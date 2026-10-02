<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Responses;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/** File downloads: always an attachment, never rendered inline on our origin. */
final class Download
{
    /**
     * A stored (uploaded) file: an RFC 6266 attachment name with an ASCII fallback, no MIME sniffing, no caching.
     * Desk attachments and Documents files.
     */
    public static function file(string $content, string $filename, string $mime, int $size): Response
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'file';

        return new Response($content, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Content-Disposition of a file we name ourselves (ASCII, no quotes): attachment; filename="<name>". */
    public static function disposition(string $filename): string
    {
        return 'attachment; filename="'.$filename.'"';
    }
}
