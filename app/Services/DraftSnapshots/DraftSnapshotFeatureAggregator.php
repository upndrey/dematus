<?php

namespace App\Services\DraftSnapshots;

use App\Models\DraftSnapshot;

class DraftSnapshotFeatureAggregator
{
    private const SYNERGY_RELIABILITY_MATCH_COUNT = 100;

    /**
     * @var list<int>
     */
    private const WINDOWS = [4, 8, 12];

    /**
     * @var list<int>
     */
    private const TEMPO_MINUTES = [20, 60];

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach (self::WINDOWS as $windowWeeks) {
            $prefix = "{$windowWeeks}w";
            $headers = [
                ...$headers,
                "radiant_{$prefix}_hero_winrate_avg",
                "dire_{$prefix}_hero_winrate_avg",
                "diff_{$prefix}_hero_winrate_avg",
                "radiant_{$prefix}_hero_match_count",
                "dire_{$prefix}_hero_match_count",
                "radiant_{$prefix}_synergy",
                "dire_{$prefix}_synergy",
                "diff_{$prefix}_synergy",
                "{$prefix}_matchup_advantage",
            ];

            foreach (self::TEMPO_MINUTES as $minute) {
                $headers = [
                    ...$headers,
                    "radiant_{$prefix}_tempo{$minute}_winrate",
                    "dire_{$prefix}_tempo{$minute}_winrate",
                    "diff_{$prefix}_tempo{$minute}_winrate",
                ];
            }
        }

