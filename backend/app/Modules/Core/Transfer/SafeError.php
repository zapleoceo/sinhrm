<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Error text safe for the console/CI log: no SQL with bindings (Laravel's QueryException message contains them), no
 * quoted fragments of the driver message at all — everything from the first to the last quote (single or double) is
 * replaced by '…': cell values ("Duplicate entry 'O'Brien@x'…", apostrophes included), logins and hosts
 * ('user "app"', 'server at "db"'). The SQLSTATE/error code and the unquoted text stay; connection URLs and passwords
 * are masked as well.
 */
final class SafeError
{
    /** @param  list<string>  $secrets  connection URLs (and their passwords) that must never appear */
    public static function text(Throwable $e, array $secrets = []): string
    {
        $message = $e instanceof QueryException && $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : $e->getMessage();
        $first = strcspn($message, "'\"");
        if ($first < strlen($message)) {
            $single = strrpos($message, "'");
            $double = strrpos($message, '"');
            $last = max($single === false ? -1 : $single, $double === false ? -1 : $double);
            $message = substr($message, 0, $first)."'…'".($last > $first ? substr($message, $last + 1) : '');
        }
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $e::class.': '.$message;
    }
}
