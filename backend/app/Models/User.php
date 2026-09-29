<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Directory\Models\Branch;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $google_id
 * @property string|null $avatar_url
 * @property UserStatus $status
 * @property string $locale
 * @property Carbon|null $last_login_at
 * @property int|null $invited_by
 * @property bool $approval_emails e-mail me about approvals and decisions ("Мій профіль", default on)
 * @property bool $safe_speak_handler Safe Speak: may read and answer anonymous reports (admins only)
 * @property-read Collection<int, Branch> $branches
 */
#[Fillable(['name', 'email', 'password', 'google_id', 'avatar_url', 'status', 'locale', 'last_login_at', 'invited_by', 'approval_emails'])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class User extends Authenticatable
{
    /** @use HasApiTokens<HasAbilities> a session request carries a TransientToken, a bearer one a PersonalAccessToken */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Spatie roles are defined for the session guard only. */
    protected string $guard_name = 'web';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'locale' => 'uk',
        'safe_speak_handler' => false,
    ];

    /** @var list<string>|null assigned global roles while the user "works as" one of them (null = not narrowed) */
    private ?array $assignedRoleNames = null;

    private ?string $activeRole = null;

    /**
     * "Працювати як": narrow this user instance to ONE of the assigned global roles for the current request.
     * Every Spatie check (hasRole, hasAnyRole, getRoleNames, permissions) reads the loaded `roles` relation, so
     * replacing it with the single active role narrows ALL authorization in one place — it can never grant a role
     * the user does not have. A role that is not (or no longer) assigned is ignored: the user keeps all roles.
     * Contextual roles (hiring manager, interviewer, line manager) come from assignments and are not affected.
     */
    public function actAs(?string $role): void
    {
        $this->setRelation('roles', $this->roles()->get());
        $this->assignedRoleNames = null;
        $this->activeRole = null;
        $assigned = $this->roleNames();
        if ($role === null || ! in_array($role, $assigned, true) || count($assigned) < 2) {
            return;
        }
        $this->assignedRoleNames = $assigned;
        $this->activeRole = $role;
        $this->setRelation('roles', $this->roles->where('name', $role)->values());
    }

    /** The role chosen in "Працювати як", or null when the user works with all assigned roles. */
    public function activeRole(): ?string
    {
        return $this->activeRole;
    }

    /**
     * Global roles the user really has (users admin, last-superadmin guard, the role switcher).
     *
     * @return list<string>
     */
    public function assignedRoles(): array
    {
        return $this->assignedRoleNames ?? $this->roleNames();
    }

    /**
     * Roles authorization works with: the active role when set, otherwise all assigned roles.
     *
     * @return list<string>
     */
    public function effectiveRoles(): array
    {
        return $this->roleNames();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Branches the user works in (Directory module). Scoping rules: Directory\Contracts\AccessibleBranches.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')->withTimestamps();
    }

    /** @return list<string> */
    private function roleNames(): array
    {
        return array_values(array_map('strval', $this->getRoleNames()->all()));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'safe_speak_handler' => 'boolean',
            'approval_emails' => 'boolean',
        ];
    }
}