        return $headers;
    }

    /**
     * @return array<string, ?float>
     */
    public function aggregate(DraftSnapshot $snapshot): array
    {
        $features = array_fill_keys($this->headers(), null);
        $radiantPicks = $this->picks($snapshot->radiant_heroes ?? []);
        $direPicks = $this->picks($snapshot->dire_heroes ?? []);

        if ($radiantPicks === [] || $direPicks === []) {
            return $features;
        }

        foreach (self::WINDOWS as $windowWeeks) {
            $prefix = "{$windowWeeks}w";
            $heroBucket = $this->bucket($snapshot, 'heroes_meta_positions', $windowWeeks);
            $tempoBucket = $this->bucket($snapshot, 'hero_stats_by_time_bracket', $windowWeeks);
            $synergyBucket = $this->bucket($snapshot, 'synergy', $windowWeeks);

            if ($heroBucket !== null) {
                $heroStats = $this->heroWinrateFeatures($heroBucket->raw_payload ?? [], $radiantPicks, $direPicks);
                $features["radiant_{$prefix}_hero_winrate_avg"] = $heroStats['radiant_winrate_avg'];
                $features["dire_{$prefix}_hero_winrate_avg"] = $heroStats['dire_winrate_avg'];
                $features["diff_{$prefix}_hero_winrate_avg"] = $this->diff($heroStats['radiant_winrate_avg'], $heroStats['dire_winrate_avg']);
                $features["radiant_{$prefix}_hero_match_count"] = $heroStats['radiant_match_count'];
                $features["dire_{$prefix}_hero_match_count"] = $heroStats['dire_match_count'];
            }

            if ($tempoBucket !== null) {
                foreach (self::TEMPO_MINUTES as $minute) {
                    $tempoStats = $this->tempoWinrateFeatures($tempoBucket->raw_payload ?? [], $radiantPicks, $direPicks, $minute);
                    $features["radiant_{$prefix}_tempo{$minute}_winrate"] = $tempoStats['radiant_winrate_avg'];
                    $features["dire_{$prefix}_tempo{$minute}_winrate"] = $tempoStats['dire_winrate_avg'];
                    $features["diff_{$prefix}_tempo{$minute}_winrate"] = $this->diff($tempoStats['radiant_winrate_avg'], $tempoStats['dire_winrate_avg']);
                }
            }

            if ($synergyBucket !== null) {
                $synergyStats = $this->synergyFeatures($synergyBucket->raw_payload ?? [], $radiantPicks, $direPicks);
                $features["radiant_{$prefix}_synergy"] = $synergyStats['radiant_synergy'];
                $features["dire_{$prefix}_synergy"] = $synergyStats['dire_synergy'];
                $features["diff_{$prefix}_synergy"] = $this->diff($synergyStats['radiant_synergy'], $synergyStats['dire_synergy']);
                $features["{$prefix}_matchup_advantage"] = $synergyStats['matchup_advantage'];
            }
        }

        return $features;
    }

    /**
     * @param  list<int>  $heroIds
     * @return list<array{heroId:int, positionId:int}>
     */
    private function picks(array $heroIds): array
    {
        $picks = [];

        foreach (array_values($heroIds) as $index => $heroId) {
            if (! is_numeric($heroId)) {
                continue;
            }

            $picks[] = [
                'heroId' => (int) $heroId,
                'positionId' => $index + 1,
            ];
        }

        return $picks;
    }

    private function bucket(DraftSnapshot $snapshot, string $dataType, int $windowWeeks): mixed
    {
        if ($snapshot->relationLoaded('stratzDataBuckets')) {
            return $snapshot->stratzDataBuckets
                ->first(
                    fn ($bucket): bool => $bucket->data_type === $dataType
                        && $bucket->window_weeks === $windowWeeks
                        && str_starts_with($bucket->bucket_key, 'stratz_window_collector:'),
                );
        }

        return $snapshot->stratzDataBuckets()
            ->where('data_type', $dataType)
            ->where('window_weeks', $windowWeeks)
            ->where('bucket_key', 'like', 'stratz_window_collector:%')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  list<array{heroId:int, positionId:int}>  $radiantPicks
     * @param  list<array{heroId:int, positionId:int}>  $direPicks
     * @return array{radiant_winrate_avg:?float, dire_winrate_avg:?float, radiant_match_count:?float, dire_match_count:?float}
     */
    private function heroWinrateFeatures(array $rawPayload, array $radiantPicks, array $direPicks): array
    {
        $lookup = [];

        foreach ((array) data_get($rawPayload, 'weeks', []) as $weekPayload) {
            foreach (range(1, 5) as $positionId) {
                foreach ((array) data_get($weekPayload, 'heroesPos_'.$positionId, []) as $row) {
                    $this->mergeWinrateEntry($lookup, $positionId, $row);
                }
            }
        }

        return [
            ...$this->teamWinrateAverages($lookup, $radiantPicks, 'radiant'),
            ...$this->teamWinrateAverages($lookup, $direPicks, 'dire'),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  list<array{heroId:int, positionId:int}>  $radiantPicks
     * @param  list<array{heroId:int, positionId:int}>  $direPicks
     * @return array{radiant_winrate_avg:?float, dire_winrate_avg:?float}
     */
    private function tempoWinrateFeatures(array $rawPayload, array $radiantPicks, array $direPicks, int $minute): array
    {
        $lookup = [];

        foreach ((array) data_get($rawPayload, 'weeks', []) as $weekPayload) {
            foreach (range(1, 5) as $positionId) {
                foreach ((array) data_get($weekPayload, 'heroStatsByTime_'.$positionId, []) as $row) {
                    if ((int) data_get($row, 'time', -1) !== $minute) {
                        continue;
                    }

                    $this->mergeWinrateEntry($lookup, $positionId, $row);
                }
            }
        }

        return [
            'radiant_winrate_avg' => $this->teamWinrateAverage($lookup, $radiantPicks),
            'dire_winrate_avg' => $this->teamWinrateAverage($lookup, $direPicks),
        ];
    }

    /**
     * @param  array<int, array<int, array{win_count:int, match_count:int}>>  $lookup
     * @return array<string, ?float>
     */
    private function teamWinrateAverages(array $lookup, array $picks, string $prefix): array
    {
        return [
            "{$prefix}_winrate_avg" => $this->teamWinrateAverage($lookup, $picks),
            "{$prefix}_match_count" => $this->teamMatchCount($lookup, $picks),
        ];
    }

    /**
     * @param  array<int, array<int, array{win_count:int, match_count:int}>>  $lookup
     * @param  list<array{heroId:int, positionId:int}>  $picks
     */
    private function teamWinrateAverage(array $lookup, array $picks): ?float
    {
        $winRates = [];

        foreach ($picks as $pick) {
            $entry = data_get($lookup, $pick['positionId'].'.'.$pick['heroId']);

            if (! is_array($entry) || (int) $entry['match_count'] <= 0) {
                continue;
            }

            $winRates[] = (((int) $entry['win_count']) / ((int) $entry['match_count'])) * 100;
        }

        return $this->average($winRates);
    }

    /**
     * @param  array<int, array<int, array{win_count:int, match_count:int}>>  $lookup
     * @param  list<array{heroId:int, positionId:int}>  $picks
     */
    private function teamMatchCount(array $lookup, array $picks): ?float
    {
        $matchCount = 0;

        foreach ($picks as $pick) {
            $matchCount += (int) data_get($lookup, $pick['positionId'].'.'.$pick['heroId'].'.match_count', 0);
        }

        return $matchCount > 0 ? (float) $matchCount : null;
    }

    /**
     * @param  array<int, array<int, array{win_count:int, match_count:int}>>  $lookup
     * @param  array<string, mixed>  $row
     */
    private function mergeWinrateEntry(array &$lookup, int $positionId, array $row): void
    {
        $heroId = data_get($row, 'heroId');
        $matchCount = data_get($row, 'matchCount');
        $winCount = data_get($row, 'winCount');

        if (! is_numeric($heroId) || ! is_numeric($matchCount) || ! is_numeric($winCount) || (int) $matchCount <= 0) {
            return;
        }

        $heroId = (int) $heroId;
        $lookup[$positionId][$heroId] ??= [
            'win_count' => 0,
            'match_count' => 0,
        ];
        $lookup[$positionId][$heroId]['win_count'] += (int) $winCount;
        $lookup[$positionId][$heroId]['match_count'] += (int) $matchCount;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  list<array{heroId:int, positionId:int}>  $radiantPicks
     * @param  list<array{heroId:int, positionId:int}>  $direPicks
     * @return array{radiant_synergy:?float, dire_synergy:?float, matchup_advantage:?float}
     */
    private function synergyFeatures(array $rawPayload, array $radiantPicks, array $direPicks): array
    {
        $lookup = $this->synergyLookup($rawPayload);

        return [
            'radiant_synergy' => $this->teamPairSynergy($radiantPicks, $lookup['with']),
            'dire_synergy' => $this->teamPairSynergy($direPicks, $lookup['with']),
            'matchup_advantage' => $this->matchupAdvantage($radiantPicks, $direPicks, $lookup['vs']),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{with:array<int, array<int, array{match_count:int, synergy:float}>>, vs:array<int, array<int, array{match_count:int, synergy:float}>>}
     */
    private function synergyLookup(array $rawPayload): array
    {
        $lookup = [
            'with' => [],
            'vs' => [],
        ];

        foreach ((array) data_get($rawPayload, 'segments', []) as $segmentPayload) {
            foreach (range(1, 4) as $weekIndex) {
                foreach ((array) data_get($segmentPayload, 'matchUp_Prev_Week_'.$weekIndex, []) as $row) {
                    $heroId = data_get($row, 'heroId');

                    if (! is_numeric($heroId)) {
                        continue;
                    }

                    foreach (['with', 'vs'] as $type) {
                        foreach ((array) data_get($row, $type, []) as $entry) {
                            $this->mergeSynergyEntry($lookup[$type], (int) $heroId, $entry);
                        }
                    }
                }
            }
        }

        foreach (['with', 'vs'] as $type) {
            foreach ($lookup[$type] as $heroId => $entries) {
                foreach ($entries as $heroId2 => $entry) {
                    $confidence = min(1.0, max(0.0, $entry['match_count'] / self::SYNERGY_RELIABILITY_MATCH_COUNT));
                    $lookup[$type][$heroId][$heroId2]['synergy'] = round($entry['synergy'] * $confidence, 2);
                }
            }
        }

        return $lookup;
    }

    /**
     * @param  array<int, array<int, array{match_count:int, synergy:float}>>  $lookup
     * @param  array<string, mixed>  $entry
     */
    private function mergeSynergyEntry(array &$lookup, int $heroId, array $entry): void
    {
        $heroId2 = data_get($entry, 'heroId2');
        $matchCount = data_get($entry, 'matchCount');
        $synergy = data_get($entry, 'synergy');

        if (! is_numeric($heroId2) || ! is_numeric($matchCount) || ! is_numeric($synergy) || (int) $matchCount <= 0) {
            return;
        }

        $heroId2 = (int) $heroId2;
        $matchCount = (int) $matchCount;

        $lookup[$heroId][$heroId2] ??= [
            'match_count' => 0,
            'synergy' => 0.0,
        ];

        $current = $lookup[$heroId][$heroId2];
        $totalMatchCount = $current['match_count'] + $matchCount;

        $lookup[$heroId][$heroId2] = [
            'match_count' => $totalMatchCount,
            'synergy' => ($current['synergy'] * ($current['match_count'] / $totalMatchCount))
                + ((float) $synergy * ($matchCount / $totalMatchCount)),
        ];
    }

    /**
     * @param  list<array{heroId:int, positionId:int}>  $picks
     * @param  array<int, array<int, array{match_count:int, synergy:float}>>  $lookup
     */
    private function teamPairSynergy(array $picks, array $lookup): ?float
    {
        $synergy = 0.0;
        $pairCount = 0;
        $pickCount = count($picks);

        for ($leftIndex = 0; $leftIndex < $pickCount; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $pickCount; $rightIndex++) {
                $synergy += $this->pairSynergy($picks[$leftIndex]['heroId'], $picks[$rightIndex]['heroId'], $lookup);
                $pairCount++;
            }
        }

        return $pairCount > 0 ? $synergy : null;
    }

    /**
     * @param  list<array{heroId:int, positionId:int}>  $radiantPicks
     * @param  list<array{heroId:int, positionId:int}>  $direPicks
     * @param  array<int, array<int, array{match_count:int, synergy:float}>>  $lookup
     */
    private function matchupAdvantage(array $radiantPicks, array $direPicks, array $lookup): ?float
    {
        $advantage = 0.0;
        $pairCount = 0;

        foreach ($radiantPicks as $radiantPick) {
            foreach ($direPicks as $direPick) {
                $radiantSynergy = data_get($lookup, $radiantPick['heroId'].'.'.$direPick['heroId'].'.synergy');
                $direSynergy = data_get($lookup, $direPick['heroId'].'.'.$radiantPick['heroId'].'.synergy');

                if (is_numeric($radiantSynergy) && is_numeric($direSynergy)) {
                    $advantage += ((float) $radiantSynergy - (float) $direSynergy) / 2;
                    $pairCount++;
                } elseif (is_numeric($radiantSynergy)) {
                    $advantage += (float) $radiantSynergy;
                    $pairCount++;
                } elseif (is_numeric($direSynergy)) {
                    $advantage -= (float) $direSynergy;
                    $pairCount++;
                }
            }
        }

        return $pairCount > 0 ? $advantage : null;
    }

    /**
     * @param  array<int, array<int, array{match_count:int, synergy:float}>>  $lookup
     */
    private function pairSynergy(int $heroId, int $heroId2, array $lookup): float
    {
        $leftSynergy = data_get($lookup, $heroId.'.'.$heroId2.'.synergy');
        $rightSynergy = data_get($lookup, $heroId2.'.'.$heroId.'.synergy');

        if (is_numeric($leftSynergy) && is_numeric($rightSynergy)) {
            return ((float) $leftSynergy + (float) $rightSynergy) / 2;
        }

        if (is_numeric($leftSynergy)) {
            return (float) $leftSynergy;
        }

        return is_numeric($rightSynergy) ? (float) $rightSynergy : 0.0;
    }

    /**
     * @param  list<float|int>  $values
     */
    private function average(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    private function diff(?float $radiant, ?float $dire): ?float
    {
        return $radiant !== null && $dire !== null ? $radiant - $dire : null;
    }
}
