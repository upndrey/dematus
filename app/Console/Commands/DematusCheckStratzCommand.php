<?php

namespace App\Console\Commands;

use App\Services\Stratz\StratzService;
use Illuminate\Console\Command;
use Throwable;

class DematusCheckStratzCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dematus:check-stratz {--match-id=8893106015 : Match ID used for the connectivity check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check that Dematus can reach the STRATZ API from this runtime.';

    /**
     * Execute the console command.
     */
    public function handle(StratzService $stratzService): int
    {
        try {
            $matchId = (int) $this->option('match-id');

            $stratzService->getMatchById($matchId);

            $this->info('STRATZ OK: Dematus can reach https://api.stratz.com/graphql');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('STRATZ ERROR: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }
}
