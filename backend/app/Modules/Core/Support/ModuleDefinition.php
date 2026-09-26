<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * What a domain module tells the rest of the app about itself (see docs/modules/modules-access.md).
 * Declared by each ModuleServiceProvider; the "Модулі" admin page and GET /api/auth/me read it.
 */
final readonly class ModuleDefinition
{
    /**
     * @param  string  $key  stable id, kebab-case of the module folder ("hiring-requests")
     * @param  string  $namespace  PHP namespace prefix with a trailing backslash (maps scheduled jobs to modules)
     * @param  string  $group  sidebar group of the module's pages: recruiting, people, perform, services, admin, core
     * @param  bool  $core  core modules cannot be switched off or restricted by role
     * @param  list<string>  $defaultRoles  roles allowed out of the box = exactly who can open the module today
     */
    public function __construct(
        public string $key,
        public string $namespace,
        public string $icon,
        public string $group,
        public bool $core,
        public array $defaultRoles,
    ) {}

    /** i18n key of the module's display name (frontend dictionaries: modules.names.<key>). */
    public function nameKey(): string
    {
        return 'modules.names.'.$this->key;
    }
}
