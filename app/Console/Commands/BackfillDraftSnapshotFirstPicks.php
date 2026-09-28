<?php

namespace App\Console\Commands;

use App\Models\DraftSnapshot;
use App\Services\DraftSnapshots\DraftSnapshotService;
use App\Services\GoogleSheets\RoshSheetService;
use Illuminate\Console\Command;

class BackfillDraftSnapshotFirstPicks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snapshots:backfill-first-picks
                            {--sync-dataset : Sync updated snapshots to the Dataset sheet}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store first-pick sides from saved STRATZ draft events.';

    /**
     * Execute the console command.
     */
    public function handle(DraftSnapshotService $draftSnapshotService, RoshSheetService $roshSheetService): int
    {
        $updated = 0;
        $exported = 0;
        $syncDataset = (bool) $this->option('sync-dataset');

        if ($syncDataset && ! $roshSheetService->isConfigured()) {
            $this->error('Google Sheets dataset sync is not configured.');

            return self::FAILURE;
        }

        DraftSnapshot::query()
            ->whereNotNull('match_id')
            ->orderBy('id')
            ->chunkById(100, function ($snapshots) use ($draftSnapshotService, $roshSheetService, $syncDataset, &$updated, &$exported): void {
                $changedSnapshots = $snapshots->filter(
                    fn (DraftSnapshot $snapshot): bool => $draftSnapshotService->backfillFirstPickSide($snapshot),
                );

                $updated += $changedSnapshots->count();

                if ($syncDataset) {
                    $exported += $roshSheetService->syncSnapshotFirstPickSides(
                        $snapshots->filter(
                            fn (DraftSnapshot $snapshot): bool => $snapshot->first_pick_side !== null,
                        ),
                    );
                }
            });

        $this->info("Stored first-pick sides for {$updated} draft snapshots.");

        if ($syncDataset) {
            $this->info("Synced {$exported} Dataset rows.");
        }

        return self::SUCCESS;
    }
}
