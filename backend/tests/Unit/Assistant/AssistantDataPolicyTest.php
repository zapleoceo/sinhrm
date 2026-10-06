<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Support\AssistantDataPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantDataPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function paths(): iterable
    {
        foreach (['auth/me', 'people', 'people/12', 'me/employee', 'people/search', 'people/lookup', 'candidates', 'candidates/7', 'vacancies', 'vacancies/3', 'tasks', 'tasks/9', 'pipelines'] as $path) {
            yield $path => [$path, $path];
        }
        yield 'query stays local' => ['/api/people?q=%53ENTINEL', 'people'];
        foreach (['people/0', 'people/-1', 'people/1.5', 'people/private-name', 'people/%31', 'people/1/compensation', 'people/1/history', 'people/org-chart', 'me/employee/compensation', 'users', 'integrations', 'privacy', 'documents', 'timeoff/balances', 'perform/reviews', 'pulse', 'safe-speak', 'unknown', 'pipelines/1', 'candidates/1/timeline'] as $path) {
            yield $path => [$path, null];
        }
    }

    #[DataProvider('paths')]
    public function test_read_matrix_is_closed(string $path, ?string $expected): void
    {
        $this->assertSame($expected, AssistantDataPolicy::readPath($path));
    }

    public function test_only_integer_fields_at_explicit_resource_positions_survive(): void
    {
        $source = ['data' => [[
            'id' => 5, 'owner_id' => '6', 'city_id' => 6.0, 'channel_id' => true,
            'full_name' => 'SENTINEL', 'tags' => ['id' => 999], 'custom_fields' => ['id' => 998],
            'owner' => ['id' => 7, 'name' => 'SENTINEL'], 'city' => ['id' => ['id' => 8]],
            'applications' => [[
                'id' => 10, 'candidate_id' => 5, 'vacancy_id' => 20, 'stage_id' => 30,
                'reject_reason_id' => -1, 'rejected_note' => 'SENTINEL', 'status' => 'SENTINEL',
                'vacancy' => ['id' => 20, 'title' => 'SENTINEL'],
                'stage' => ['id' => 30, 'name' => 'SENTINEL'],
                'stages' => [['id' => 30, 'position' => 1, 'name' => 'SENTINEL']],
                'route' => [['stage_id' => 999]],
            ]],
        ]], 'meta' => ['total' => 0, 'current_page' => 1, 'per_page' => '20', 'last_page' => -1, 'links' => [['url' => 'SENTINEL']]], 'links' => ['next' => 'SENTINEL']];
        $this->assertSame(['data' => [[
            'id' => 5, 'city' => [], 'owner' => ['id' => 7], 'applications' => [[
                'id' => 10, 'candidate_id' => 5, 'vacancy_id' => 20, 'stage_id' => 30,
                'vacancy' => ['id' => 20], 'stage' => ['id' => 30], 'stages' => [['id' => 30]],
            ]],
        ]], 'meta' => ['current_page' => 1, 'total' => 0]], AssistantDataPolicy::project('candidates', $source));
    }

    public function test_each_schema_keeps_its_own_references_without_generic_recursion(): void
    {
        $this->assertSame(['id' => 1], AssistantDataPolicy::project('auth/me', ['id' => 1, 'name' => 'SENTINEL', 'branches' => [['id' => 2]]]));
        $this->assertSame(['data' => ['id' => 1, 'manager_id' => 2, 'manager' => ['id' => 2]]], AssistantDataPolicy::project('people/1', ['data' => ['id' => 1, 'manager_id' => 2, 'manager' => ['id' => 2, 'full_name' => 'SENTINEL'], 'compensation' => ['id' => 999]]]));
        $this->assertSame(['data' => [['id' => 1, 'pipeline_id' => 2, 'stages' => [['id' => 3]]]]], AssistantDataPolicy::project('vacancies', ['data' => [['id' => 1, 'pipeline_id' => 2, 'salary_min' => 100000, 'applications_count' => 9, 'stages' => [['id' => 3, 'name' => 'SENTINEL']]]]]));
        $this->assertSame(['data' => ['id' => 1, 'assignee_id' => 2, 'employee' => ['id' => 3]]], AssistantDataPolicy::project('tasks/1', ['data' => ['id' => 1, 'assignee_id' => 2, 'employee' => ['id' => 3, 'name' => 'SENTINEL'], 'due_at' => 'SENTINEL']]));
        $this->assertSame(['data' => [['id' => 1, 'stages' => [['id' => 2]]]]], AssistantDataPolicy::project('pipelines', ['data' => [['id' => 1, 'name' => 'SENTINEL', 'stages' => [['id' => 2, 'kind' => 'SENTINEL']]]]]));
        $this->assertSame([], AssistantDataPolicy::project('people/1/compensation', ['data' => ['id' => 999]]));
    }

    public function test_oversized_encoded_and_nested_strings_are_discarded_before_size_limits(): void
    {
        $source = ['data' => [['id' => 1, 'manager_id' => 'SENTINEL', 'custom_fields' => ['id' => 123], 'address' => str_repeat('SENTINEL%53&#83;\\u0053', 4000)]]];
        $projected = AssistantDataPolicy::project('people', $source);
        $this->assertSame(['data' => [['id' => 1]]], $projected);
        $this->assertGreaterThan(80000, strlen((string) json_encode($source)));
        $this->assertSame(19, strlen((string) json_encode($projected)));
        $this->assertStringNotContainsString('SENTINEL', (string) json_encode($projected));
    }

    public function test_page_context_never_echoes_arbitrary_segments_or_queries(): void
    {
        $this->assertSame('/people/12', AssistantDataPolicy::pagePath('/people/12?q=SENTINEL'));
        $this->assertSame('/admin/integrations', AssistantDataPolicy::pagePath('/admin/integrations?email=SENTINEL'));
        foreach (['/people/private-name', '//SENTINEL', '/people/%31', '/unknown/SENTINEL', '/people/12#SENTINEL'] as $path) {
            $this->assertSame('/', AssistantDataPolicy::pagePath($path));
        }
    }
}
