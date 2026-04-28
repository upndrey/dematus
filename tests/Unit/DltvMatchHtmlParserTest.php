<?php

namespace Tests\Unit;

use App\Services\Dltv\DltvMatchHtmlParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DltvMatchHtmlParserTest extends TestCase
{
    public function test_it_parses_series_item_map_picks_sorted_by_role(): void
    {
        $html = <<<'HTML'
<script>
let series_item = {
    "first_team": {"id": 10, "title": "Team A"},
    "second_team": {"id": 20, "title": "Team B"},
    "maps": [{
        "radiant_team_id": 20,
        "dire_team_id": 10,
        "radiant_picks": [
            {"hero_id": 79, "role": 4},
            {"hero": {"steam_id": 114}, "role": 1},
            {"steam_id": 25, "position": 2},
            {"hero_id": 112, "role": 5},
            {"hero_id": 23, "role": 3}
        ],
        "dire_picks": [
            {"hero_id": 83, "role": 4},
            {"hero_id": 37, "role": 5},
            {"hero_id": 70, "role": 1},
            {"hero_id": 39, "role": 3},
            {"hero_id": 59, "role": 2}
        ]
    }]
};
</script>
HTML;

        $payload = (new DltvMatchHtmlParser)->parse($html);

        $this->assertSame('Team B', $payload['radiant_team']);
        $this->assertSame('Team A', $payload['dire_team']);
        $this->assertSame([114, 25, 23, 79, 112], $payload['radiant_heroes']);
        $this->assertSame([70, 59, 39, 83, 37], $payload['dire_heroes']);
    }

    public function test_it_parses_live_payload_by_radiant_side(): void
    {
        $html = <<<'HTML'
<script>
window.livePayload = {
    "db": {
        "first_team": {
            "id": 1,
            "title": "First Team",
            "is_radiant": false,
            "picks": [
                {"hero_id": 70, "role": 1},
                {"hero_id": 59, "role": 2},
                {"hero_id": 39, "role": 3},
                {"hero_id": 83, "role": 4},
                {"hero_id": 37, "role": 5}
            ]
        },
        "second_team": {
            "id": 2,
            "title": "Second Team",
            "is_radiant": true,
            "picks": [
                {"hero_id": 114, "role": 1},
                {"hero_id": 25, "role": 2},
                {"hero_id": 23, "role": 3},
                {"hero_id": 79, "role": 4},
                {"hero_id": 112, "role": 5}
            ]
        }
    }
};
</script>
HTML;

        $payload = (new DltvMatchHtmlParser)->parse($html);

        $this->assertSame('Second Team', $payload['radiant_team']);
        $this->assertSame('First Team', $payload['dire_team']);
        $this->assertSame([114, 25, 23, 79, 112], $payload['radiant_heroes']);
        $this->assertSame([70, 59, 39, 83, 37], $payload['dire_heroes']);
    }

    public function test_it_parses_rendered_picks_sorted_by_series_player_roles(): void
    {
        $html = <<<'HTML'
<script>
let series_item = {
    "first_team": {"id": 7784, "title": "Team Lynx"},
    "second_team": {"id": 423, "title": "South America Rejects"},
    "maps": [{
        "radiant_team_id": 7784,
        "dire_team_id": 423,
        "radiant_picks": null,
        "dire_picks": null
    }],
    "series_players": [
        {"team_id": 7784, "role": 2, "player": {"slug": "mellojul"}},
        {"team_id": 7784, "role": 1, "player": {"slug": "7jesu"}},
        {"team_id": 7784, "role": 4, "player": {"slug": "qbfy"}},
        {"team_id": 7784, "role": 3, "player": {"slug": "vladikthehtiviy"}},
        {"team_id": 7784, "role": 5, "player": {"slug": "kreker"}},
        {"team_id": 423, "role": 4, "player": {"slug": "scofield"}},
        {"team_id": 423, "role": 3, "player": {"slug": "frank"}},
        {"team_id": 423, "role": 2, "player": {"slug": "darkmago"}},
        {"team_id": 423, "role": 5, "player": {"slug": "elmisho"}},
        {"team_id": 423, "role": 1, "player": {"slug": "wits"}}
    ]
};
</script>
<div class="picks__new-picks__picks radiant">
    <div class="items">
        <div class="pick player" data-hero-id="28" data-tippy-content="Slardar"><a href="/players/vladikthehtiviy?date_range=4#hero-slardar" class="pick__stats"></a></div>
        <div class="pick player" data-hero-id="21" data-tippy-content="Windranger"><a href="/players/qbfy?date_range=4#hero-windranger" class="pick__stats"></a></div>
        <div class="pick player" data-hero-id="90" data-tippy-content="Keeper of the Light"><a href="/players/mellojul?date_range=4#hero-keeper-of-the-light" class="pick__stats"></a></div>
        <div class="pick player" data-hero-id="48" data-tippy-content="Luna"><a href="/players/7jesu?date_range=4#hero-luna" class="pick__stats"></a></div>
        <div class="pick player" data-hero-id="100" data-tippy-content="Tusk"><a href="/players/kreker?date_range=4#hero-tusk" class="pick__stats"></a></div>
    </div>
</div>
<div class="picks__new-picks__picks dire">
    <div class="items">
        <div class="pick player" data-hero-id="36" data-tippy-content="Necrophos"><a href="/players/wits?date_range=4#hero-necrophos" class="pick__stats"></a></div>
        <div class="pick player" data-hero-id="79" data-tippy-content="Shadow Demon"><a href="/players/scofield?date_range=4#hero-shadow-demon" class="pick__stats"></a></div>
        <div class="pick player" data-hero-id="120" data-tippy-content="Pangolier"><a href="/players/frank?date_range=4#hero-pangolier" class="pick__stats"></a></div>
        <div class="pick player" data-hero-id="58" data-tippy-content="Enchantress"><a href="/players/elmisho?date_range=4#hero-enchantress" class="pick__stats"></a></div>
        <div class="pick player" data-hero-id="39" data-tippy-content="Queen of Pain"><a href="/players/darkmago?date_range=4#hero-queen-of-pain" class="pick__stats"></a></div>
    </div>
</div>
HTML;

        $payload = (new DltvMatchHtmlParser)->parse($html);

        $this->assertSame('Team Lynx', $payload['radiant_team']);
        $this->assertSame('South America Rejects', $payload['dire_team']);
        $this->assertSame([48, 90, 28, 21, 100], $payload['radiant_heroes']);
        $this->assertSame([36, 39, 120, 79, 58], $payload['dire_heroes']);
    }

    public function test_it_rejects_incomplete_drafts(): void
    {
        $this->expectException(RuntimeException::class);

        (new DltvMatchHtmlParser)->parse('<html><body>No draft here</body></html>');
    }
}
