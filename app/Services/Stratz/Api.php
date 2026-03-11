<?php

namespace App\Services\Stratz;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Api
{
    private const USER_AGENT = 'STRATZ_API';

    public function query(string $query, array $variables = []): array
    {
        $token = config('services.stratz.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('STRATZ token is not configured. Set STRATZ_TOKEN in .env.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
            ])
            ->withToken($token)
            ->timeout((int) config('services.stratz.timeout', 20))
            ->post((string) config('services.stratz.endpoint'), [
                'query' => $query,
                'variables' => $variables,
            ]);

        return $this->parseResponse($response);
    }

    protected function parseResponse(Response $response): array
    {
        $response->throw();

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Invalid STRATZ response format.');
        }

        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            $firstErrorMessage = (string) data_get($payload, 'errors.0.message', 'Unknown GraphQL error');
            throw new RuntimeException('STRATZ GraphQL error: '.$firstErrorMessage);
        }

        return (array) ($payload['data'] ?? []);
    }
}
