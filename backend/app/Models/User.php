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
 * @property bool $safe_speak_handler Safe Speak: may read and answer anonymous reports (admins only)
 * @property-read Collection<int, Branch> $branches
 */
#[Fillable(['name', 'email', 'password', 'google_id', 'avatar_url', 'status', 'locale', 'last_login_at', 'invited_by'])]
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
        ];
    }
}
