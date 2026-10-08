<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Providers;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\Contracts\RetentionSource;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PrivacyRepository;
use App\Modules\Privacy\Repositories\EloquentPrivacyRepository;
use App\Modules\Privacy\Services\PersonalDataService;
use App\Modules\Privacy\Services\RetentionJob;

/**
 * Privacy: personal-data rights under Law of Ukraine No. 2297-VI — export and erase (anonymize) a candidate or a
 * former employee across modules (Core\Contracts\PersonalDataProvider), journal, retention rule for rejected
 * candidates (job "privacy.retention", off by default). Routes: /api/privacy/*.
 */
final class PrivacyServiceProvider extends ModuleServiceProvider
{
    /** Export, erase, settings: superadmin, admin. */
    public const string MANAGE = 'privacy-manage';

    protected string $prefix = 'privacy';

    protected string $moduleIcon = 'shield_person';

    protected string $moduleGroup = 'admin';

    /** Every page and route of the module is behind privacy-manage (superadmin, admin). */
    protected ?array $defaultRoles = [UserRole::Superadmin, UserRole::Admin];

    public function register(): void
    {
        $this->app->bind(PrivacyRepository::class, EloquentPrivacyRepository::class);
        $this->app->bind(PersonalDataService::class, fn ($app) => new PersonalDataService($app->tagged(PersonalDataProvider::class), $app->make(PrivacyRepository::class)));
        $this->app->bind(RetentionJob::class, fn ($app) => new RetentionJob($app->tagged(RetentionSource::class), $app->make(PersonalDataService::class), $app->make(PrivacyRepository::class)));
        $this->app->tag([RetentionJob::class], ScheduledJob::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE, [UserRole::Superadmin, UserRole::Admin]);
    }
}
