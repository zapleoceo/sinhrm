<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A rating scale: ascending levels with labels (e.g. 1 "Very weak" … 5 "Very strong").
 *
 * @property int $id
 * @property string $name
 * @property list<array{value: int, label: string}> $levels
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RatingScale extends Model
{
    protected $fillable = ['name', 'levels'];

    /** @return list<int> */
    public function values(): array
    {
        return array_map(static fn (array $l): int => $l['value'], $this->levels);
    }

    public function maxValue(): int
    {
        $values = $this->values();

        return $values === [] ? 0 : max($values);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['levels' => 'array'];
    }
}
