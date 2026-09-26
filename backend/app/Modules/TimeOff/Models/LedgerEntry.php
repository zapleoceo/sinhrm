<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Models;

use App\Modules\TimeOff\Enums\LedgerReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One movement of a leave balance (append-only).
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property string $delta decimal
 * @property LedgerReason $reason
 * @property int|null $reference_id
 * @property string|null $period
 * @property string|null $comment
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
final class LedgerEntry extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'leave_balance_ledger';

    protected $fillable = ['employee_id', 'leave_type_id', 'delta', 'reason', 'reference_id', 'period', 'comment', 'created_by', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reason' => LedgerReason::class, 'delta' => 'decimal:2'];
    }
}
