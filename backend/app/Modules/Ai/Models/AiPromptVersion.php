<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Modules\Ai\Enums\AiPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An edited prompt version (admin prompt editor): the instruction part only (ROLE/TASK/RULES), never input data.
 *
 * @property int $id
 * @property AiPurpose $purpose
 * @property string $version
 * @property string $base_version
 * @property string $body
 * @property int|null $author_id
 * @property bool $is_active
 * @property int|null $activated_by
 * @property Carbon|null $activated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiPromptVersion extends Model
{
    protected $table = 'ai_prompt_versions';

    protected $fillable = ['purpose', 'version', 'base_version', 'body', 'author_id', 'is_active', 'activated_by', 'activated_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => AiPurpose::class,
            'author_id' => 'integer',
            'activated_by' => 'integer',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }
}
