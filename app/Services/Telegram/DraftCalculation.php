<?php

namespace App\Services\Telegram;

use App\Models\DraftSnapshot;
use App\Models\TelegramDraft;
use App\Services\DraftSnapshots\DraftSnapshotService;
use App\Services\GoogleSheets\RoshSheetService;
use App\Services\Stratz\StratzDataWindowCollector;
use App\Services\Stratz\StratzService;
use RuntimeException;

class DraftCalculation
{
    public function __construct(private StratzService $stratz, private DraftSnapshotService $snapshots, private StratzDataWindowCollector $collector, private RoshSheetService $sheets, private BetBoomDraftSource $source) {}

    public function run(TelegramDraft $draft): void
    {
        if (! $this->sheets->isConfigured()) {
            throw new RuntimeException('Google Sheets is not configured.');
        }
        $payload = $this->source->calculationPayload($draft->players);
        if (! $draft->calculation) {
            $draft->update(['calculation' => $this->stratz->getRoshFromHeroes($payload)]);
        }
        if (! $draft->draft_snapshot_id) {
            $draft->getConnection()->transaction(function () use ($draft, $payload): void {
                $snapshot = $this->snapshots->store('betboom', $payload + ['betboom_match_id' => $draft->match_id, 'map_number' => $draft->map_number, 'players' => $draft->players, 'confirmed_by' => $draft->confirmed_by], $draft->calculation, ['captured_at' => $draft->confirmed_at]);
                $draft->update(['draft_snapshot_id' => $snapshot->id]);
            });
        }
        $snapshot = DraftSnapshot::query()->findOrFail($draft->draft_snapshot_id);
        if (! $draft->sheet_result) {
            $result = $this->sheets->syncBetBoomOdds($draft->match_id, $draft->map_number, $draft->calculation['formatted']);
            $draft->update(['sheet_result' => $result]);
        }
        $snapshot->update(['sheet_payload' => $draft->sheet_result]);
        $this->collector->collect($snapshot);
        $snapshot = $this->snapshots->refreshMlFeaturePayload($snapshot);
        $this->sheets->syncSnapshotDataset([$snapshot->fresh('stratzDataBuckets')]);
        $draft->update(['status' => 'done', 'error' => null, 'message_hash' => null]);
    }
}
