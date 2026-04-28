<?php

namespace App\Services\Dltv;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DltvGistHtmlFetcher
{
    public function fetch(): string
    {
        $gistUrl = config('services.dltv.gist_url');

        if (! is_string($gistUrl) || trim($gistUrl) === '') {
            throw new RuntimeException('DLTV Gist URL is not configured. Set DLTV_GIST_URL in .env.');
        }

        $rawUrl = $this->resolveRawUrl(trim($gistUrl));
        $response = Http::accept('text/html')
            ->timeout((int) config('services.dltv.timeout', 20))
            ->get($rawUrl);

        if ($response->failed()) {
            throw new RuntimeException('Failed to download DLTV HTML from Gist raw URL.');
        }

        $html = trim($response->body());

        if ($html === '') {
            throw new RuntimeException('DLTV Gist raw file is empty.');
        }

        return $html;
    }

    private function resolveRawUrl(string $gistUrl): string
    {
        if (str_contains(parse_url($gistUrl, PHP_URL_HOST) ?: '', 'gist.githubusercontent.com')) {
            return $gistUrl;
        }

        $gistId = $this->extractGistId($gistUrl);
        $response = Http::acceptJson()
            ->timeout((int) config('services.dltv.timeout', 20))
            ->get("https://api.github.com/gists/{$gistId}");

        if ($response->failed()) {
            throw new RuntimeException('Failed to resolve DLTV Gist via GitHub API.');
        }

        $files = $response->json('files');

        if (! is_array($files) || $files === []) {
            throw new RuntimeException('DLTV Gist does not contain files.');
        }

        $file = $this->selectHtmlFile($files);
        $rawUrl = data_get($file, 'raw_url');

        if (! is_string($rawUrl) || $rawUrl === '') {
            throw new RuntimeException('DLTV Gist file does not contain a raw_url.');
        }

        return $rawUrl;
    }

    private function extractGistId(string $gistUrl): string
    {
        $path = (string) parse_url($gistUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path)));
        $gistId = end($segments);

        if (! is_string($gistId) || ! preg_match('/^[a-f0-9]+$/i', $gistId)) {
            throw new RuntimeException('DLTV_GIST_URL must be a GitHub Gist URL or a raw gist URL.');
        }

        return $gistId;
    }

    /**
     * @param  array<string, mixed>  $files
     * @return array<string, mixed>
     */
    private function selectHtmlFile(array $files): array
    {
        foreach ($files as $file) {
            if (is_array($file) && str_ends_with(strtolower((string) ($file['filename'] ?? '')), '.html')) {
                return $file;
            }
        }

        $firstFile = reset($files);

        if (! is_array($firstFile)) {
            throw new RuntimeException('DLTV Gist file metadata has an invalid format.');
        }

        return $firstFile;
    }
}
