<?php

namespace App\Services\GoogleSheets;

use App\Models\DraftSnapshot;
use App\Services\DraftSnapshots\DraftSnapshotFeatureAggregator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RoshSheetService
{
    private const OAUTH_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    private const MATCH_ID_COLUMN = 'A';

    private const TEAM_1_RADIANT_COLUMN = 'C';

    private const TEAM_2_DIRE_COLUMN = 'D';

    private const WHO_WON_COLUMN = 'E';

    private const RADIANT_ODDS_1_COLUMN = 'F';

    private const RADIANT_ODDS_2_COLUMN = 'G';

    private const DIRE_ODDS_1_COLUMN = 'I';

    private const DIRE_ODDS_2_COLUMN = 'J';

    private const DATASET_SHEET_TITLE = 'Dataset';

    /**
     * @var list<string>
     */
    private const BASE_DATASET_HEADERS = [
        'snapshot_id',
        'source',
        'captured_at',
        'match_id',
        'tournament',
        'radiant_team',
        'dire_team',
        'winner',
        'model_version',
        'sheet_title',
        'sheet_row',
        'baseline_ready',
        'windows_4_8_12_ready',
        'bucket_count',
        'radiant_odds_1',
        'radiant_odds_2',
        'dire_odds_1',
        'dire_odds_2',
        'avg_radiant_odds',
        'avg_dire_odds',
        'bookmaker_odds_radiant',
        'bookmaker_odds_dire',
        'raw_size_mb',
    ];

    public function __construct(private readonly DraftSnapshotFeatureAggregator $featureAggregator) {}

    public function isConfigured(): bool
    {
        $credentialsPath = (string) config('services.google_sheets.service_account_credentials');

        return filled(config('services.google_sheets.spreadsheet_url'))
            && filled($credentialsPath)
            && is_file($this->resolveCredentialsPath($credentialsPath));
    }

    /**
     * @param  array{
     *     match_id?:int,
     *     winner?:string,
     *     radiant_team?:string,
     *     dire_team?:string,
     *     radiant_odds_1:?float,
     *     radiant_odds_2:?float,
     *     dire_odds_1:?float,
     *     dire_odds_2:?float
     * }  $formatted
     * @return array{
     *     spreadsheet_id:string,
     *     sheet_title:string,
     *     row:int,
     *     cells:array<string, string>
     * }
     */
    public function syncMatchOdds(int $matchId, array $formatted): array
    {
        return $this->syncOdds((string) $matchId, $formatted);
    }

    /**
     * @param  array{
     *     match_id?:int|string,
     *     winner?:string,
     *     radiant_team?:string,
     *     dire_team?:string,
     *     radiant_odds_1:?float,
     *     radiant_odds_2:?float,
     *     dire_odds_1:?float,
     *     dire_odds_2:?float
     * }  $formatted
     * @return array{
     *     spreadsheet_id:string,
     *     sheet_title:string,
     *     row:int,
     *     cells:array<string, string>
     * }
     */
    public function appendLiveOdds(array $formatted): array
    {
        return $this->syncOdds('LIVE', $formatted, true);
    }

    /**
     * @param  Collection<int, DraftSnapshot>  $snapshots
     * @return array{
     *     spreadsheet_id:string,
     *     sheet_title:string,
     *     exported_rows:int,
     *     rows:array<int, int>
     * }
     */
    public function syncSnapshotDataset(Collection $snapshots): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Sheets dataset sync is not configured.');
        }

        $spreadsheet = $this->parseSpreadsheetConfig(
            (string) config('services.google_sheets.spreadsheet_url'),
        );
        $accessToken = $this->requestAccessToken();
        $sheetTitle = $this->ensureSheetExists(
            $accessToken,
            $spreadsheet['spreadsheet_id'],
            self::DATASET_SHEET_TITLE,
        );

        $this->batchUpdateValues(
            $accessToken,
            $spreadsheet['spreadsheet_id'],
            [
                $this->sheetRange($sheetTitle, $this->rowRange(1)) => $this->datasetHeaders(),
            ],
        );

        $existingRows = $this->datasetExistingRows(
            $accessToken,
            $spreadsheet['spreadsheet_id'],
            $sheetTitle,
        );
        $nextRow = $existingRows === [] ? 2 : max(array_column($existingRows, 'row')) + 1;
        $ranges = [];
        $rows = [];

        foreach ($snapshots as $snapshot) {
            $snapshotId = (string) $snapshot->id;
            $row = $existingRows[$snapshotId]['row'] ?? $nextRow++;
            $rows[$snapshot->id] = $row;
            $ranges[$this->sheetRange($sheetTitle, $this->rowRange($row))] = $this->snapshotDatasetRow(
                $snapshot,
                $existingRows[$snapshotId]['values'] ?? [],
            );
        }

        if ($ranges !== []) {
            $this->batchUpdateValues($accessToken, $spreadsheet['spreadsheet_id'], $ranges);
        }

        return [
            'spreadsheet_id' => $spreadsheet['spreadsheet_id'],
            'sheet_title' => $sheetTitle,
            'exported_rows' => count($ranges),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{
     *     match_id?:int|string,
     *     winner?:string,
     *     radiant_team?:string,
     *     dire_team?:string,
     *     radiant_odds_1:?float,
     *     radiant_odds_2:?float,
     *     dire_odds_1:?float,
     *     dire_odds_2:?float
     * }  $formatted
     * @return array{
     *     spreadsheet_id:string,
     *     sheet_title:string,
     *     row:int,
     *     cells:array<string, string>
     * }
     */
    private function syncOdds(string $matchIdValue, array $formatted, bool $alwaysAppend = false): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Sheets ROSH sync is not configured.');
        }

        $spreadsheet = $this->parseSpreadsheetConfig(
            (string) config('services.google_sheets.spreadsheet_url'),
        );
        $accessToken = $this->requestAccessToken();
        $sheetTitle = $this->resolveSheetTitle(
            $accessToken,
            $spreadsheet['spreadsheet_id'],
            $spreadsheet['gid'],
        );
        $row = $this->findOrNextMatchRow(
            $accessToken,
            $spreadsheet['spreadsheet_id'],
            $sheetTitle,
            $matchIdValue,
            $alwaysAppend,
        );
        $teamMetadata = [
            $this->formatSheetTextValue($formatted['radiant_team'] ?? null),
            $this->formatSheetTextValue($formatted['dire_team'] ?? null),
            $this->formatWinnerValue($formatted['winner'] ?? null),
        ];

        $radiantValues = [
            $this->formatPercentValue($formatted['radiant_odds_1'] ?? null),
            $this->formatPercentValue($formatted['radiant_odds_2'] ?? null),
        ];
        $direValues = [
            $this->formatPercentValue($formatted['dire_odds_1'] ?? null),
            $this->formatPercentValue($formatted['dire_odds_2'] ?? null),
        ];

        $this->batchUpdateValues(
            $accessToken,
            $spreadsheet['spreadsheet_id'],
            [
                $this->sheetRange(
                    $sheetTitle,
                    self::MATCH_ID_COLUMN.$row,
                ) => [$matchIdValue],
                $this->sheetRange(
                    $sheetTitle,
                    self::TEAM_1_RADIANT_COLUMN.$row.':'.self::WHO_WON_COLUMN.$row,
                ) => $teamMetadata,
                $this->sheetRange(
                    $sheetTitle,
                    self::RADIANT_ODDS_1_COLUMN.$row.':'.self::RADIANT_ODDS_2_COLUMN.$row,
                ) => $radiantValues,
                $this->sheetRange(
                    $sheetTitle,
                    self::DIRE_ODDS_1_COLUMN.$row.':'.self::DIRE_ODDS_2_COLUMN.$row,
                ) => $direValues,
            ],
        );

        $verifiedValues = $this->batchGetValues(
            $accessToken,
            $spreadsheet['spreadsheet_id'],
            [
                'A'.$row,
                'C'.$row,
                'D'.$row,
                'E'.$row,
                'F'.$row,
                'G'.$row,
                'I'.$row,
                'J'.$row,
            ],
            $sheetTitle,
        );

        return [
            'spreadsheet_id' => $spreadsheet['spreadsheet_id'],
            'sheet_title' => $sheetTitle,
            'row' => $row,
            'cells' => $verifiedValues,
        ];
    }

    /**
     * @return array{spreadsheet_id:string, gid:?int}
     */
    private function parseSpreadsheetConfig(string $spreadsheetUrl): array
    {
        if (! preg_match('~/spreadsheets/d/([a-zA-Z0-9-_]+)~', $spreadsheetUrl, $matches)) {
            throw new RuntimeException('Google Sheets URL is invalid.');
        }

        $gid = null;
        $query = parse_url($spreadsheetUrl, PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $queryParams);

            if (isset($queryParams['gid']) && is_numeric($queryParams['gid'])) {
                $gid = (int) $queryParams['gid'];
            }
        }

        $fragment = parse_url($spreadsheetUrl, PHP_URL_FRAGMENT);

        if ($gid === null && is_string($fragment) && preg_match('/gid=(\d+)/', $fragment, $fragmentMatches)) {
            $gid = (int) $fragmentMatches[1];
        }

        return [
            'spreadsheet_id' => $matches[1],
            'gid' => $gid,
        ];
    }

    private function requestAccessToken(): string
    {
        $serviceAccount = $this->loadServiceAccountCredentials();
        $issuedAt = time();
        $expiresAt = $issuedAt + 3600;
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $serviceAccount['private_key_id'],
        ], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => self::SHEETS_SCOPE,
            'aud' => self::OAUTH_TOKEN_ENDPOINT,
            'exp' => $expiresAt,
            'iat' => $issuedAt,
        ], JSON_THROW_ON_ERROR));
        $unsignedToken = $header.'.'.$claims;
        $signature = '';
        $signingResult = openssl_sign(
            $unsignedToken,
            $signature,
            $serviceAccount['private_key'],
            OPENSSL_ALGO_SHA256,
        );

        if ($signingResult !== true) {
            throw new RuntimeException('Unable to sign Google service account JWT.');
        }

        $assertion = $unsignedToken.'.'.$this->base64UrlEncode($signature);
        $response = Http::asForm()
            ->timeout($this->timeout())
            ->post(self::OAUTH_TOKEN_ENDPOINT, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
        $data = $this->decodeResponse($response, 'Google OAuth token request failed.');
        $accessToken = data_get($data, 'access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google OAuth token response did not include access_token.');
        }

        return $accessToken;
    }

    private function resolveSheetTitle(string $accessToken, string $spreadsheetId, ?int $gid): string
    {
        $response = $this->authorizedRequest($accessToken)
            ->get(self::SHEETS_API_BASE.'/'.$spreadsheetId, [
                'fields' => 'sheets(properties(sheetId,title,index))',
            ]);
        $data = $this->decodeResponse($response, 'Google Sheets metadata request failed.');
        $sheets = (array) data_get($data, 'sheets', []);

        foreach ($sheets as $sheet) {
            if ($gid !== null && (int) data_get($sheet, 'properties.sheetId') !== $gid) {
                continue;
            }

            $title = data_get($sheet, 'properties.title');

            if (is_string($title) && $title !== '') {
                return $title;
            }
        }

        $fallbackTitle = data_get($sheets, '0.properties.title');

        if (! is_string($fallbackTitle) || $fallbackTitle === '') {
            throw new RuntimeException('Unable to resolve the Google Sheets tab title.');
        }

        return $fallbackTitle;
    }

    private function ensureSheetExists(string $accessToken, string $spreadsheetId, string $sheetTitle): string
    {
        $response = $this->authorizedRequest($accessToken)
            ->get(self::SHEETS_API_BASE.'/'.$spreadsheetId, [
                'fields' => 'sheets(properties(title))',
            ]);
        $data = $this->decodeResponse($response, 'Google Sheets metadata request failed.');

        foreach ((array) data_get($data, 'sheets', []) as $sheet) {
            if (data_get($sheet, 'properties.title') === $sheetTitle) {
                return $sheetTitle;
            }
        }

        $response = $this->authorizedRequest($accessToken)
            ->post(self::SHEETS_API_BASE.'/'.$spreadsheetId.':batchUpdate', [
                'requests' => [
                    [
                        'addSheet' => [
                            'properties' => [
                                'title' => $sheetTitle,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->decodeResponse($response, 'Google Sheets dataset sheet creation failed.');

        return $sheetTitle;
    }

    /**
     * @return array<string, array{row:int, values:list<string>}>
     */
    private function datasetExistingRows(string $accessToken, string $spreadsheetId, string $sheetTitle): array
    {
        $range = $this->sheetRange($sheetTitle, 'A:'.$this->columnName(count($this->datasetHeaders())));
        $response = $this->authorizedRequest($accessToken)
            ->get(self::SHEETS_API_BASE.'/'.$spreadsheetId.'/values/'.rawurlencode($range));
        $data = $this->decodeResponse($response, 'Google Sheets dataset lookup request failed.');
        $rows = [];

        foreach ((array) data_get($data, 'values', []) as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $snapshotId = trim((string) data_get($row, '0', ''));

            if ($snapshotId !== '') {
                $rows[$snapshotId] = [
                    'row' => $index + 1,
                    'values' => array_values(array_map('strval', (array) $row)),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function datasetHeaders(): array
    {
        return [
            ...self::BASE_DATASET_HEADERS,
            ...$this->featureAggregator->headers(),
        ];
    }

    private function rowRange(int $row): string
    {
        return 'A'.$row.':'.$this->columnName(count($this->datasetHeaders())).$row;
    }

    private function columnName(int $columnNumber): string
    {
        $columnName = '';

        while ($columnNumber > 0) {
            $columnNumber--;
            $columnName = chr(65 + ($columnNumber % 26)).$columnName;
            $columnNumber = intdiv($columnNumber, 26);
        }

        return $columnName;
    }

    /**
     * @param  list<string>  $existingRow
     * @return list<string>
     */
    private function snapshotDatasetRow(DraftSnapshot $snapshot, array $existingRow = []): array
    {
        $formatted = (array) data_get($snapshot->feature_payload, 'formatted', []);
        $radiantOdds = [
            $this->numericOrNull(data_get($formatted, 'radiant_odds_1')),
            $this->numericOrNull(data_get($formatted, 'radiant_odds_2')),
        ];
        $direOdds = [
            $this->numericOrNull(data_get($formatted, 'dire_odds_1')),
            $this->numericOrNull(data_get($formatted, 'dire_odds_2')),
        ];

        return [
            (string) $snapshot->id,
            (string) $snapshot->source,
            $snapshot->captured_at?->toISOString() ?? '',
            $snapshot->match_id !== null ? (string) $snapshot->match_id : 'LIVE',
            $this->formatSheetTextValue($snapshot->tournament),
            $this->formatSheetTextValue($snapshot->radiant_team),
            $this->formatSheetTextValue($snapshot->dire_team),
            $this->formatWinnerValue(data_get($snapshot->result_payload, 'winner')),
            $this->formatSheetTextValue($snapshot->model_version),
            $this->formatSheetTextValue(data_get($snapshot->sheet_payload, 'sheet_title')),
            $this->formatSheetScalar(data_get($snapshot->sheet_payload, 'row')),
            $this->formatBooleanValue($this->hasBaselineBuckets($snapshot)),
            $this->formatBooleanValue($this->hasCompleteCollectedWindows($snapshot)),
            (string) $this->snapshotBuckets($snapshot)->count(),
            $this->formatDecimalValue($radiantOdds[0]),
            $this->formatDecimalValue($radiantOdds[1]),
            $this->formatDecimalValue($direOdds[0]),
            $this->formatDecimalValue($direOdds[1]),
            $this->formatDecimalValue($this->averageNumericValues($radiantOdds)),
            $this->formatDecimalValue($this->averageNumericValues($direOdds)),
            $this->manualDatasetValue(
                $existingRow,
                'bookmaker_odds_radiant',
                $this->formatDecimalValue($this->numericOrNull($snapshot->bookmaker_odds_radiant)),
            ),
            $this->manualDatasetValue(
                $existingRow,
                'bookmaker_odds_dire',
                $this->formatDecimalValue($this->numericOrNull($snapshot->bookmaker_odds_dire)),
            ),
            $this->formatDecimalValue($this->snapshotRawSizeMegabytes($snapshot)),
            ...array_map(
                fn (?float $value): string => $this->formatDecimalValue($value),
                array_values($this->featureAggregator->aggregate($snapshot)),
            ),
        ];
    }

    /**
     * @param  list<string>  $existingRow
     */
    private function manualDatasetValue(array $existingRow, string $header, string $fallback = ''): string
    {
        $index = array_search($header, $this->datasetHeaders(), true);

        if (! is_int($index)) {
            return $fallback;
        }

        $existingValue = trim((string) data_get($existingRow, (string) $index, ''));

        return $existingValue !== '' ? $existingValue : $fallback;
    }

    private function hasBaselineBuckets(DraftSnapshot $snapshot): bool
    {
        $buckets = $this->snapshotBuckets($snapshot);

        return collect([
            ['heroes_meta_positions', 1],
            ['hero_stats_by_time_bracket', 1],
            ['synergy', 4],
        ])->every(fn (array $bucket): bool => $buckets->contains(
            fn ($existingBucket): bool => $existingBucket->data_type === $bucket[0]
                && $existingBucket->window_weeks === $bucket[1],
        ));
    }

    private function hasCompleteCollectedWindows(DraftSnapshot $snapshot): bool
    {
        $buckets = $this->snapshotBuckets($snapshot);

        return collect(['heroes_meta_positions', 'hero_stats_by_time_bracket', 'synergy'])
            ->every(fn (string $dataType): bool => collect([4, 8, 12])
                ->every(fn (int $windowWeeks): bool => $buckets->contains(
                    fn ($bucket): bool => $bucket->data_type === $dataType
                        && $bucket->window_weeks === $windowWeeks
                        && str_starts_with($bucket->bucket_key, 'stratz_window_collector:'),
                )));
    }

    private function snapshotRawSizeMegabytes(DraftSnapshot $snapshot): ?float
    {
        $bytes = 0;

        foreach ($this->snapshotBuckets($snapshot) as $bucket) {
            $bytes += strlen((string) json_encode($bucket->raw_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if ($bytes === 0) {
            return null;
        }

        return round($bytes / 1024 / 1024, 3);
    }

    private function snapshotBuckets(DraftSnapshot $snapshot): Collection
    {
        if ($snapshot->relationLoaded('stratzDataBuckets')) {
            return $snapshot->stratzDataBuckets;
        }

        return $snapshot->stratzDataBuckets()->get();
    }

    private function findOrNextMatchRow(
        string $accessToken,
        string $spreadsheetId,
        string $sheetTitle,
        string $matchIdValue,
        bool $alwaysAppend = false,
    ): int {
        $range = $this->sheetRange($sheetTitle, self::MATCH_ID_COLUMN.':'.self::MATCH_ID_COLUMN);
        $response = $this->authorizedRequest($accessToken)
            ->get(self::SHEETS_API_BASE.'/'.$spreadsheetId.'/values/'.rawurlencode($range));
        $data = $this->decodeResponse($response, 'Google Sheets match lookup request failed.');
        $rows = (array) data_get($data, 'values', []);

        if (! $alwaysAppend) {
            foreach ($rows as $index => $row) {
                $value = trim((string) data_get($row, '0', ''));

                if ($value === $matchIdValue) {
                    return $index + 1;
                }
            }
        }

        return count($rows) + 1;
    }

    /**
     * @param  array<string, list<string>>  $ranges
     */
    private function batchUpdateValues(string $accessToken, string $spreadsheetId, array $ranges): void
    {
        $data = [];

        foreach ($ranges as $range => $values) {
            $data[] = [
                'range' => $range,
                'majorDimension' => 'ROWS',
                'values' => [$values],
            ];
        }

        $response = $this->authorizedRequest($accessToken)
            ->post(self::SHEETS_API_BASE.'/'.$spreadsheetId.'/values:batchUpdate', [
                'valueInputOption' => 'USER_ENTERED',
                'data' => $data,
            ]);

        $this->decodeResponse($response, 'Google Sheets batch update failed.');
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function batchGetValues(string $accessToken, string $spreadsheetId, array $cells, string $sheetTitle): array
    {
        $ranges = array_map(
            fn (string $cell): string => $this->sheetRange($sheetTitle, $cell),
            $cells,
        );
        $query = implode('&', array_map(
            fn (string $range): string => 'ranges='.rawurlencode($range),
            $ranges,
        ));
        $response = $this->authorizedRequest($accessToken)
            ->get(self::SHEETS_API_BASE.'/'.$spreadsheetId.'/values:batchGet?'.$query);
        $data = $this->decodeResponse($response, 'Google Sheets verification read failed.');
        $valueRanges = (array) data_get($data, 'valueRanges', []);
        $verifiedValues = [];

        foreach ($valueRanges as $index => $valueRange) {
            $verifiedValues[$cells[$index]] = (string) data_get($valueRange, 'values.0.0', '');
        }

        return $verifiedValues;
    }

    /**
     * @return array{
     *     client_email:string,
     *     private_key:string,
     *     private_key_id:string
     * }
     */
    private function loadServiceAccountCredentials(): array
    {
        $credentialsPath = $this->resolveCredentialsPath(
            (string) config('services.google_sheets.service_account_credentials'),
        );

        if (! is_file($credentialsPath)) {
            throw new RuntimeException("Google service account credentials file was not found at [{$credentialsPath}].");
        }

        $credentials = json_decode((string) file_get_contents($credentialsPath), true, 512, JSON_THROW_ON_ERROR);
        $clientEmail = data_get($credentials, 'client_email');
        $privateKey = data_get($credentials, 'private_key');
        $privateKeyId = data_get($credentials, 'private_key_id');

        if (! is_string($clientEmail) || ! is_string($privateKey) || ! is_string($privateKeyId)) {
            throw new RuntimeException('Google service account credentials are missing required fields.');
        }

        return [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'private_key_id' => $privateKeyId,
        ];
    }

    private function resolveCredentialsPath(string $configuredPath): string
    {
        if ($configuredPath === '') {
            throw new RuntimeException('Google service account credentials path is empty.');
        }

        if (str_starts_with($configuredPath, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $configuredPath) === 1) {
            return $configuredPath;
        }

        return base_path($configuredPath);
    }

    private function authorizedRequest(string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout())
            ->withToken($accessToken);
    }

    private function timeout(): int
    {
        return (int) config('services.google_sheets.timeout', 20);
    }

    private function sheetRange(string $sheetTitle, string $range): string
    {
        return "'".str_replace("'", "''", $sheetTitle)."'!".$range;
    }

    private function formatPercentValue(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '';
        }

        return number_format((float) $value, 1, ',', '').'%';
    }

    private function formatSheetTextValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function formatSheetScalar(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private function formatBooleanValue(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function formatDecimalValue(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return number_format($value, 3, '.', '');
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  list<?float>  $values
     */
    private function averageNumericValues(array $values): ?float
    {
        $numericValues = array_values(array_filter($values, is_float(...)));

        if ($numericValues === []) {
            return null;
        }

        return array_sum($numericValues) / count($numericValues);
    }

    private function formatWinnerValue(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return match (strtolower(trim($value))) {
            'radiant' => 'Radiant',
            'dire' => 'Dire',
            default => '',
        };
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response, string $messagePrefix): array
    {
        if (! $response->successful()) {
            throw new RuntimeException($messagePrefix.' '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException($messagePrefix.' Invalid JSON response.');
        }

        return $json;
    }
}
