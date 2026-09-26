<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A small attached file kept in the database (base64 in "content", hidden from serialization).
 *
 * @property int $id
 * @property int $document_id
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DocumentFile extends Model
{
    protected $table = 'documents_files';

    protected $fillable = ['document_id', 'filename', 'mime', 'size', 'sha256', 'content'];

    /** @var list<string> */
    protected $hidden = ['content'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
