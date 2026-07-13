<?php

namespace App\Console\Commands;

use App\Models\DraftSnapshot;
use App\Services\Stratz\StratzDataWindowCollector;
use Illuminate\Console\Command;
use Throwable;

class DematusCollectStratzWindowsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dematus:collect-stratz-windows {snapshot_id? : Draft snapshot ID. Defaults to the latest snapshot.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collect 4w, 8w, and 12w STRATZ data buckets for a draft snapshot.';

    /**
     * Execute the console command.
     */
    public function handle(StratzDataWindowCollector $collector): int
    {
        try {
            $snapshot = $this->snapshot();

            if (! $snapshot instanceof DraftSnapshot) {
                $this->error('No draft snapshots found.');

                return self::FAILURE;
            }

            $buckets = $collector->collect($snapshot);

            $this->info("Collected {$buckets->count()} STRATZ data window buckets for snapshot #{$snapshot->id}.");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('STRATZ window collection failed: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function snapshot(): ?DraftSnapshot
    {
        $snapshotId = $this->argument('snapshot_id');

        if (is_numeric($snapshotId)) {
            return DraftSnapshot::query()->find((int) $snapshotId);
        }

        return DraftSnapshot::query()->latest('id')->first();
    }
}
