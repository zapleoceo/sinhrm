<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A personal column of the /candidates board (one user, one vacancy). Not a funnel stage: filing a card here never
 * changes the application's stage.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $scope_vacancy_id
 * @property string $title
 * @property string|null $color
 * @property int $position
 * @property bool $hidden
 */
final class BoardColumn extends Model
{
    protected $table = 'candidate_board_columns';

    protected $fillable = ['user_id', 'scope_vacancy_id', 'title', 'color', 'position', 'hidden'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hidden' => 'boolean', 'position' => 'integer'];
    }
}
