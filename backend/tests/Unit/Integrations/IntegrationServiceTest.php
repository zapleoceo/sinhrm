<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Models\User;
use App\Modules\Integrations\Contracts\ConnectionChecker;
use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Definitions\AbstractDefinition;
use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationGroup;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Services\IntegrationConfigLoader;
use App\Modules\Integrations\Services\IntegrationService;
use App\Modules\Integrations\Support\IntegrationRegistry;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class IntegrationServiceTest extends TestCase
{
    private const string SECRET = 'fake-leaky-secret-7777';

    private IntegrationRepository&MockObject $repo;

    private SecretVault&MockObject $vault;

    private Integration $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->row = new Integration(['key' => 'probe']);
        $this->row->setRawAttributes(['key' => 'probe', 'status' => 'off', 'settings' => '{}']);
        $this->repo = $this->createMock(IntegrationRepository::class);
        $this->repo->method('findOrCreate')->willReturn($this->row);
        $this->repo->method('find')->willReturn($this->row);
        $this->vault = $this->createMock(SecretVault::class);
        $this->vault->method('describe')->willReturn([]);
    }

    public function test_check_scrubs_secret_values_from_checker_messages_and_logs(): void
    {
        $this->vault->method('get')->willReturn(self::SECRET);
        $definition = $this->checkable(CheckResult::error('failed calling https://x.test/bot'.self::SECRET.'/getMe'));

        $this->repo->expects($this->once())->method('log')->with(
            $this->row,
            LogLevel::Error,
            'check_error',
            $this->callback(fn (array $ctx): bool => ! str_contains((string) json_encode($ctx), self::SECRET)),
        );

        $view = $this->service($definition)->check($this->user(), $definition);

        $this->assertSame(IntegrationStatus::Error, $view->status);
        $this->assertSame('failed calling https://x.test/bot***/getMe', $view->lastError);
    }

    public function test_check_without_required_secret_does_not_call_the_checker(): void
    {
        $this->vault->method('get')->willReturn(null);
        $definition = $this->checkable(CheckResult::connected(), mustNotRun: true);

        $view = $this->service($definition)->check($this->user(), $definition);

        $this->assertSame('missing_secret:token', $view->lastError);
    }

    public function test_check_on_definition_without_checker_throws_422(): void
    {
        $definition = new class extends AbstractDefinition
        {
            public function key(): string
            {
                return 'plain';
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

        try {
            $this->service($definition)->check($this->user(), $definition);
            $this->fail('expected exception');
        } catch (IntegrationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('check_not_supported', $e->errorCode);
        }
    }

    public function test_update_treats_empty_string_as_unchanged_and_null_as_delete(): void
    {
        $definition = $this->checkable(CheckResult::connected());
        $this->vault->expects($this->never())->method('put');
        $this->vault->expects($this->once())->method('forget')->with('probe', 'token');
        $this->repo->expects($this->once())->method('log')->with(
            $this->row, LogLevel::Info, 'settings_updated',
            $this->callback(fn (array $ctx): bool => $ctx['secrets_cleared'] === ['token'] && $ctx['secrets_set'] === []),
        );

        $this->service($definition)->update($this->user(), $definition, [], ['token' => '']);
        $this->service($definition)->update($this->user(), $definition, [], ['token' => null]);
    }

    public function test_update_without_changes_writes_no_log(): void
    {
        $definition = $this->checkable(CheckResult::connected());
        $this->repo->expects($this->never())->method('log');

        $this->service($definition)->update($this->user(), $definition, ['region' => null], []);
    }

    private function service(AbstractDefinition $definition): IntegrationService
    {
        return new IntegrationService(new IntegrationRegistry([$definition]), $this->repo, $this->vault, new IntegrationConfigLoader($this->repo, $this->vault));
    }

    private function checkable(CheckResult $result, bool $mustNotRun = false): AbstractDefinition
    {
        return new class($result, $mustNotRun) extends AbstractDefinition implements ConnectionChecker
        {
            public function __construct(private readonly CheckResult $result, private readonly bool $mustNotRun) {}

            public function key(): string
            {
                return 'probe';
            }

            public function group(): IntegrationGroup
            {
                return IntegrationGroup::Ai;
            }

            public function fields(): array
            {
                return [FieldSpec::text('region'), FieldSpec::secret('token')];
            }

            public function check(IntegrationConfig $config): CheckResult
            {
                if ($this->mustNotRun) {
                    throw new LogicException('checker must not run');
                }

                return $this->result;
            }
        };
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 1;

        return $user;
    }
}
