<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Enums;

/** Question kinds: rating scales 1-5 / 1-10, eNPS 0-10, single / multiple choice, free text. */
enum QuestionType: string
{
    case Scale5 = 'scale5';
    case Scale10 = 'scale10';
    case Enps = 'enps';
    case Single = 'single';
    case Multi = 'multi';
    case Text = 'text';

    /** Numeric questions have an average and are compared wave over wave. */
    public function isNumeric(): bool
    {
        return in_array($this, [self::Scale5, self::Scale10, self::Enps], true);
    }

    /** @return array{0: int, 1: int}|null inclusive value range of numeric questions */
    public function range(): ?array
    {
        return match ($this) {
            self::Scale5 => [1, 5],
            self::Scale10 => [1, 10],
            self::Enps => [0, 10],
            default => null,
        };
    }
}
