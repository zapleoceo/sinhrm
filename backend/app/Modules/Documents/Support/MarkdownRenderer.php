<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use League\CommonMark\CommonMarkConverter;

/**
 * Markdown → HTML for documents, rendered on the server. Raw HTML in the source is ESCAPED (shown as text, never
 * executed) and unsafe links (javascript:, vbscript:, file:, data: except images) are dropped, so neither a template
 * nor an employee's name inserted into it can inject markup into the browser.
 */
final class MarkdownRenderer
{
    public static function toHtml(string $markdown): string
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        return (string) $converter->convert($markdown);
    }
}
