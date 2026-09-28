<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Support\ToolEmulation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ToolEmulation::unwrap tolerates providers that ignore json_schema (prod 28.09: finish "stop", not the protocol JSON). */
final class ToolEmulationTest extends TestCase
{
    public function test_protocol_json_becomes_text_and_tool_calls(): void
    {
        $out = ToolEmulation::unwrap(AiResult::done('{"say":"","calls":[{"name":"api_get","arguments":"{\"path\":\"candidates\"}"}]}'));

        $this->assertSame('', $out->text);
        $this->assertSame('api_get', $out->toolCalls[0]['name']);
        $this->assertSame('{"path":"candidates"}', $out->toolCalls[0]['arguments']);
        $this->assertStringStartsWith('call_', $out->toolCalls[0]['id']);
    }

    public function test_plain_prose_is_a_final_answer(): void
    {
        $out = ToolEmulation::unwrap(AiResult::done("Активних кандидатів: **125**.\n"));

        $this->assertSame('Активних кандидатів: **125**.', $out->text);
        $this->assertSame([], $out->toolCalls);
    }

    public function test_prose_quoting_json_stays_prose_and_never_becomes_a_tool_call(): void
    {
        $answer = 'Кандидат: {"name": "Іван Петров", "stage": "offer"} — на етапі оферу.';
        $out = ToolEmulation::unwrap(AiResult::done($answer));

        $this->assertSame($answer, $out->text);
        $this->assertSame([], $out->toolCalls);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function variants(): iterable
    {
        yield 'say only' => ['{"say":"Привіт"}', 'Привіт', []];
        yield 'text synonym' => ['{"text":"Привіт","calls":[]}', 'Привіт', []];
        yield 'fenced json' => ["```json\n{\"say\":\"Ок\",\"calls\":[]}\n```", 'Ок', []];
        yield 'tool_calls + args object' => ['{"tool_calls":[{"tool":"find_endpoints","args":{"query":"vacancy"}}]}', '', ['find_endpoints']];
        yield 'single call at top level' => ['{"name":"open_page","arguments":{"path":"/timeoff"}}', '', ['open_page']];
    }

    /** @param list<string> $names */
    #[DataProvider('variants')]
    public function test_common_variants_are_accepted(string $answer, string $say, array $names): void
    {
        $out = ToolEmulation::unwrap(AiResult::done($answer));

        $this->assertSame($say, $out->text);
        $this->assertSame($names, array_column($out->toolCalls, 'name'));
        foreach ($out->toolCalls as $call) {
            $this->assertIsArray(json_decode($call['arguments'], true));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function broken(): iterable
    {
        yield 'truncated json' => ['{"say": "обріза', 'not_json'];
        yield 'empty' => ['   ', 'not_json'];
        yield 'json without text or calls' => ['{"foo":1}', 'bad_shape'];
        yield 'call without name' => ['{"calls":[{"arguments":"{}"}]}', 'bad_tool_call'];
    }

    #[DataProvider('broken')]
    public function test_unusable_answers_fail_with_a_precise_code(string $answer, string $code): void
    {
        $this->expectException(InvalidAiOutput::class);
        $this->expectExceptionMessage($code);

        ToolEmulation::unwrap(AiResult::done($answer));
    }
}
