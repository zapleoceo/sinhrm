<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single settings row.
 *
 * @property int $id
 * @property list<array{key: string, label: string, type: string, required: bool, options?: list<string>}>|null $form_fields
 * @property list<int>|null $creator_user_ids users allowed to create requests besides admins and managers
 * @property bool $auto_vacancy open the vacancy automatically on final approval
 */
final class HiringSettings extends Model
{
    protected $table = 'hiring_request_settings';

    protected $fillable = ['form_fields', 'creator_user_ids', 'auto_vacancy'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['form_fields' => 'array', 'creator_user_ids' => 'array', 'auto_vacancy' => 'boolean'];
    }
}
