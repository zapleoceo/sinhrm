<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One person's answers in a wave. Anonymous waves: employee_id is always null and the hash cannot be traced back
 * once the wave's salt is wiped. No timestamps (only the day).
 *
 * @property int $id
 * @property int $wave_id
 * @property string $respondent_hash
 * @property int|null $employee_id
 * @property int|null $branch_id
 * @property int|null $department_id
 * @property array<string, int|string|list<int>> $answers
 * @property Carbon $submitted_on
 */
final class SurveyResponse extends Model
{
    public $timestamps = false;

    protected $fillable = ['wave_id', 'respondent_hash', 'employee_id', 'branch_id', 'department_id', 'answers', 'submitted_on'];

    /** @var list<string> */
    protected $hidden = ['respondent_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['answers' => 'array', 'submitted_on' => 'date'];
    }
}
