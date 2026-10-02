<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\People\DTO\PersonOption;
use App\Modules\People\Http\Requests\LookupPeopleRequest;
use App\Modules\People\Http\Requests\SearchPeopleRequest;
use App\Modules\People\Services\PersonPicker;
use Illuminate\Http\JsonResponse;

/** Person picker: search by name and id → name. Rows carry directory-level data only (PersonOption). */
final class PersonPickerController
{
    use ResolvesActor;

    public function __construct(private readonly PersonPicker $picker) {}

    public function search(SearchPeopleRequest $request): JsonResponse
    {
        return $this->rows($this->picker->search(
            $this->actor($request),
            $request->scope(),
            $request->term(),
            $request->limit(),
            $request->includeTerminated(),
        ));
    }

    public function lookup(LookupPeopleRequest $request): JsonResponse
    {
        return $this->rows($this->picker->lookup($this->actor($request), $request->scope(), $request->ids()));
    }

    /** @param  list<PersonOption>  $rows */
    private function rows(array $rows): JsonResponse
    {
        return new JsonResponse(['data' => array_map(static fn (PersonOption $o): array => $o->toArray(), $rows)]);
    }
}
