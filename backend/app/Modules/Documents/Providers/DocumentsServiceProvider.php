<?php

declare(strict_types=1);

namespace App\Modules\Documents\Providers;

use App\Models\User;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Documents\Contracts\DocumentRepository;
use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\Contracts\DocumentTemplateRepository;
use App\Modules\Documents\Repositories\DatabaseDocumentStorage;
use App\Modules\Documents\Repositories\EloquentDocumentRepository;
use App\Modules\Documents\Repositories\EloquentDocumentTemplateRepository;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Support\Facades\Gate;

/**
 * Documents: templates with variables, employee documents (Markdown rendered to sanitized HTML, small files in the
 * database), acknowledgement "Ознайомлений". Routes at the /api root: documents/*, me/documents.
 */
final class DocumentsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'description';

    protected string $moduleGroup = 'people';

    /** Templates and writing documents: superadmin, admin (HR). */
    public const string MANAGE = 'documents-manage';

    public function register(): void
    {
        $this->app->bind(DocumentRepository::class, EloquentDocumentRepository::class);
        $this->app->bind(DocumentTemplateRepository::class, EloquentDocumentTemplateRepository::class);
        // Object storage later = another implementation here.
        $this->app->bind(DocumentStorage::class, DatabaseDocumentStorage::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));
    }
}
