<?php

namespace App\Services\Stratz;

use App\Exceptions\ExternalHttpRequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Api
{
    private const USER_AGENT = 'STRATZ_API';

    /**
     * @var array<string, string>
     */
    private const REQUIRED_HEADERS = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'User-Agent' => self::USER_AGENT,
        'GraphQL-Require-Preflight' => '1',
    ];

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

        $headers = self::REQUIRED_HEADERS;
        $url = (string) config('services.stratz.endpoint');
        $requestHeaders = [
            ...$headers,
            'Authorization' => 'Bearer [redacted]',
        ];

        $response = Http::withHeaders($headers)
            ->asJson()
            ->withToken($token)
            ->withOptions($this->httpOptions())
            ->timeout((int) config('services.stratz.timeout', 20))
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);

        return $this->parseResponse($response, $allowGraphQLErrors, $requestHeaders, $url);
    }

    /**
     * @return array{data: array<string, mixed>, errors: list<array<string, mixed>>}
     */
    protected function parseResponse(Response $response, bool $allowGraphQLErrors = false, array $requestHeaders = [], ?string $requestUrl = null): array
    {
        if ($response->failed()) {
            throw new ExternalHttpRequestException(
                $this->resolveErrorMessage($response),
                $this->responseContext($response, $requestHeaders, $requestUrl),
                $response->status(),
            );
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
     * @return array<string, mixed>
     */
    private function responseContext(Response $response, array $requestHeaders = [], ?string $requestUrl = null): array
    {
        return [
            'service' => 'stratz',
            'status' => $response->status(),
            'url' => $requestUrl ?? (string) config('services.stratz.endpoint'),
            'request_headers' => $this->safeHeaders($this->normalizeHeaderValues($requestHeaders)),
            'headers' => $this->safeHeaders($response->headers()),
            'body' => $this->truncateBody($response->body()),
        ];
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, list<string>>
     */
    private function normalizeHeaderValues(array $headers): array
    {
        return collect($headers)
            ->map(fn (string|array $value): array => is_array($value) ? array_values($value) : [$value])
            ->all();
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array<string, list<string>>
     */
    private function safeHeaders(array $headers): array
    {
        return collect($headers)
            ->reject(fn (mixed $value, string $header): bool => in_array(strtolower($header), [
                'set-cookie',
                'cookie',
            ], true))
            ->mapWithKeys(fn (array $value, string $header): array => in_array(strtolower($header), [
                'authorization',
                'x-api-key',
            ], true) ? [$header => ['[redacted]']] : [$header => $value])
            ->all();
    }

    private function truncateBody(string $body): string
    {
        $body = trim($body);

        return strlen($body) > 12000 ? substr($body, 0, 12000).'... [truncated]' : $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpOptions(): array
    {
        if (! config('services.stratz.force_ipv4', false)) {
            return [];
        }

        if (! defined('CURLOPT_IPRESOLVE') || ! defined('CURL_IPRESOLVE_V4')) {
            return [];
        }

        return [
            'curl' => [
                constant('CURLOPT_IPRESOLVE') => constant('CURL_IPRESOLVE_V4'),
            ],
        ];
    }

    /**
     * @param  list<mixed>  $errors
     */
    private function firstGraphQLErrorMessage(array $errors): string
    {
        return (string) data_get($errors, '0.message', 'Unknown GraphQL error');
    }
}
