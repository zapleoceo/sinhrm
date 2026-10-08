<?php

declare(strict_types=1);

// CI-only, synthetic data. This deliberately has no connection-string or production mode.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_URL') || getenv('DATABASE_URL')
    || ! in_array($argv[1] ?? null, ['forward', 'replay-candidate'], true)) {
    fwrite(STDERR, "Synthetic CI transfer only.\n");
    exit(1);
}

$source = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=app_transfer_source', 'app', 'app');
$target = new PDO('mysql:host=127.0.0.1;port=3306;dbname=app_transfer_target;charset=utf8mb4', 'app', 'app');
foreach ([$source, $target] as $connection) {
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connection->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
}

function quoted(string $name, string $driver): string
{
    if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
        throw new RuntimeException('Unexpected identifier');
    }

    return $driver === 'mysql' ? '`'.$name.'`' : '"'.$name.'"';
}

/** @param array<string, mixed> $row */
function insertRow(PDO $db, string $table, array $row, string $driver): void
{
    $columns = array_keys($row);
    $sql = 'INSERT INTO '.quoted($table, $driver).' ('.implode(', ', array_map(fn ($column) => quoted($column, $driver), $columns))
        .') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')';
    $db->prepare($sql)->execute(array_values($row));
}

/** Normalize driver-specific representations without printing any row contents. */
function canonicalValue(mixed $value, string $type): mixed
{
    if ($value === null) {
        return null;
    }
    if ($type === 'boolean') {
        return in_array($value, [true, 't', '1', 1], true) ? '1' : '0';
    }
    if (in_array($type, ['json', 'jsonb'], true)) {
        $decoded = json_decode((string) $value, false, 512, JSON_THROW_ON_ERROR);
        $sort = static function (mixed $item) use (&$sort): mixed {
            if ($item instanceof stdClass) {
                $properties = get_object_vars($item);
                ksort($properties);
                foreach ($properties as &$child) {
                    $child = $sort($child);
                }
                unset($child);

                return (object) $properties;
            }
            if (is_array($item)) {
                foreach ($item as &$child) {
                    $child = $sort($child);
                }
                unset($child);
            }

            return $item;
        };

        return json_encode($sort($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if ($type === 'timestamp with time zone') {
        return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    return (string) $value;
}

if (canonicalValue('{}', 'jsonb') === canonicalValue('[]', 'jsonb')
    || canonicalValue('{"0":"x"}', 'jsonb') === canonicalValue('["x"]', 'jsonb')) {
    throw new RuntimeException('JSON object and array must remain distinct');
}

if ($argv[1] === 'replay-candidate') {
    // One synthetic post-cutover insert is replayed before rollback to the retained source.
    $row = $target->query("SELECT * FROM candidates WHERE email = 'after-cutover@example.test'")->fetch();
    if (! $row || $source->query("SELECT 1 FROM candidates WHERE email = 'after-cutover@example.test'")->fetch()) {
        throw new RuntimeException('Expected one unreplayed synthetic candidate');
    }
    insertRow($source, 'candidates', $row, 'pgsql');
    $source->query("SELECT setval(pg_get_serial_sequence('candidates', 'id'), (SELECT MAX(id) FROM candidates), true)")->fetchColumn();
    echo "Synthetic candidate replayed to retained PostgreSQL source.\n";
    exit(0);
}

$tables = $source->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations' ORDER BY tablename")
    ->fetchAll(PDO::FETCH_COLUMN);
$targetTables = $target->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name <> 'migrations'")
    ->fetchAll(PDO::FETCH_COLUMN);
sort($targetTables);
if ($tables !== $targetTables) {
    throw new RuntimeException('Source and target table inventories differ');
}

$relations = $target->query("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name,
    REFERENCED_TABLE_NAME AS referenced_table_name, REFERENCED_COLUMN_NAME AS referenced_column_name,
    CONSTRAINT_NAME AS constraint_name
    FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL")
    ->fetchAll();
$dependencies = array_fill_keys($tables, []);
$constraints = [];
foreach ($relations as $relation) {
    $child = $relation['table_name'];
    $parent = $relation['referenced_table_name'];
    // scripts.active_version_id is a nullable back-reference to script_versions.
    // Insert scripts first with NULL, then versions, then restore the back-reference.
    $deferredScriptVersion = $child === 'scripts' && $relation['column_name'] === 'active_version_id'
        && $parent === 'script_versions';
    if ($child !== $parent && ! $deferredScriptVersion) {
        $dependencies[$child][$parent] = true;
    }
    $key = $child.':'.$relation['constraint_name'];
    $constraints[$key][] = $relation;
}
foreach ($constraints as $columns) {
    if (count($columns) !== 1) {
        throw new RuntimeException('Composite foreign key needs explicit transfer handling');
    }
}

$ordered = [];
while ($dependencies !== []) {
    $ready = array_keys(array_filter($dependencies, static fn ($parents) => $parents === []));
    if ($ready === []) {
        throw new RuntimeException('Foreign-key cycle needs explicit transfer handling');
    }
    sort($ready);
    foreach ($ready as $table) {
        $ordered[] = $table;
        unset($dependencies[$table]);
        foreach ($dependencies as &$parents) {
            unset($parents[$table]);
        }
        unset($parents);
    }
}

$sourceTypes = [];
foreach ($source->query("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = 'public'") as $column) {
    $sourceTypes[$column['table_name']][$column['column_name']] = $column['data_type'];
}
$keys = [];
foreach ($target->query("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND constraint_name = 'PRIMARY' ORDER BY ordinal_position") as $column) {
    $keys[$column['table_name']][] = $column['column_name'];
}

$copied = 0;
$deferredScriptVersions = [];
foreach ($ordered as $table) {
    $pgTable = quoted($table, 'pgsql');
    $myTable = quoted($table, 'mysql');
    $sourceCount = (int) $source->query("SELECT COUNT(*) FROM $pgTable")->fetchColumn();
    $targetCount = (int) $target->query("SELECT COUNT(*) FROM $myTable")->fetchColumn();
    if ($targetCount > $sourceCount) {
        throw new RuntimeException("Target contains unexpected rows in $table");
    }
    if (! isset($keys[$table])) {
        if ($sourceCount !== $targetCount) {
            throw new RuntimeException("Unkeyed table $table needs explicit handling");
        }
        continue;
    }
    $rows = $source->query("SELECT * FROM $pgTable");
    foreach ($rows as $row) {
        $where = implode(' AND ', array_map(fn ($key) => quoted($key, 'mysql').' = ?', $keys[$table]));
        $parameters = array_map(fn ($key) => $row[$key], $keys[$table]);
        $exists = $target->prepare("SELECT * FROM $myTable WHERE $where LIMIT 1");
        $exists->execute($parameters);
        $baseline = $exists->fetch();
        if ($baseline !== false) {
            foreach ($row as $column => $value) {
                // Migration-generated wall-clock timestamps differ between the two fresh schemas.
                if (in_array($column, ['created_at', 'updated_at'], true)) {
                    continue;
                }
                $type = $sourceTypes[$table][$column] ?? '';
                if (canonicalValue($value, $type) !== canonicalValue($baseline[$column] ?? null, $type)) {
                    throw new RuntimeException("Migration baseline mismatch in $table.$column");
                }
            }
            continue;
        }
        foreach ($row as $column => &$value) {
            $type = $sourceTypes[$table][$column] ?? '';
            if ($value !== null && $type === 'boolean') {
                $value = in_array($value, [true, 't', '1', 1], true) ? 1 : 0;
            } elseif ($value !== null && $type === 'timestamp with time zone') {
                $value = (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            }
        }
        unset($value);
        if ($table === 'scripts' && $row['active_version_id'] !== null) {
            $deferredScriptVersions[(int) $row['id']] = $row['active_version_id'];
            $row['active_version_id'] = null;
        }
        insertRow($target, $table, $row, 'mysql');
        $copied++;
    }
    if ((int) $target->query("SELECT COUNT(*) FROM $myTable")->fetchColumn() !== $sourceCount) {
        throw new RuntimeException("Row count mismatch in $table");
    }
}

foreach ($deferredScriptVersions as $scriptId => $versionId) {
    $update = $target->prepare('UPDATE scripts SET active_version_id = ? WHERE id = ?');
    $update->execute([$versionId, $scriptId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Script version back-reference was not restored');
    }
}

foreach ($constraints as $columns) {
    $relation = $columns[0];
    $child = quoted($relation['table_name'], 'mysql');
    $parent = quoted($relation['referenced_table_name'], 'mysql');
    $column = quoted($relation['column_name'], 'mysql');
    $reference = quoted($relation['referenced_column_name'], 'mysql');
    $orphans = (int) $target->query("SELECT COUNT(*) FROM $child c LEFT JOIN $parent p ON c.$column = p.$reference WHERE c.$column IS NOT NULL AND p.$reference IS NULL")
        ->fetchColumn();
    if ($orphans !== 0) {
        throw new RuntimeException('Foreign-key orphans after transfer');
    }
}
echo 'Synthetic PostgreSQL-to-MySQL transfer passed: '.count($tables)." tables, $copied new rows, no FK orphans.\n";
