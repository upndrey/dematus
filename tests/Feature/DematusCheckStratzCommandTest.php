<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DematusCheckStratzCommandTest extends TestCase
{
    public function test_it_reports_success_when_stratz_is_reachable(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');

        Http::fake([
            'https://api.stratz.com/graphql' => Http::response([
                'data' => [
                    'match' => [
                        'id' => 8893106015,
                    ],
                ],
            ]),
        ]);

        $this->artisan('dematus:check-stratz')
            ->expectsOutput('STRATZ OK: Dematus can reach https://api.stratz.com/graphql')
            ->assertExitCode(0);
    }

    public function test_it_reports_failure_when_stratz_is_not_reachable(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');

        Http::fake([
            'https://api.stratz.com/graphql' => Http::response([], 500),
        ]);

        $this->artisan('dematus:check-stratz')
            ->expectsOutputToContain('STRATZ ERROR:')
            ->assertExitCode(1);
    }
}
