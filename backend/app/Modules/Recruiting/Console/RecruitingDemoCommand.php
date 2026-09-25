<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Console;

use App\Modules\Recruiting\Services\RecruitingDemoData;
use Illuminate\Console\Command;

/** Thin wrapper over RecruitingDemoData for local use. Never runs in production; runs once per DB. */
final class RecruitingDemoCommand extends Command
{
    protected $signature = 'recruiting:demo';

    protected $description = 'Synthetic demo data for Recruiting (preview only; refused in production)';

    public function handle(RecruitingDemoData $demo): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('recruiting:demo is not allowed in production.');

            return self::FAILURE;
        }
        $this->info('recruiting:demo: '.$demo->generate()->summary());

        return self::SUCCESS;
    }
}
