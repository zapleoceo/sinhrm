<?php

declare(strict_types=1);

namespace App\Modules\Directory\Support;

use App\Modules\Directory\DTO\ImportedItem;
use App\Modules\Directory\DTO\MappedPayload;
use App\Modules\Directory\Enums\DirectoryStatus;

/**
 * Tolerant reader of a Sintegrum "<resource>/list" response.
 *
 * UNVERIFIED against the live API. The Sintegrum apidoc shows a top-level array of {id, name, status}
 * (status 1 = enabled); the importer also accepts the same list under "data" or "items".
 * Items without a usable id or name are counted as invalid (reported as skipped), never guessed.
 * A missing status means active; 1 / "1" / true / "active" / "enabled" mean active, anything else disabled.
 */
final class SintegrumPayloadMapper
{
    private const int MAX_EXTERNAL_ID_LENGTH = 64;

    private const int MAX_NAME_LENGTH = 255;

    /** null = the shape is not recognised (not a list and no list under data/items). */
    public function map(mixed $json): ?MappedPayload
    {
        $rows = $this->rows($json);
        if ($rows === null) {
            return null;
        }

        $items = [];
        $invalid = 0;
        foreach ($rows as $row) {
            $item = is_array($row) ? $this->item($row) : null;
            if ($item === null) {
                $invalid++;

                continue;
            }
            $items[] = $item;
        }

        return new MappedPayload($items, $invalid);
    }

    /** @return list<mixed>|null */
    private function rows(mixed $json): ?array
    {
        if (! is_array($json)) {
            return null;
        }
        if (array_is_list($json)) {
            return $json;
        }
        foreach (['data', 'items'] as $key) {
            if (isset($json[$key]) && is_array($json[$key]) && array_is_list($json[$key])) {
                return $json[$key];
            }
        }

        return null;
    }

    /** @param  array<mixed>  $row */
    private function item(array $row): ?ImportedItem
    {
        $id = self::scalarId($row['id'] ?? null);
        $name = $row['name'] ?? null;
        if ($id === null || ! is_string($name) || trim($name) === '') {
            return null;
        }

        return new ImportedItem(
            externalId: $id,
            name: mb_substr(trim($name), 0, self::MAX_NAME_LENGTH),
            status: self::status($row['status'] ?? null),
            cityExternalId: self::scalarId($row['city_id'] ?? null),
        );
    }

    private static function scalarId(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_string($value) && trim($value) !== '' && mb_strlen(trim($value)) <= self::MAX_EXTERNAL_ID_LENGTH) {
            return trim($value);
        }

        return null;
    }

    private static function status(mixed $value): DirectoryStatus
    {
        if ($value === null) {
            return DirectoryStatus::Active;
        }
        $active = $value === true || $value === 1
            || (is_string($value) && in_array(mb_strtolower(trim($value)), ['1', 'active', 'enabled'], true));

        return $active ? DirectoryStatus::Active : DirectoryStatus::Disabled;
    }
}
