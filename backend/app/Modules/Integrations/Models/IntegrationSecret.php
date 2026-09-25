<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Encrypted with APP_KEY (Eloquent "encrypted" cast). Read only through SecretVault.
 *
 * @property int $id
 * @property int $integration_id
 * @property string $name
 * @property string $value decrypted on access
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class IntegrationSecret extends Model
{
    protected $table = 'integration_secrets';

    protected $fillable = ['integration_id', 'name', 'value', 'updated_by'];

    /** Never serialized, even by accident. */
    protected $hidden = ['value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
