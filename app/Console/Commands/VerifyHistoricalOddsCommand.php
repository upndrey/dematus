<?php

namespace App\Console\Commands;

use App\Models\DraftSnapshot;
use App\Services\GoogleSheets\RoshSheetService;
use App\Services\OddsApi\HistoricalOddsVerificationService;
use Illuminate\Console\Command;
use Throwable;

class VerifyHistoricalOddsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'odds:verify-historical
                            {--event-id=* : Odds API event IDs to verify. Defaults to all saved Odds API events.}
                            {--apply : Save verification metadata and swap reversed rows.}
                            {--sync-sheet : Synchronize changed bookmaker odds to the Dataset sheet. Requires --apply.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify Stake historical odds orientation against 1xbet and annotate lines without a control quote.';

    /**
     * Execute the console command.
     */
    public function handle(
        HistoricalOddsVerificationService $verificationService,
        RoshSheetService $roshSheetService,
    ): int {
        if ((bool) $this->option('sync-sheet') && ! (bool) $this->option('apply')) {
            $this->error('The --sync-sheet option requires --apply.');

            return self::FAILURE;
        }

        $snapshotsByEvent = DraftSnapshot::query()
            ->whereNotNull('book_payload')
            ->get()
            ->filter(static fn (DraftSnapshot $snapshot): bool => data_get($snapshot->book_payload, 'provider') === 'odds-api.io'
                && data_get($snapshot->book_payload, 'bookmaker') === 'Stake'
                && is_numeric(data_get($snapshot->book_payload, 'event_id')))
            ->groupBy(static fn (DraftSnapshot $snapshot): int => (int) data_get($snapshot->book_payload, 'event_id'));

        $requestedEventIds = array_values(array_filter(
            array_map('intval', (array) $this->option('event-id')),
            static fn (int $eventId): bool => $eventId > 0,
        ));

        if ($requestedEventIds !== []) {
            $snapshotsByEvent = $snapshotsByEvent->only($requestedEventIds);
        }

        if ($snapshotsByEvent->isEmpty()) {
            $this->warn('No Stake historical odds snapshots found.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $syncSheet = (bool) $this->option('sync-sheet');
        $rows = [];

        foreach ($snapshotsByEvent as $eventId => $snapshots) {
            try {
                $verification = $verificationService->verifyEvent((int) $eventId);
            } catch (Throwable $throwable) {
                $this->error("Event {$eventId}: {$throwable->getMessage()}");

                return self::FAILURE;
            }

            $orientation = (string) $verification['orientation'];
            $rows[] = [$eventId, $snapshots->count(), $orientation, $apply ? 'saved' : 'dry run'];

            if (! $apply) {
                continue;
            }

            foreach ($snapshots as $snapshot) {
                $bookPayload = (array) $snapshot->book_payload;
                $wasAlreadyReversed = data_get($bookPayload, 'verification.orientation') === 'reversed';
                $bookPayload['verification'] = [
                    ...$verification,
                    'verified_at' => now()->toIso8601String(),
                ];

                $attributes = ['book_payload' => $bookPayload];

                if ($orientation === 'reversed' && ! $wasAlreadyReversed) {
                    $attributes['bookmaker_odds_radiant'] = $snapshot->bookmaker_odds_dire;
                    $attributes['bookmaker_odds_dire'] = $snapshot->bookmaker_odds_radiant;
                }

                $snapshot->update($attributes);

                if ($syncSheet && in_array($orientation, ['reversed', 'unverified'], true)) {
                    $roshSheetService->syncSnapshotDataset([$snapshot->fresh()], true);
                }
            }
        }

        $this->table(['Event ID', 'Snapshots', 'Orientation', 'Result'], $rows);

        if (! $apply) {
            $this->comment('Dry run only. Re-run with --apply to save verification and swap confirmed reversed rows.');
        }

        return self::SUCCESS;
    }
}
