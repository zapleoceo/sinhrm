<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

/**
 * Built-in survey templates offered by the builder ("from template"): eNPS, a short engagement pulse, onboarding
 * check-in and an exit survey. Plain Ukrainian text; the admin edits the copy before saving.
 */
final class SurveyTemplates
{
    /** Exit survey: reason, liked, disliked, relationship with the manager, would come back. */
    public const array EXIT_QUESTIONS = [
        ['id' => 'reason', 'type' => 'single', 'text' => 'Головна причина звільнення', 'options' => ['Зарплата', 'Керівник', 'Задачі', 'Кар\'єрне зростання', 'Особисті обставини', 'Інше'], 'required' => true],
        ['id' => 'liked', 'type' => 'text', 'text' => 'Що вам подобалося в роботі у нас?', 'required' => false],
        ['id' => 'disliked', 'type' => 'text', 'text' => 'Що вам не подобалося?', 'required' => false],
        ['id' => 'manager', 'type' => 'scale5', 'text' => 'Як ви оцінюєте стосунки з керівником?', 'required' => true],
        ['id' => 'return', 'type' => 'single', 'text' => 'Чи повернулися б ви до нас?', 'options' => ['Так', 'Можливо', 'Ні'], 'required' => true],
    ];

    /** @return list<array{key: string, title: string, type: string, lifecycle_trigger: string|null, questions: list<array<string, mixed>>}> */
    public static function all(): array
    {
        return [
            [
                'key' => 'enps',
                'title' => 'eNPS',
                'type' => 'enps',
                'lifecycle_trigger' => null,
                'questions' => [
                    ['id' => 'enps', 'type' => 'enps', 'text' => 'Наскільки ймовірно, що ви порекомендуєте нашу компанію як місце роботи другу?', 'required' => true],
                    ['id' => 'why', 'type' => 'text', 'text' => 'Що найбільше вплинуло на вашу оцінку?', 'required' => false],
                ],
            ],
            [
                'key' => 'engagement',
                'title' => 'Залученість (коротке опитування)',
                'type' => 'engagement',
                'lifecycle_trigger' => null,
                'questions' => [
                    ['id' => 'q1', 'type' => 'scale5', 'text' => 'Я знаю, чого від мене очікують на роботі', 'required' => true],
                    ['id' => 'q2', 'type' => 'scale5', 'text' => 'У мене є все необхідне, щоб добре виконувати роботу', 'required' => true],
                    ['id' => 'q3', 'type' => 'scale5', 'text' => 'За останній тиждень мене хвалили за добре виконану роботу', 'required' => true],
                    ['id' => 'q4', 'type' => 'scale5', 'text' => 'Мій керівник дбає про мене як про людину', 'required' => true],
                    ['id' => 'q5', 'type' => 'text', 'text' => 'Що нам варто змінити в першу чергу?', 'required' => false],
                ],
            ],
            [
                'key' => 'onboarding',
                'title' => 'Адаптація: перший місяць',
                'type' => 'lifecycle',
                'lifecycle_trigger' => 'hire_30',
                'questions' => [
                    ['id' => 'q1', 'type' => 'scale5', 'text' => 'Наскільки зрозумілі ваші задачі та очікування?', 'required' => true],
                    ['id' => 'q2', 'type' => 'scale5', 'text' => 'Наскільки вам допомагає команда?', 'required' => true],
                    ['id' => 'q3', 'type' => 'text', 'text' => 'Чого вам не вистачало в перший місяць?', 'required' => false],
                ],
            ],
            [
                'key' => 'exit',
                'title' => 'Вихідне опитування',
                'type' => 'lifecycle',
                'lifecycle_trigger' => 'exit',
                // Owner decision 2026-10-07: five short questions (migration 2026_10_25_100002 brings saved copies in line).
                'questions' => self::EXIT_QUESTIONS,
            ],
        ];
    }
}
