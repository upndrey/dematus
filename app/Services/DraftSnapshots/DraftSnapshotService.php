<?php

namespace App\Services\DraftSnapshots;

use App\Models\DraftSnapshot;
use App\Models\StratzDataBucket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class DraftSnapshotService
{
    public const MODEL_VERSION = 'dematus-rosh-v1';

    /**
     * @param  array<string, mixed>  $draftPayload
     * @param  array<string, mixed>  $rosh
     * @param  array<string, mixed>  $metadata
     */
    public function store(string $source, array $draftPayload, array $rosh, array $metadata = []): DraftSnapshot
    {
        $formatted = (array) data_get($rosh, 'formatted', []);
        $sheetPayload = data_get($rosh, 'google_sheets');

        $draftSnapshot = DraftSnapshot::query()->create([
            'source' => $source,
            'captured_at' => $this->capturedAt($metadata, $formatted),
            'match_id' => $this->matchId($formatted, $metadata),
            'dltv_url' => $this->nullableString(data_get($metadata, 'dltv_url')),
            'tournament' => $this->nullableString(data_get($metadata, 'tournament')),
            'radiant_team' => $this->nullableString(data_get($formatted, 'radiant_team')),
            'dire_team' => $this->nullableString(data_get($formatted, 'dire_team')),
            'radiant_heroes' => $this->heroIds($draftPayload, $rosh, true),
            'dire_heroes' => $this->heroIds($draftPayload, $rosh, false),
            'bookmaker_odds_radiant' => $this->numericOrNull(data_get($metadata, 'bookmaker_odds_radiant')),
            'bookmaker_odds_dire' => $this->numericOrNull(data_get($metadata, 'bookmaker_odds_dire')),
            'draft_payload' => $draftPayload,
            'stratz_payload' => $this->stratzPayload($rosh),
            'feature_payload' => $this->featurePayload($rosh),
            'sheet_payload' => is_array($sheetPayload) ? $sheetPayload : null,
            'book_payload' => $this->bookPayload($metadata),
            'result_payload' => $this->resultPayload($formatted),
            'model_version' => self::MODEL_VERSION,
        ]);

        $this->storeStratzDataBuckets($draftSnapshot, $rosh);

        return $draftSnapshot;
    }

    /**
     * @param  array<string, mixed>  $rosh
     */
    public function withoutInternalPayload(array $rosh): array
    {
        unset($rosh['snapshot_stratz_payload']);

        return $rosh;
    }

    public function backfillStratzDataBuckets(DraftSnapshot $draftSnapshot): void
    {
        $draftSnapshot->stratzDataBuckets()->delete();

        $this->storeStratzDataBuckets($draftSnapshot, [
            'snapshot_stratz_payload' => $draftSnapshot->stratz_payload ?? [],
            'raw' => [
                'analysis_summary' => data_get($draftSnapshot->feature_payload, 'analysis_summary', []),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $formatted
     */
    private function capturedAt(array $metadata, array $formatted): CarbonInterface
    {
        $capturedAt = data_get($metadata, 'captured_at');

        if (is_string($capturedAt) && trim($capturedAt) !== '') {
            return Carbon::parse($capturedAt);
        }

        $dateTime = data_get($formatted, 'date_time');

        if (is_numeric($dateTime)) {
            return Carbon::createFromTimestamp((int) $dateTime);
        }

        return now();
    }

    /**
     * @param  array<string, mixed>  $formatted
     * @param  array<string, mixed>  $metadata
     */
    private function matchId(array $formatted, array $metadata): ?int
    {
        $matchId = data_get($metadata, 'match_id') ?? data_get($formatted, 'match_id');

        return is_numeric($matchId) ? (int) $matchId : null;
    }

    /**
     * @param  array<string, mixed>  $draftPayload
     * @param  array<string, mixed>  $rosh
     * @return list<int>|null
     */
    private function heroIds(array $draftPayload, array $rosh, bool $isRadiant): ?array
    {
        $payloadKey = $isRadiant ? 'radiant_heroes' : 'dire_heroes';
        $payloadHeroes = data_get($draftPayload, $payloadKey);

        if (is_array($payloadHeroes) && $payloadHeroes !== []) {
            return array_values(array_map('intval', $payloadHeroes));
        }

        $pickBans = (array) data_get($rosh, 'raw.match.pickBans', []);
        $heroes = [];

        foreach ($pickBans as $pickBan) {
            if (! is_array($pickBan) || ! (bool) data_get($pickBan, 'isPick')) {
                continue;
            }

            if ((bool) data_get($pickBan, 'isRadiant') !== $isRadiant) {
                continue;
            }

            $heroId = data_get($pickBan, 'heroId');

            if (is_numeric($heroId)) {
                $heroes[] = (int) $heroId;
            }
        }

        return $heroes === [] ? null : $heroes;
    }

    /**
     * @param  array<string, mixed>  $rosh
     * @return array<string, mixed>
     */
    private function stratzPayload(array $rosh): array
    {
        $payload = data_get($rosh, 'snapshot_stratz_payload');

        if (is_array($payload)) {
            return $payload;
        }

        return [
            'request' => data_get($rosh, 'request'),
            'raw' => data_get($rosh, 'raw'),
        ];
    }

    /**
     * @param  array<string, mixed>  $rosh
     * @return array<string, mixed>
     */
    private function featurePayload(array $rosh): array
    {
        return [
            'formatted' => data_get($rosh, 'formatted'),
            'minute_table' => data_get($rosh, 'minute_table'),
            'analysis_summary' => data_get($rosh, 'raw.analysis_summary'),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function bookPayload(array $metadata): ?array
    {
        $radiantOdds = $this->numericOrNull(data_get($metadata, 'bookmaker_odds_radiant'));
        $direOdds = $this->numericOrNull(data_get($metadata, 'bookmaker_odds_dire'));

        if ($radiantOdds === null && $direOdds === null) {
            return null;
        }

        return [
            'radiant_odds' => $radiantOdds,
            'dire_odds' => $direOdds,
        ];
    }

    /**
     * @param  array<string, mixed>  $formatted
     * @return array<string, mixed>|null
     */
    private function resultPayload(array $formatted): ?array
    {
        $winner = data_get($formatted, 'winner');

        if (! is_string($winner) || $winner === '') {
            return null;
        }

        return [
            'winner' => $winner,
        ];
    }

    /**
     * @param  array<string, mixed>  $rosh
     */
    private function storeStratzDataBuckets(DraftSnapshot $draftSnapshot, array $rosh): void
    {
        $requestAnalysis = (array) data_get($rosh, 'snapshot_stratz_payload.request.analysis', []);
        $analysis = (array) data_get($rosh, 'snapshot_stratz_payload.analysis', []);
        $analysisSummary = (array) data_get($rosh, 'raw.analysis_summary', []);
        $anchorWeek = data_get($requestAnalysis, 'week');

        if (! is_numeric($anchorWeek)) {
            return;
        }

        $bucketDefinitions = [
            [
                'data_type' => 'heroes_meta_positions',
                'window_weeks' => 1,
                'raw_key' => 'heroes_meta_positions',
            ],
            [
                'data_type' => 'hero_stats_by_time_bracket',
                'window_weeks' => 1,
                'raw_key' => 'hero_stats_by_time_bracket',
            ],
            [
                'data_type' => 'synergy',
                'window_weeks' => 4,
                'raw_key' => 'synergy',
            ],
        ];

        foreach ($bucketDefinitions as $definition) {
            $rawPayload = data_get($analysis, $definition['raw_key']);

            if (! is_array($rawPayload)) {
                continue;
            }

            $windowWeeks = (int) $definition['window_weeks'];
            $weekTimestamps = $this->bucketWeekTimestamps($requestAnalysis, (int) $anchorWeek, $windowWeeks);
            $normalizedPayload = [
                'summary' => data_get($analysisSummary, $definition['raw_key']),
                'active_data_window' => data_get($analysisSummary, 'stratz_data_windows.active'),
                'target_data_windows' => data_get($analysisSummary, 'stratz_data_windows.targets'),
                'operation' => $this->bucketOperation($requestAnalysis, (string) $definition['raw_key']),
            ];

            StratzDataBucket::query()->create([
                'draft_snapshot_id' => $draftSnapshot->id,
                'bucket_key' => $this->bucketKey($requestAnalysis, (string) $definition['data_type'], $windowWeeks),
                'data_type' => $definition['data_type'],
                'window_weeks' => $windowWeeks,
                'anchor_week' => (int) $anchorWeek,
                'week_timestamps' => $weekTimestamps,
                'bracket_basic_id' => $this->nullableString(data_get($requestAnalysis, 'bracketBasicIds')),
                'hero_ids' => $this->heroIdsFromRequestAnalysis($requestAnalysis),
                'raw_payload' => $rawPayload,
                'normalized_payload' => $normalizedPayload,
                'payload_hash' => $this->payloadHash($rawPayload),
                'model_version' => self::MODEL_VERSION,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $requestAnalysis
     * @return list<int>
     */
    private function bucketWeekTimestamps(array $requestAnalysis, int $anchorWeek, int $windowWeeks): array
    {
        $targetWeeks = data_get($requestAnalysis, 'dataWindows.targets.'.$windowWeeks.'w.week_timestamps');

        if (is_array($targetWeeks) && $targetWeeks !== []) {
            return array_values(array_map('intval', $targetWeeks));
        }

        return array_map(
            static fn (int $index): int => $anchorWeek - (604800 * $index),
            range(0, $windowWeeks - 1),
        );
    }

    /**
     * @param  array<string, mixed>  $requestAnalysis
     * @return list<int>
     */
    private function heroIdsFromRequestAnalysis(array $requestAnalysis): array
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
     * @return array<string, mixed>|null
     */
    private function bucketOperation(array $requestAnalysis, string $rawKey): ?array
    {
        foreach ((array) data_get($requestAnalysis, 'operations', []) as $operation) {
            if (! is_array($operation) || data_get($operation, 'key') !== $rawKey) {
                continue;
            }

            return $operation;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $requestAnalysis
     */
    private function bucketKey(array $requestAnalysis, string $dataType, int $windowWeeks): string
    {
        $bracketBasicId = (string) data_get($requestAnalysis, 'bracketBasicIds', 'unknown');
        $anchorWeek = (string) data_get($requestAnalysis, 'week', 'unknown');
        $heroHash = substr(hash('sha256', json_encode($this->heroIdsFromRequestAnalysis($requestAnalysis))), 0, 12);

        return "legacy_mixed:{$dataType}:{$windowWeeks}w:{$bracketBasicId}:{$anchorWeek}:{$heroHash}";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
