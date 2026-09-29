<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The user's column order on the /candidates board of one vacancy: ["stage:3", "col:7", "stage:4", ...].
 *
 * @property int $id
 * @property int $user_id
 * @property int $vacancy_id
 * @property list<string> $keys
 */
final class BoardLayout extends Model
{
    protected $table = 'candidate_board_layouts';

    protected $fillable = ['user_id', 'vacancy_id', 'keys'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['keys' => 'array'];
    }
}
