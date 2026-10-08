<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-10-07: the exit survey has five short questions. Only saved surveys whose questions are EXACTLY
 * the old built-in template (frozen below; compared as decoded structures with sorted keys — a JSON column does not keep
 * key order) change; any difference (a rewritten text, option or flag, even with the same ids) = HR's survey, left alone.
 * - no answers yet: its questions are replaced in place;
 * - with answers and active: it is switched off and an active copy with the five questions is created — earlier
 *   waves keep the questions they were answered with, new exit waves use the copy. Running it again changes nothing.
 * The questions are frozen here on purpose (a migration must not follow later template edits). The copy fills every
 * NOT NULL column of surveys (title, type, questions, active, timestamps); surveys has no unique constraint.
 */
return new class extends Migration
{
    /** The built-in exit template before 2026-10-07, frozen. */
    private const string OLD_TEMPLATE = <<<'JSON'
        [
          {"id": "reason", "type": "single", "text": "Головна причина звільнення", "options": ["Зарплата", "Керівник", "Задачі", "Кар'єрне зростання", "Особисті обставини", "Інше"], "required": true},
          {"id": "enps", "type": "enps", "text": "Чи порекомендуєте ви нас як роботодавця?", "required": true},
          {"id": "comment", "type": "text", "text": "Що ми могли зробити інакше?", "required": false}
        ]
        JSON;

    /** Associative arrays with sorted keys, lists in their order: equal structures compare equal whatever the key order. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function questions(): array
    {
        return [
            ['id' => 'reason', 'type' => 'single', 'text' => 'Головна причина звільнення', 'options' => ['Зарплата', 'Керівник', 'Задачі', 'Кар\'єрне зростання', 'Особисті обставини', 'Інше'], 'required' => true],
            ['id' => 'liked', 'type' => 'text', 'text' => 'Що вам подобалося в роботі у нас?', 'required' => false],
            ['id' => 'disliked', 'type' => 'text', 'text' => 'Що вам не подобалося?', 'required' => false],
            ['id' => 'manager', 'type' => 'scale5', 'text' => 'Як ви оцінюєте стосунки з керівником?', 'required' => true],
            ['id' => 'return', 'type' => 'single', 'text' => 'Чи повернулися б ви до нас?', 'options' => ['Так', 'Можливо', 'Ні'], 'required' => true],
        ];
    }

    public function up(): void
    {
        $json = json_encode($this->questions(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $old = self::canonical(json_decode(self::OLD_TEMPLATE, true, 512, JSON_THROW_ON_ERROR));
        $now = Carbon::now();
        foreach (DB::table('surveys')->where('lifecycle_trigger', 'exit')->orderBy('id')->get() as $survey) {
            if (self::canonical(json_decode((string) $survey->questions, true)) !== $old) {
                continue;
            }
            $answered = DB::table('survey_responses')
                ->whereIn('wave_id', DB::table('survey_waves')->where('survey_id', $survey->id)->select('id'))
                ->exists();
            if (! $answered) {
                DB::table('surveys')->where('id', $survey->id)->update(['questions' => $json, 'updated_at' => $now]);

                continue;
            }
            if (! $survey->active) {
                continue; // switched off (by HR or by an earlier run): starts no waves, nothing to replace
            }
            DB::table('surveys')->insert([
                'title' => $survey->title,
                'type' => $survey->type,
                'description' => $survey->description,
                'questions' => $json,
                'lifecycle_trigger' => 'exit',
                'active' => true,
                'created_by' => $survey->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('surveys')->where('id', $survey->id)->update(['active' => false, 'updated_at' => $now]);
        }
    }

    /** Data change only: nothing to undo safely (answers may already use the new questions). */
    public function down(): void {}
};
