<?php

namespace App\Console\Commands;

use App\Models\DraftSnapshot;
use App\Services\DraftSnapshots\DraftSnapshotFeatureAggregator;
use Illuminate\Console\Command;

class BackfillDraftSnapshotMlFeatures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snapshots:backfill-ml-features
                            {--chunk=1 : Number of snapshots to process per database query}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store the 4/8/12-week ML feature payload for historical draft snapshots.';

    public function __construct(private readonly DraftSnapshotFeatureAggregator $featureAggregator)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $updated = 0;

        DraftSnapshot::query()
            ->whereNull('ml_feature_payload')
            ->with('stratzDataBuckets')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($snapshots) use (&$updated): void {
                foreach ($snapshots as $snapshot) {
                    $snapshot->update([
                        'ml_feature_payload' => $this->featureAggregator->aggregate($snapshot),
                    ]);

                    $updated++;
                }
            });

        $this->info("Stored ML features for {$updated} draft snapshots.");

        return self::SUCCESS;
    }
}
