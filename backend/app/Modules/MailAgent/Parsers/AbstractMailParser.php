<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Parsers;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\Contracts\MailParser;
use App\Modules\MailAgent\DTO\IncomingApplication;

/**
 * Tolerant extraction from a typical "new application" e-mail, in this order:
 * 1. labelled lines ("Ім'я: …", "Телефон: …", "E-mail: …", "Вакансія: …" — uk/ru/en labels);
 * 2. the subject ("… на вакансію «X»", "X відгукнувся на вакансію Y", "… from Name");
 * 3. fallbacks in the text: the first phone-looking number (10–13 digits), the first e-mail that is not the board's,
 *    a line of 2–3 capitalized words as the name, a link that looks like a CV.
 *
 * NOT calibrated on real mail (formats are unknown): board parsers only add patterns on top of these. Calibrate with
 * real (never committed) samples — docs/modules/mail-agent.md.
 */
abstract class AbstractMailParser implements MailParser
{
    /** Addresses of these domains are never the candidate's. */
    protected const array SERVICE_DOMAINS = ['work.ua', 'robota.ua', 'rabota.ua', 'djinni.co'];

    private const string NAME_WORD = "[\\p{L}'ʼ’\\-]+";

    private const array LABELS = [
        'name' => "ім['ʼ’]?я та прізвище|прізвище та ім['ʼ’]?я|ім['ʼ’]?я|піб|фио|имя|кандидат|шукач|соискатель|full name|name|candidate|applicant",
        'phone' => 'моб(?:ільний|ильный)?\.?\s*телефон|телефон|тел\.?|phone|mobile|tel\.?',
        'email' => 'e-?mail|ел\.?\s*пошта|електронна пошта|эл\.?\s*почта|электронная почта|пошта|почта',
        'vacancy' => 'вакансія|вакансия|посада|должность|vacancy|position|job',
    ];

    public function parse(GmailMessage $message): ?IncomingApplication
    {
        $labelled = $this->labelled($message->text);
        $email = $this->email($labelled['email'] ?? null, $message->text, $message->fromEmail);
        $phone = $this->phone($labelled['phone'] ?? null, $message->text);
        if ($email === null && $phone === null) {
            return null;
        }

        return new IncomingApplication(
            fullName: $this->name($labelled['name'] ?? null, $message->subject, $message->text),
            phone: $phone,
            email: $email,
            vacancyTitle: $this->vacancy($labelled['vacancy'] ?? null, $message->subject),
            vacancyRef: self::vacancyRef($message->text),
            cvUrl: self::cvUrl($message->text),
        );
    }

    /**
     * Extra subject regexes of a board; named groups "n" (name) and/or "v" (vacancy). Tried before the common ones.
     *
     * @return list<string>
     */
    protected function subjectPatterns(): array
    {
        return [];
    }

    /** @return list<string> */
    private function allSubjectPatterns(): array
    {
        $w = self::NAME_WORD;

        return [
            ...$this->subjectPatterns(),
            // "Олена Приклад відгукнулася на вакансію «Менеджер»" / "Jane Doe applied to Sales Manager"
            "/^(?:[^:]*:\\s*)?(?<n>\\p{Lu}{$w}(?:\\s+\\p{Lu}{$w}){1,2})\\s+(?:відгукнул(?:ся|ася|ись)|откликнул(?:ся|ась|ись)|applied)\\s+(?:на|to|for)\\s+(?:вакансію|вакансию|the\\s+)?(?:vacancy|position|job)?\\s*[«\"“]?(?<v>[^»\"”]+?)[»\"”]?\\s*$/iu",
            // quoted vacancy anywhere: «X», "X", “X”
            '/[«"“„](?<v>[^»"”“]{2,150})[»"”]/u',
            // "… на вакансію X [від Name]"
            "/(?:на\\s+вакансію|на\\s+вакансию|for\\s+(?:the\\s+)?(?:vacancy|position|job)|вакансія|вакансия|vacancy|position)\\s*[:\\-–—]?\\s*(?<v>.+?)(?:\\s+(?:від|от|from)\\s+(?<n>{$w}(?:\\s+{$w}){0,3}))?\\s*$/iu",
            // "… від Name" / "… from Name"
            "/(?:від|от|from)\\s+(?<n>\\p{Lu}{$w}(?:\\s+\\p{Lu}{$w}){0,3})\\s*$/u",
        ];
    }

