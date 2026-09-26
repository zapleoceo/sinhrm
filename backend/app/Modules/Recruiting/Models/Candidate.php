<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Models\User;
use App\Modules\Directory\Models\City;
use App\Modules\Recruiting\Database\Factories\CandidateFactory;
use App\Modules\Recruiting\Enums\AddedVia;
use App\Modules\Recruiting\Enums\CandidateSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $full_name
 * @property string|null $phone E.164
 * @property string|null $email lowercase
 * @property string|null $telegram_username lowercase, without "@"
 * @property int|null $city_id
 * @property CandidateSource $source
 * @property int|null $channel_id acquisition channel (tz3)
 * @property AddedVia|null $added_via how the record was added
 * @property array<string, string>|null $utm
 * @property list<string>|null $tags
 * @property int|null $owner_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read City|null $city
 * @property-read AcquisitionChannel|null $channel
 * @property-read User|null $owner
 * @property-read Collection<int, Application> $applications
 * @property-read Collection<int, Touchpoint> $touchpoints
 */
final class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use HasFactory;

    protected $fillable = [
        'full_name', 'phone', 'email', 'telegram_username', 'city_id', 'source', 'channel_id', 'added_via', 'utm', 'tags',
        'owner_id', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['source' => 'manual'];

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<AcquisitionChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(AcquisitionChannel::class, 'channel_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return HasMany<Touchpoint, $this> */
    public function touchpoints(): HasMany
    {
        return $this->hasMany(Touchpoint::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['source' => CandidateSource::class, 'added_via' => AddedVia::class, 'utm' => 'array', 'tags' => 'array'];
    }

    protected static function newFactory(): CandidateFactory
    {
        return CandidateFactory::new();
    }
}
