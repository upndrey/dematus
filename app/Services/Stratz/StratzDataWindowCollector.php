<?php

namespace App\Services\Stratz;

use App\Models\DraftSnapshot;
use App\Models\StratzDataBucket;
use App\Services\DraftSnapshots\DraftSnapshotService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StratzDataWindowCollector
{
    /**
     * @var list<int>
     */
    private const WINDOW_WEEKS = [4, 8, 12];

    public function __construct(private readonly StratzService $stratzService) {}

    /**
     * @return Collection<int, StratzDataBucket>
     */
    public function collect(DraftSnapshot $draftSnapshot): Collection
    {
        $requestAnalysis = (array) data_get($draftSnapshot->stratz_payload, 'request.analysis', []);
        $anchorWeek = data_get($requestAnalysis, 'week');

        if (! is_numeric($anchorWeek)) {
            throw new InvalidArgumentException('Snapshot does not contain STRATZ analysis week.');
        }

        $bracketBasicId = data_get($requestAnalysis, 'bracketBasicIds');

        if (! is_string($bracketBasicId) || trim($bracketBasicId) === '') {
            throw new InvalidArgumentException('Snapshot does not contain STRATZ bracket.');
        }

        $heroIds = $this->heroIds($requestAnalysis);

        if ($heroIds === []) {
            throw new InvalidArgumentException('Snapshot does not contain STRATZ hero IDs.');
        }

        $buckets = collect();

        $payloads = $this->stratzService->collectRoshDataWindowBucketPayloads(
            (int) $anchorWeek,
            self::WINDOW_WEEKS,
            trim($bracketBasicId),
            $heroIds,
        );

        foreach ($payloads as $payload) {
            $dataType = (string) $payload['data_type'];
            $windowWeeks = (int) $payload['window_weeks'];
            $rawPayload = $payload['raw_payload'];
            $normalizedPayload = array_merge(
                $payload['normalized_payload'],
                [
                    'collection_source' => 'stratz_window_collector',
                    'active_data_window' => data_get($requestAnalysis, 'dataWindows.active'),
                    'optimized_collection' => true,
                ],
            );

            $buckets->push(StratzDataBucket::query()->updateOrCreate(
                [
                    'draft_snapshot_id' => $draftSnapshot->id,
                    'bucket_key' => $this->bucketKey(
                        $requestAnalysis,
                        $dataType,
                        $windowWeeks,
                        trim($bracketBasicId),
                        (int) $anchorWeek,
                        $heroIds,
                    ),
                ],
                [
                    'data_type' => $dataType,
                    'window_weeks' => $windowWeeks,
                    'anchor_week' => (int) $anchorWeek,
                    'week_timestamps' => (array) data_get($payload, 'normalized_payload.week_timestamps', []),
                    'bracket_basic_id' => trim($bracketBasicId),
                    'hero_ids' => $heroIds,
                    'raw_payload' => $rawPayload,
                    'normalized_payload' => $normalizedPayload,
                    'payload_hash' => $this->payloadHash($rawPayload),
                    'model_version' => DraftSnapshotService::MODEL_VERSION,
                ],
            ));
        }

        return $buckets;
    }

    /**
     * @param  array<string, mixed>  $requestAnalysis
     * @return list<int>
     */
    private function heroIds(array $requestAnalysis): array
    {
        return array_values(array_map(
            'intval',
            array_filter(
                (array) data_get($requestAnalysis, 'heroIds', []),
                static fn (mixed $heroId): bool => is_numeric($heroId),
            ),
        ));
    }

    /**
     * @param  array<string, mixed>  $requestAnalysis
     * @param  list<int>  $heroIds
     */
    private function bucketKey(
        array $requestAnalysis,
        string $dataType,
        int $windowWeeks,
        string $bracketBasicId,
        int $anchorWeek,
        array $heroIds,
    ): string {
        $heroHash = substr(hash('sha256', (string) json_encode($heroIds)), 0, 12);
        $source = (string) data_get($requestAnalysis, 'source', 'snapshot');

        return "stratz_window_collector:{$source}:{$dataType}:{$windowWeeks}w:{$bracketBasicId}:{$anchorWeek}:{$heroHash}";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
