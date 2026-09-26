<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single settings row.
 *
 * @property int $id
 * @property int|null $retention_rejected_months auto-anonymize rejected candidates after N months; null = off
 */
final class PrivacySettings extends Model
{
    protected $table = 'privacy_settings';

    protected $fillable = ['retention_rejected_months'];

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrCreate([]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['retention_rejected_months' => 'integer'];
    }
}
