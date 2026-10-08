<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Error text safe for the console/CI log: no SQL with bindings (Laravel's QueryException message contains them),
 * no quoted cell values from driver messages ("Duplicate entry '<email>'…"), no connection URL or password.
 * Quoted identifiers after "column", "key" or "table" are kept — they tell the operator where to look.
 */
final class SafeError
{
    /** @param  list<string>  $secrets  connection URLs (and their passwords) that must never appear */
    public static function text(Throwable $e, array $secrets = []): string
    {
        $message = $e instanceof QueryException && $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : $e->getMessage();
        $out = '';
        $offset = 0;
        if (preg_match_all("/'[^']*'/", $message, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$quoted, $position]) {
                $before = substr($message, max(0, $position - 12), min(12, $position));
                $keep = preg_match('/(column|key|table)\s*$/i', $before) === 1;
                $out .= substr($message, $offset, $position - $offset).($keep ? $quoted : "'…'");
                $offset = $position + strlen($quoted);
            }
        }
        $message = $out.substr($message, $offset);
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $e::class.': '.$message;
    }
}
