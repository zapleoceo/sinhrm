<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Parsers;

use App\Modules\MailAgent\Enums\ParserKey;

/**
 * djinni.co. ASSUMED subject "Jane Doe applied to Sales Manager" / "… is interested in …" — not confirmed on real
 * mail; the common rules still apply.
 */
final class DjinniParser extends AbstractMailParser
{
    public function key(): ParserKey
    {
        return ParserKey::Djinni;
    }

    protected function subjectPatterns(): array
    {
        return ['/^(?<n>\p{Lu}[\p{L}\'ʼ’\-]+(?:\s+\p{Lu}[\p{L}\'ʼ’\-]+){1,2})\s+(?:is\s+interested\s+in|applied\s+(?:to|for))\s+(?:your\s+)?(?:job\s+)?[«"“]?(?<v>[^»"”]+?)[»"”]?\s*$/iu'];
    }
}
