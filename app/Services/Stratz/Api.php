<?php

namespace App\Services\Stratz;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Api
{
    private const USER_AGENT = 'STRATZ_API';

    private const DIFFERENT_IP_ERROR_FRAGMENT = 'You cannot use different IP Addresses when using the API.';

    public function query(string $query, array $variables = []): array
    {
        return $this->queryPayload($query, $variables)['data'];
    }

    /**
     * @return array{data: array<string, mixed>, errors: list<array<string, mixed>>}
     */
    public function queryAllowPartial(string $query, array $variables = []): array
    {
        return $this->queryPayload($query, $variables, true);
    }

    /**
     * @return array{data: array<string, mixed>, errors: list<array<string, mixed>>}
     */
    private function queryPayload(string $query, array $variables = [], bool $allowGraphQLErrors = false): array
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

        return $this->parseResponse($response, $allowGraphQLErrors);
    }

    /**
     * @return array{data: array<string, mixed>, errors: list<array<string, mixed>>}
     */
    protected function parseResponse(Response $response, bool $allowGraphQLErrors = false): array
    {
        if ($response->failed()) {
            throw new RuntimeException($this->resolveErrorMessage($response));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Invalid STRATZ response format.');
        }

        $errors = array_values(array_filter(
            is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
            static fn (mixed $error): bool => is_array($error),
        ));

        if (! $allowGraphQLErrors && $errors !== []) {
            throw new RuntimeException('STRATZ GraphQL error: '.$this->firstGraphQLErrorMessage($errors));
        }

        return [
            'data' => (array) ($payload['data'] ?? []),
            'errors' => $errors,
        ];
    }

    protected function resolveErrorMessage(Response $response): string
    {
        $body = trim($response->body());

        if ($response->status() === 403 && str_contains($body, self::DIFFERENT_IP_ERROR_FRAGMENT)) {
            return 'STRATZ token is tied to another public IP address. Use this token only from one IP, disable VPN/proxy switching, or create a separate token for this environment.';
        }

        if ($response->status() === 403 && str_contains($body, 'Just a moment...')) {
            return 'STRATZ rejected the request with a Cloudflare challenge. This token should be used from a backend environment that STRATZ accepts.';
        }

        $payload = $response->json();

        if (is_array($payload) && isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            return 'STRATZ GraphQL error: '.$this->firstGraphQLErrorMessage($payload['errors']);
        }

        $exception = $response->toException();

        if ($exception !== null) {
            return $exception->getMessage();
        }

        return 'STRATZ request failed with HTTP '.$response->status().'.';
    }

    /**
     * @param  list<mixed>  $errors
     */
    private function firstGraphQLErrorMessage(array $errors): string
    {
        return (string) data_get($errors, '0.message', 'Unknown GraphQL error');
    }
}
