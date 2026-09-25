<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Console;

use App\Modules\Recruiting\Services\DemoDataGenerator;
use Illuminate\Console\Command;

/** Fills a preview/local DB with synthetic recruiting data. Never runs in production; runs once per DB. */
final class RecruitingDemoCommand extends Command
{
    protected $signature = 'recruiting:demo';

    protected $description = 'Synthetic demo data for Recruiting (preview only; refused in production)';

    public function handle(DemoDataGenerator $generator): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('recruiting:demo is not allowed in production.');

            return self::FAILURE;
        }
        if ($generator->alreadyGenerated()) {
            $this->info('recruiting:demo: demo data already present, nothing to do.');

            return self::SUCCESS;
        }
        $counts = $generator->generate();
        $this->info('recruiting:demo: '.implode(', ', array_map(
            static fn (string $k, int $v): string => "$k=$v",
            array_keys($counts),
            $counts,
        )));

        return self::SUCCESS;
    }
}
