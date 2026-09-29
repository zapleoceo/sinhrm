<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Saved values of the vacancy form («Зберегти шаблон»), shared by recruiting writers; «Створити з шаблону» prefills a
 * new vacancy. Only the keys of SaveVacancyTemplateRequest::KEYS are kept (no branch, recruiter, status, publication).
 *
 * @property int $id
 * @property string $name
 * @property array<string, mixed> $data
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VacancyTemplate extends Model
{
    protected $fillable = ['name', 'data', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
