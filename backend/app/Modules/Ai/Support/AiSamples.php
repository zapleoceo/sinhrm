<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Enums\AiPurpose;

/**
 * Built-in synthetic inputs (app/Modules/Ai/Samples/<purpose>.json) for "Спробувати" in the prompt editor and for
 * showing the effective instruction text. Synthetic only — never real people or messages.
 */
final class AiSamples
{
    /** @return array<string, mixed>|null the fixture-style "input" object */
    public static function input(AiPurpose $purpose): ?array
    {
        $path = dirname(__DIR__).'/Samples/'.$purpose->value.'.json';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $input = is_array($data) ? ($data['input'] ?? null) : null;
        if (! is_array($input)) {
            return null;
        }

        /** @var array<string, mixed> $input */
        return $input;
    }
}
