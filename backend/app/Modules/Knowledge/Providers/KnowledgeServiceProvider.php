<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Providers;

use App\Models\User;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Knowledge\Contracts\KnowledgeRepository;
use App\Modules\Knowledge\Contracts\PublishedArticles;
use App\Modules\Knowledge\Repositories\EloquentKnowledgeRepository;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Support\Facades\Gate;

/** Knowledge base: categories, versioned Markdown articles with an audience, search, votes. Routes: /api/knowledge/*. */
final class KnowledgeServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'menu_book';

    protected string $moduleGroup = 'services';

    /** Categories, articles, drafts, versions: superadmin, admin (HR). */
    public const string MANAGE = 'knowledge-manage';

    protected string $prefix = 'knowledge';

    public function register(): void
    {
        $this->app->bind(KnowledgeRepository::class, EloquentKnowledgeRepository::class);
        $this->app->bind(PublishedArticles::class, EloquentKnowledgeRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));
    }
}
