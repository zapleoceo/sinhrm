<?php

declare(strict_types=1);

namespace Tests\Unit\Directory;

use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Support\SintegrumPayloadMapper;
use PHPUnit\Framework\TestCase;

final class SintegrumPayloadMapperTest extends TestCase
{
    public function test_reads_top_level_list_data_and_items(): void
    {
        $mapper = new SintegrumPayloadMapper;
        $row = ['id' => 5, 'name' => ' Five ', 'status' => 1];

        foreach ([[$row], ['data' => [$row]], ['items' => [$row]]] as $json) {
            $payload = $mapper->map($json);
            $this->assertNotNull($payload);
            $this->assertCount(1, $payload->items);
            $this->assertSame('5', $payload->items[0]->externalId);
            $this->assertSame('Five', $payload->items[0]->name);
        }
        $this->assertSame([], $mapper->map([])?->items);
    }

    public function test_unknown_shapes_are_null(): void
    {
        $mapper = new SintegrumPayloadMapper;
        foreach ([null, 'text', 42, ['data' => ['id' => 1]], ['result' => []]] as $json) {
            $this->assertNull($mapper->map($json));
        }
    }

    public function test_invalid_rows_are_counted_not_guessed(): void
    {
        $payload = (new SintegrumPayloadMapper)->map([
            ['id' => 1, 'name' => 'Ok'],
            ['id' => 0, 'name' => 'Zero id'],
            ['id' => 2, 'name' => '  '],
            ['id' => str_repeat('9', 65), 'name' => 'Too long id'],
            ['id' => 3.5, 'name' => 'Float id'],
            'not an object',
        ]);

        $this->assertNotNull($payload);
        $this->assertCount(1, $payload->items);
        $this->assertSame(5, $payload->invalid);
    }

    public function test_status_mapping_and_city_reference(): void
    {
        $payload = (new SintegrumPayloadMapper)->map([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b', 'status' => '1'],
            ['id' => 3, 'name' => 'c', 'status' => 'Enabled'],
            ['id' => 4, 'name' => 'd', 'status' => 0],
            ['id' => 5, 'name' => 'e', 'status' => 'disabled', 'city_id' => 7],
        ]);

        $this->assertNotNull($payload);
        $statuses = array_map(static fn ($i) => $i->status, $payload->items);
        $this->assertSame([
            DirectoryStatus::Active, DirectoryStatus::Active, DirectoryStatus::Active,
            DirectoryStatus::Disabled, DirectoryStatus::Disabled,
        ], $statuses);
        $this->assertSame('7', $payload->items[4]->cityExternalId);
        $this->assertNull($payload->items[0]->cityExternalId);
    }
}
