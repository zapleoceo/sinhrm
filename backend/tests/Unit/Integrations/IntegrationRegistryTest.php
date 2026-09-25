<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Modules\Integrations\Contracts\ConnectionChecker;
use App\Modules\Integrations\Definitions\AbstractDefinition;
use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationGroup;
use App\Modules\Integrations\Support\IntegrationRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IntegrationRegistryTest extends TestCase
{
    public function test_finds_definitions_in_registration_order(): void
    {
        $a = $this->definition('a');
        $b = $this->definition('b', checkable: true);
        $registry = new IntegrationRegistry([$a, $b]);

        $this->assertSame([$a, $b], $registry->all());
        $this->assertSame($b, $registry->get('b'));
        $this->assertNull($registry->find('zzz'));
        $this->assertFalse($a->supportsCheck());
        $this->assertTrue($b->supportsCheck());
    }

    public function test_unknown_key_is_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);
        (new IntegrationRegistry([]))->get('missing');
    }

    public function test_duplicate_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IntegrationRegistry([$this->definition('a'), $this->definition('a')]);
    }

    private function definition(string $key, bool $checkable = false): AbstractDefinition
    {
        return $checkable
            ? new class($key) extends AbstractDefinition implements ConnectionChecker
            {
                public function __construct(private readonly string $k) {}

                public function key(): string
                {
                    return $this->k;
                }

                public function group(): IntegrationGroup
                {
                    return IntegrationGroup::Ai;
                }

                public function fields(): array
                {
                    return [FieldSpec::secret('token')];
                }

                public function check(IntegrationConfig $config): CheckResult
                {
                    return CheckResult::connected();
                }
            }
        : new class($key) extends AbstractDefinition
        {
            public function __construct(private readonly string $k) {}

            public function key(): string
            {
                return $this->k;
            }

            public function group(): IntegrationGroup
            {
                return IntegrationGroup::Sources;
            }

            public function fields(): array
            {
                return [];
            }
        };
    }
}
