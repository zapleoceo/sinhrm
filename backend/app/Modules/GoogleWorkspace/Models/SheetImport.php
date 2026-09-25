<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $spreadsheet_id
 * @property string $sheet
 * @property list<string> $headers
 * @property array<string, int> $mapping field → column index
 * @property int $last_row
 * @property bool $auto_sync
 * @property int|null $created_by
 * @property Carbon|null $last_synced_at
 * @property array<string, mixed>|null $last_report
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SheetImport extends Model
{
    protected $fillable = [
        'spreadsheet_id', 'sheet', 'headers', 'mapping', 'last_row', 'auto_sync', 'created_by', 'last_synced_at', 'last_report',
    ];

    /** @var array<string, mixed> same as the DB defaults, so a just-created row has them in memory */
    protected $attributes = ['sheet' => '', 'last_row' => 1, 'auto_sync' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'last_row' => 'integer',
            'auto_sync' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_report' => 'array',
        ];
    }
}
