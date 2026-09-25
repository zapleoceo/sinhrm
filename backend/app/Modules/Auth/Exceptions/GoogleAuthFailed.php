<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

/** OAuth exchange failed (invalid state, consent denied, Google error). The message never holds tokens. */
final class GoogleAuthFailed extends RuntimeException {}
