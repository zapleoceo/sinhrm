<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $report_id
 * @property string $author reporter | handler
 * @property int|null $handler_id
 * @property string $body
 * @property Carbon $created_on
 */
final class SafeSpeakMessage extends Model
{
    public const string REPORTER = 'reporter';

    public const string HANDLER = 'handler';

    public $timestamps = false;

    protected $table = 'safe_speak_messages';

    protected $fillable = ['report_id', 'author', 'handler_id', 'body', 'created_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_on' => 'date'];
    }
}
