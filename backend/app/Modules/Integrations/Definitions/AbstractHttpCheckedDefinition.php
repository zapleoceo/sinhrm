<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Contracts\ConnectionChecker;
use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\Support\OutboundUrlGuard;
use Closure;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * A definition whose "Check connection" is one outgoing HTTP request. probe() keeps the outbound rules in one place:
 * the URL passes OutboundUrlGuard::inspect() first (blocked → its code, nothing is sent) and the request is pinned to
 * the IPs the guard approved (CURLOPT_RESOLVE, no DNS rebinding between check and send), redirects are never
 * followed, 10 s timeout, JSON accepted, and a transport error becomes connection_failed — never the exception text (it may carry
 * the URL with a token). The subclass builds the URL, adds its auth and reads the answer.
 */
abstract class AbstractHttpCheckedDefinition extends AbstractDefinition implements ConnectionChecker
{
    protected const int TIMEOUT_SECONDS = 10;

    public function __construct(private readonly Http $http, private readonly OutboundUrlGuard $guard) {}

    /**
     * @param  Closure(PendingRequest): Response  $send  adds auth/headers and sends to $url
     * @return Response|CheckResult the answer, or the error result when the URL is blocked or the request failed
     */
    protected function probe(string $url, Closure $send): Response|CheckResult
    {
        $target = $this->guard->inspect($url);
        if ($target->error !== null) {
            return CheckResult::error($target->error);
        }

        try {
            return $send($this->http->withOptions($target->httpOptions())->timeout(self::TIMEOUT_SECONDS)->acceptJson());
        } catch (Throwable) {
            // Never the exception text: it may contain the request URL.
            return CheckResult::error('connection_failed');
        }
    }
}
