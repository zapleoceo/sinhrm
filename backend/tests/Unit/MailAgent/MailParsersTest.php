<?php

declare(strict_types=1);

namespace Tests\Unit\MailAgent;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\GoogleWorkspace\Support\MimeText;
use App\Modules\MailAgent\Contracts\MailParser;
use App\Modules\MailAgent\Parsers\DjinniParser;
use App\Modules\MailAgent\Parsers\GenericParser;
use App\Modules\MailAgent\Parsers\RobotaUaParser;
use App\Modules\MailAgent\Parsers\WorkUaParser;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsers on INVENTED fixtures. Real job-board formats are unknown: these fixtures only model a typical
 * "new application" notification; calibrate on real mail (never commit it).
 */
final class MailParsersTest extends TestCase
{
    /** @return array<string, array{MailParser, string, string, string, array<string, string|null>}> */
    public static function fixtures(): array
    {
        return [
            'work.ua-like, labelled lines' => [
                new WorkUaParser,
                'robot@notify.work.ua',
                'Новий відгук на вакансію «Адміністратор філії»',
                "Вітаємо!\nНа вашу вакансію відгукнувся кандидат.\n\nІм'я: Оксана Вигадана\nТелефон: +38 (050) 765-43-21\nE-mail: oksana.vyhadana@example.test\n"
                    ."Переглянути резюме: https://www.work.ua/resumes/1234567/\n\nЛист від no-reply@work.ua",
                ['fullName' => 'Оксана Вигадана', 'phone' => '+38 (050) 765-43-21', 'email' => 'oksana.vyhadana@example.test',
                    'vacancyTitle' => 'Адміністратор філії', 'cvUrl' => 'https://www.work.ua/resumes/1234567/'],
            ],
            'robota.ua-like, no labels' => [
                new RobotaUaParser,
                'noreply@robota.ua',
                'Нове резюме на вакансію Оператор кол-центру',
                "Сергій Тестенко\nКиїв\n0671112233\nserhii.testenko@example.test\nhttps://robota.ua/candidates/555000",
                ['fullName' => 'Сергій Тестенко', 'phone' => '0671112233', 'email' => 'serhii.testenko@example.test',
                    'vacancyTitle' => 'Оператор кол-центру', 'cvUrl' => 'https://robota.ua/candidates/555000'],
            ],
            'djinni-like, english subject' => [
                new DjinniParser,
                'no-reply@djinni.co',
                'Jane Sample applied to Sales Manager',
                "Hi!\nJane Sample is interested in your job.\nEmail: jane.sample@example.test\nMessage: looking forward to hearing from you.",
                ['fullName' => 'Jane Sample', 'phone' => null, 'email' => 'jane.sample@example.test', 'vacancyTitle' => 'Sales Manager', 'cvUrl' => null],
            ],
            'generic careers form (russian labels)' => [
                new GenericParser,
                'forms@careers.example.test',
                'Заявка с сайта: вакансия "Кассир"',
                "Имя: Пётр Примеров\nТел.: +380 (93) 000 11 22\nПочта: petr.primerov@example.test",
                ['fullName' => 'Пётр Примеров', 'phone' => '+380 (93) 000 11 22', 'email' => 'petr.primerov@example.test', 'vacancyTitle' => 'Кассир', 'cvUrl' => null],
            ],
        ];
    }

    /** @param  array<string, string|null>  $expected */
    #[DataProvider('fixtures')]
    public function test_extracts_the_application(MailParser $parser, string $from, string $subject, string $text, array $expected): void
    {
        $result = $parser->parse($this->message($from, $subject, $text));

        $this->assertNotNull($result);
        foreach ($expected as $field => $value) {
            $this->assertSame($value, $result->{$field}, $field);
        }
    }

    public function test_html_only_mail_is_parsed_after_stripping_tags(): void
    {
        $html = '<html><head><style>p{color:red}</style></head><body><p>Кандидат: <b>Ірина Зразкова</b></p>'
            .'<p>Телефон: 097 123 45 67</p><script>alert("x")</script>'
            .'<a href="https://jobs.example.test/cv/42">Резюме</a></body></html>';
        $text = MimeText::extract(['mimeType' => 'text/html', 'body' => ['data' => rtrim(strtr(base64_encode($html), '+/', '-_'), '=')]]);

        $result = (new GenericParser)->parse($this->message('robot@jobs.example.test', 'Новий відгук', $text));

        $this->assertNotNull($result);
        $this->assertSame('Ірина Зразкова', $result->fullName);
        $this->assertSame('097 123 45 67', $result->phone);
        $this->assertSame('https://jobs.example.test/cv/42', $result->cvUrl);
        $this->assertStringNotContainsString('alert', $text);
    }

    public function test_board_and_robot_addresses_are_not_taken_as_the_candidate_email(): void
    {
        $result = (new WorkUaParser)->parse($this->message(
            'robot@notify.work.ua',
            'Новий відгук',
            "Телефон: 0501234567\nВідповісти: support@work.ua\nno-reply@jobs.example.test",
        ));

        $this->assertNotNull($result);
        $this->assertNull($result->email);
    }

    public function test_no_contact_means_no_application(): void
    {
        $this->assertNull((new GenericParser)->parse($this->message('robot@jobs.example.test', 'Новий відгук', "Ім'я: Без Контактів\nДата: 2026-09-29")));
    }

    private function message(string $from, string $subject, string $text): GmailMessage
    {
        return new GmailMessage('m1', Carbon::now(), $from, null, $subject, $text);
    }
}
