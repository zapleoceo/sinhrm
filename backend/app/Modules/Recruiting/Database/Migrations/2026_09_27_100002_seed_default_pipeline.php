<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: the default pipeline and generic rejection reasons (generic names only, no company data).
 * Idempotent: does nothing when a default pipeline / any reason already exists.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: bool}> name, kind, is_terminal */
    private const array STAGES = [
        ['Новий відгук', 'attract', false],
        ['Первинний контакт', 'attract', false],
        ['Співбесіда', 'select', false],
        ['Зустріч у філії', 'select', false],
        ['Фінал з керівником', 'select', false],
        ['Офер', 'hire', false],
        ['Вийшов на роботу', 'hire', true],
        ['Відмова', 'closed', true],
    ];

    /** @var list<string> */
    private const array REJECT_REASONS = [
        'Не підійшов за досвідом',
        'Відмовився сам',
        'Не виходить на звʼязок',
        'Не влаштували умови',
        'Не прийшов на зустріч',
        'Інше',
    ];

    public function up(): void
    {
        $now = now();
        if (! DB::table('pipelines')->where('is_default', true)->exists()) {
            $pipelineId = DB::table('pipelines')->insertGetId([
                'name' => 'Основна воронка',
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach (self::STAGES as $i => [$name, $kind, $terminal]) {
                DB::table('pipeline_stages')->insert([
                    'pipeline_id' => $pipelineId,
                    'name' => $name,
                    'kind' => $kind,
                    'position' => $i + 1,
                    'is_terminal' => $terminal,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        if (! DB::table('reject_reasons')->exists()) {
            foreach (self::REJECT_REASONS as $name) {
                DB::table('reject_reasons')->insert(['name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // The tables are dropped by the previous migration.
    }
};
