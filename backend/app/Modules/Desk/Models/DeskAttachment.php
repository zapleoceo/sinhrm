<?php

declare(strict_types=1);

namespace App\Modules\Desk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A small file attached to a case (base64 in "content", hidden from serialization).
 *
 * @property int $id
 * @property int $case_id
 * @property int|null $uploaded_by
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $content
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
final class DeskAttachment extends Model
{
    protected $table = 'desk_attachments';

    protected $fillable = ['case_id', 'uploaded_by', 'filename', 'mime', 'size', 'sha256', 'content'];

    /** @var list<string> */
    protected $hidden = ['content'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
