<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An employee's document: generated from a template (Markdown) and/or an attached file.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $template_id
 * @property string $title
 * @property string|null $category
 * @property DocumentStatus $status
 * @property string|null $content_md
 * @property string|null $file_path
 * @property string|null $reject_reason
 * @property int|null $created_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read DocumentFile|null $file
 * @property-read Collection<int, Signature> $signatures
 */
final class Document extends Model
{
    protected $fillable = [
        'employee_id', 'template_id', 'title', 'category', 'status', 'content_md', 'file_path', 'reject_reason',
        'created_by', 'sent_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Metadata of the attached file; the (large) content column is not selected.
     *
     * @return HasOne<DocumentFile, $this>
     */
    public function file(): HasOne
    {
        return $this->hasOne(DocumentFile::class)->select(['id', 'document_id', 'filename', 'mime', 'size', 'sha256']);
    }

    /** @return HasMany<Signature, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class)->orderBy('signed_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => DocumentStatus::class, 'sent_at' => 'datetime'];
    }
}
