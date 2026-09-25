<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Scripts\Services\TemplateService;
use App\Modules\Scripts\Support\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    public function test_fills_known_values_and_keeps_missing_or_unknown_tokens(): void
    {
        $out = TemplateRenderer::render(
            "Вітаю, {Ім'я}! {Рекрутер} — {Вакансія}. {Адреса} {Адреса} {json} {Посилання на співбесіду}",
            ["Ім'я" => ' Олена ', 'Рекрутер' => 'Ірина', 'Вакансія' => '', 'Посилання на співбесіду' => null],
        );

        $this->assertSame('Вітаю, Олена! Ірина — {Вакансія}. {Адреса} {Адреса} {json} {Посилання на співбесіду}', $out['text']);
        $this->assertSame(['Вакансія', 'Адреса', 'Посилання на співбесіду'], $out['missing']);
    }

    public function test_unknown_tokens(): void
    {
        $this->assertSame(['Імя', 'Name'], TemplateRenderer::unknownTokens("{Імя} {Ім'я} {Name} {Імя}"));
        $this->assertSame([], TemplateRenderer::unknownTokens('No variables, {} or {multi
line}'));
    }

    public function test_first_name(): void
    {
        $this->assertSame('Olena', TemplateService::firstName('  Olena   Test '));
        $this->assertNull(TemplateService::firstName('   '));
    }
}
