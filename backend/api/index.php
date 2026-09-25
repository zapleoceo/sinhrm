<?php

declare(strict_types=1);

// Vercel serverless entry point (vercel-php runtime).

// Stack traces without argument values: a secret passed as a function argument (token, decrypted value)
// never reaches a log or an error report through a trace. See docs/architecture/secrets.md.
ini_set('zend.exception_ignore_args', '1');

// The runtime serves this file as the router script, so PHP reports SCRIPT_NAME=/api/index.php and
// Symfony would treat "/api" as the base path (/api/health → /health). Pretend we are public/index.php.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';

require __DIR__.'/../public/index.php';