    /** @return array<string, string> field → value of the first labelled line */
    private function labelled(string $text): array
    {
        $found = [];
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            foreach (self::LABELS as $field => $labels) {
                if (isset($found[$field])) {
                    continue;
                }
                if (preg_match('/^\s*[*•\-]?\s*(?:'.$labels.')\s*[:：\-–—]\s*(.+?)\s*$/iu', $line, $m) === 1) {
                    $found[$field] = $m[1];
                }
            }
        }

        return $found;
    }

    private function email(?string $labelled, string $text, ?string $sender): ?string
    {
        $senderDomain = $sender !== null && str_contains($sender, '@') ? substr($sender, (int) strrpos($sender, '@') + 1) : null;
        foreach ([$labelled ?? '', $text] as $source) {
            preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $source, $m);
            foreach ($m[0] as $candidate) {
                $email = mb_strtolower(rtrim($candidate, '.'));
                [$local, $domain] = explode('@', $email, 2);
                if ($domain === $senderDomain || self::isServiceDomain($domain)
                    || preg_match('/^(no-?reply|do-?not-?reply|support|info|hello|notifications?|mailer-daemon)$/', $local) === 1) {
                    continue;
                }

                return $email;
            }
        }

        return null;
    }

    private function phone(?string $labelled, string $text): ?string
    {
        foreach ([$labelled ?? '', $text] as $source) {
            preg_match_all('/(?<![\w+])(\+?\d[\d\s\-().]{8,18}\d)(?!\d)/u', $source, $m);
            foreach ($m[1] as $candidate) {
                $digits = strlen((string) preg_replace('/\D/', '', $candidate));
                if ($digits >= 10 && $digits <= 13) {
                    return trim($candidate);
                }
            }
        }

        return null;
    }

    private function name(?string $labelled, string $subject, string $text): ?string
    {
        if ($labelled !== null) {
            return self::clean($labelled, 120);
        }
        foreach ($this->allSubjectPatterns() as $pattern) {
            if (preg_match($pattern, $subject, $m) === 1 && ($m['n'] ?? '') !== '') {
                return self::clean($m['n'], 120);
            }
        }
        $w = self::NAME_WORD;
        foreach (array_slice(preg_split('/\n/u', $text) ?: [], 0, 15) as $line) {
            if (preg_match("/^\\p{Lu}{$w}(?:\\s+\\p{Lu}{$w}){1,2}$/u", trim($line)) === 1) {
                return self::clean($line, 120);
            }
        }

        return null;
    }

    private function vacancy(?string $labelled, string $subject): ?string
    {
        if ($labelled !== null) {
            return self::clean($labelled, 200);
        }
        foreach ($this->allSubjectPatterns() as $pattern) {
            if (preg_match($pattern, $subject, $m) === 1 && ($m['v'] ?? '') !== '') {
                return self::clean($m['v'], 200);
            }
        }

        return null;
    }

    private static function vacancyRef(string $text): ?string
    {
        return preg_match('#/(?:vacancy|vacancies|jobs?)/(\d{3,12})\b#i', $text, $m) === 1 ? $m[1] : null;
    }

    private static function cvUrl(string $text): ?string
    {
        preg_match_all('#https://[^\s<>"\')\]]+#i', $text, $m);
        foreach ($m[0] as $url) {
            if (preg_match('#(cv|resume|rezume|resumes|candidate|applicant|attachment|download|file)#i', $url) === 1
                && preg_match('#unsubscribe|settings|preferences#i', $url) !== 1) {
                return mb_substr(rtrim($url, '.,;'), 0, 500);
            }
        }

        return null;
    }

    private static function isServiceDomain(string $domain): bool
    {
        foreach (self::SERVICE_DOMAINS as $service) {
            if ($domain === $service || str_ends_with($domain, '.'.$service)) {
                return true;
            }
        }

        return false;
    }

    private static function clean(string $value, int $max): ?string
    {
        // Multibyte-safe trim (byte-wise trim() would cut UTF-8 sequences of «» and quotes).
        $value = (string) preg_replace(['/\s+/u', '/^[\s.,;:!«»"“”„\']+|[\s.,;:!«»"“”„\']+$/u'], [' ', ''], $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
