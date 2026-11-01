<?php

namespace App\Services\OddsApi;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class HistoricalOddsVerificationService
{
    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function verifyEvent(int $eventId): array
    {
        $response = Http::baseUrl(rtrim((string) config('services.odds_api.endpoint'), '/'))
            ->timeout((int) config('services.odds_api.timeout', 20))
            ->get('/historical/odds', [
                'apiKey' => config('services.odds_api.key'),
                'eventId' => $eventId,
                'bookmakers' => '1xbet,Stake',
            ])
            ->throw();

        $event = $response->json();
        $controlOdds = $this->marketOdds((array) data_get($event, 'bookmakers.1xbet', []), 'ML');
        $stakeOdds = $this->marketOdds((array) data_get($event, 'bookmakers.Stake', []), 'ML');

        return [
            'event_id' => $eventId,
            'event_home' => data_get($event, 'home'),
            'event_away' => data_get($event, 'away'),
            'orientation' => $this->orientation($controlOdds, $stakeOdds),
            'control_bookie' => '1xbet',
            'control_market' => 'ML',
            'control_odds' => $controlOdds,
            'stake_control_market' => 'ML',
            'stake_control_odds' => $stakeOdds,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $markets
     * @return array{home: float, away: float}|null
     */
    private function marketOdds(array $markets, string $marketName): ?array
    {
        foreach ($markets as $market) {
            if (data_get($market, 'name') !== $marketName) {
                continue;
            }

            $odds = data_get($market, 'odds.0');

            if (! is_array($odds) || ! is_numeric($odds['home'] ?? null) || ! is_numeric($odds['away'] ?? null)) {
                return null;
            }

            return [
                'home' => (float) $odds['home'],
                'away' => (float) $odds['away'],
            ];
        }

        return null;
    }

    /**
     * @param  array{home: float, away: float}|null  $controlOdds
     * @param  array{home: float, away: float}|null  $stakeOdds
     */
    private function orientation(?array $controlOdds, ?array $stakeOdds): string
    {
        if ($stakeOdds !== null && $stakeOdds['home'] === $stakeOdds['away']) {
            return 'equal';
        }

        if ($controlOdds === null || $stakeOdds === null || $controlOdds['home'] === $controlOdds['away']) {
            return 'unverified';
        }

        $controlFavorite = $controlOdds['home'] < $controlOdds['away'] ? 'home' : 'away';
        $stakeFavorite = $stakeOdds['home'] < $stakeOdds['away'] ? 'home' : 'away';

        return $controlFavorite === $stakeFavorite ? 'direct' : 'reversed';
    }
}
