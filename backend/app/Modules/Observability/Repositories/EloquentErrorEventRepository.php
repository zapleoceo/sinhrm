<?php

declare(strict_types=1);

namespace App\Modules\Observability\Repositories;

use App\Modules\Observability\Contracts\ErrorEventRepository;
use App\Modules\Observability\Models\ErrorEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentErrorEventRepository implements ErrorEventRepository
{
    private const string TABLE = 'error_events';

    public function list(?bool $resolved, int $limit): Collection
    {
        $query = ErrorEvent::query()->orderByDesc('last_seen_at')->limit($limit);
        if ($resolved === false) {
            $query->whereNull('resolved_at');
        } elseif ($resolved === true) {
            $query->whereNotNull('resolved_at');
        }

        return $query->get();
    }

    public function findOrFail(int $id): ErrorEvent
    {
        return ErrorEvent::query()->findOrFail($id);
    }

    public function save(ErrorEvent $event): void
    {
        $event->save();
    }

    public function upsertGroup(array $row): void
    {
        // Query builder, not Eloquent: one upsert statement, no model events (the recorder runs inside the reporter).
        DB::table(self::TABLE)->upsert([$row], ['fingerprint'], [
            'count' => DB::raw(self::TABLE.'.count + 1'),
            'message' => $row['message'],
            'route' => $row['route'],
            'last_user_id' => $row['last_user_id'],
            'last_seen_at' => $row['last_seen_at'],
            'resolved_at' => null,
            'updated_at' => $row['updated_at'],
        ]);
    }

    public function pruneNotSeenSince(Carbon $before): int
    {
        return DB::table(self::TABLE)->where('last_seen_at', '<', $before)->delete();
    }
}
