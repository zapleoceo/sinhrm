<?php

declare(strict_types=1);

namespace App\Modules\Documents\Providers;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Documents\Contracts\DocumentRepository;
use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\Contracts\DocumentTemplateRepository;
use App\Modules\Documents\Privacy\DocumentsPersonalData;
use App\Modules\Documents\Repositories\DatabaseDocumentStorage;
use App\Modules\Documents\Repositories\EloquentDocumentRepository;
use App\Modules\Documents\Repositories\EloquentDocumentTemplateRepository;
use App\Modules\Documents\Services\DocumentNavBadges;

/**
 * Documents: templates with variables, employee documents (Markdown rendered to sanitized HTML, small files in the
 * database), acknowledgement "Ознайомлений". Routes at the /api root: documents/*, me/documents.
 */
final class DocumentsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'description';

    protected string $moduleGroup = 'people';

    /** Templates and writing documents: HR staff (superadmin, admin, hr_manager). */
    public const string MANAGE = 'documents-manage';

    public function register(): void
    {
        $this->app->tag([DocumentNavBadges::class], NavBadgeProvider::class);
        // Personal-data export/erase (Privacy module, docs/architecture/secrets.md).
        $this->app->tag([DocumentsPersonalData::class], PersonalDataProvider::class);
        $this->app->bind(DocumentRepository::class, EloquentDocumentRepository::class);
        $this->app->bind(DocumentTemplateRepository::class, EloquentDocumentTemplateRepository::class);
        // Object storage later = another implementation here.
        $this->app->bind(DocumentStorage::class, DatabaseDocumentStorage::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE, UserRole::hrStaff());
    }
}
