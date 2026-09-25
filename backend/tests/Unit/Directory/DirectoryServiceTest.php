<?php

declare(strict_types=1);

namespace Tests\Unit\Directory;

use App\Models\User;
use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Directory\DTO\DictionaryItemData;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Services\DirectoryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DirectoryServiceTest extends TestCase
{
    private DictionaryRepository&MockObject $repo;

    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(DictionaryRepository::class);
        $this->service = new DirectoryService($this->repo, new NullLogger);
    }

    public function test_update_passes_only_sent_fields_and_nothing_sent_is_a_no_op(): void
    {
        $city = (new City)->forceFill(['id' => 7, 'name' => 'Old']);
        $this->repo->expects($this->once())->method('update')
            ->with($city, ['status' => DirectoryStatus::Disabled])->willReturn($city);
        $this->repo->method('find')->willReturn($city);

        $this->service->update($this->actor(), DictionaryType::Cities, $city, new DictionaryItemData(status: DirectoryStatus::Disabled));
        $this->assertSame($city, $this->service->update($this->actor(), DictionaryType::Cities, $city, new DictionaryItemData));
    }

    public function test_city_null_is_sent_explicitly(): void
    {
        $this->assertSame(['city_id' => null], (new DictionaryItemData(cityIdSent: true))->attributes());
        $this->assertSame([], (new DictionaryItemData)->attributes());
    }

    public function test_find_missing_throws_not_found(): void
    {
        $this->repo->method('find')->willReturn(null);
        $this->expectException(ModelNotFoundException::class);

        $this->service->find(DictionaryType::Positions, 1);
    }

    private function actor(): User
    {
        return (new User)->forceFill(['id' => 1]);
    }
}
