<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a user filed an application on his personal board (at most one personal column per user and application).
 *
 * @property int $id
 * @property int $user_id
 * @property int $application_id
 * @property int $column_id
 */
final class BoardCard extends Model
{
    protected $table = 'candidate_board_cards';

    protected $fillable = ['user_id', 'application_id', 'column_id'];
}
