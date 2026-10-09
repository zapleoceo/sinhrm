<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\TimeOff\Exceptions\TimeOffException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BusinessRuleExceptionTest extends TestCase
{
    public function test_renders_code_as_message_with_status(): void
    {
        $e = PeopleException::noEmployee();

        self::assertInstanceOf(BusinessRuleException::class, $e);
        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame('no_employee', $e->getMessage());
        self::assertSame(404, $e->render()->getStatusCode());
        self::assertSame(['message' => 'no_employee', 'code' => 'no_employee'], $e->render()->getData(true));
    }

    public function test_extra_fields_follow_message_and_code(): void
    {
        $e = TimeOffException::insufficientBalance(2.5, 4.5);
        $body = $e->render()->getData(true);

        self::assertSame(422, $e->status);
        self::assertSame(['message', 'code'], array_slice(array_keys($body), 0, 2));
        self::assertSame('insufficient_balance', $body['code']);
        self::assertSame($e->extra, array_diff_key($body, ['message' => 1, 'code' => 1]));
        self::assertNotSame([], $e->extra);
    }
}
