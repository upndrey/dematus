<?php

namespace App\Console\Commands;

use App\Services\Stratz\Api;
use Illuminate\Console\Command;
use RuntimeException;

class StratzSyncHeroesEnum extends Command
{
    protected $signature = 'app:stratz-sync-heroes-enum';

    protected $description = 'Sync App\\Enums\\Stratz\\Hero enum from STRATZ constants.heroes';

    public function __construct(protected Api $api)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = <<<'GRAPHQL'
query SyncHeroes {
  constants {
    heroes {
      id
      displayName
    }
  }
}
GRAPHQL;

        $heroes = data_get($this->api->query($query), 'constants.heroes', []);

        if (! is_array($heroes) || $heroes === []) {
            throw new RuntimeException('STRATZ returned empty heroes list.');
        }

        $normalizedHeroes = [];

        foreach ($heroes as $hero) {
            if (! is_array($hero)) {
                continue;
            }

            $id = (int) ($hero['id'] ?? 0);
            $displayName = trim((string) ($hero['displayName'] ?? ''));

            if ($id <= 0 || $displayName === '') {
                continue;
            }

            $normalizedHeroes[$id] = [
                'id' => $id,
                'displayName' => $displayName,
            ];
        }

        if ($normalizedHeroes === []) {
            throw new RuntimeException('STRATZ heroes list does not contain valid data.');
        }

        ksort($normalizedHeroes, SORT_NUMERIC);

        $usedCaseNames = [];
        $cases = [];

        foreach ($normalizedHeroes as $hero) {
            $caseName = $this->buildCaseName($hero['displayName'], $hero['id']);

            $originalCaseName = $caseName;
            $counter = 2;

            while (isset($usedCaseNames[$caseName])) {
                $caseName = $originalCaseName.$counter;
                $counter++;
            }

            $usedCaseNames[$caseName] = true;

            $cases[] = [
                'caseName' => $caseName,
                'id' => $hero['id'],
                'displayName' => $hero['displayName'],
            ];
        }

        $enum = "<?php\n\nnamespace App\\Enums\\Stratz;\n\nenum Hero: int\n{\n";

        foreach ($cases as $case) {
            $enum .= "    case {$case['caseName']} = {$case['id']};\n";
        }

        $enum .= "\n    public function title(): string\n    {\n        return match (\$this) {\n";

        foreach ($cases as $case) {
            $safeTitle = str_replace("'", "\\'", $case['displayName']);
            $enum .= "            self::{$case['caseName']} => '{$safeTitle}',\n";
        }

        $enum .= "        };\n    }\n}\n";

        file_put_contents(app_path('Enums/Stratz/Hero.php'), $enum);

        $this->info('Hero enum has been synced from STRATZ.');

        return self::SUCCESS;
    }

    protected function buildCaseName(string $displayName, int $id): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $displayName);
        $parts = array_filter(explode(' ', trim((string) $normalized)));

        $caseName = '';

        foreach ($parts as $part) {
            $caseName .= ucfirst(strtolower($part));
        }

        if ($caseName === '') {
            return 'Hero'.$id;
        }

        if (is_numeric($caseName[0])) {
            return 'Hero'.$caseName;
        }

        return $caseName;
    }
}
