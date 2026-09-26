<?php

declare(strict_types=1);

namespace App\Modules\Directory\Http\Resources;

use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\DictionaryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DictionaryItem */
final class DictionaryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        $item = $this->resource;
        if ($item instanceof Branch) {
            $city = $item->city;
            $data['city_id'] = $item->city_id;
            $data['city'] = $city === null ? null : ['id' => $city->id, 'name' => $city->name];
        }

        return $data;
    }
}
