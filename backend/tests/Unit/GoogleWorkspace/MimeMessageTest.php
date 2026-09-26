<?php

declare(strict_types=1);

namespace Tests\Unit\GoogleWorkspace;

use App\Modules\GoogleWorkspace\DTO\OutgoingMail;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Support\MimeMessage;
use PHPUnit\Framework\TestCase;

final class MimeMessageTest extends TestCase
{
    private const string BOUNDARY = 'b0undary0123456789ab';

    public function test_ascii_subject_stays_plain_and_long_utf8_subject_is_split_on_characters(): void
    {
        $this->assertSame('Hello', MimeMessage::encodeHeader('Hello'));
        $subject = str_repeat('Співбесіда ', 10);
        $encoded = MimeMessage::encodeHeader($subject);
        $decoded = '';
        foreach (explode("\r\n ", $encoded) as $word) {
            $this->assertLessThanOrEqual(75, strlen($word));
            $this->assertMatchesRegularExpression('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/', $word);
            $part = (string) base64_decode(substr($word, 10, -2));
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
            $decoded .= $part;
        }
        $this->assertSame($subject, $decoded);
    }

    public function test_header_injection_is_neutralised(): void
    {
        $raw = MimeMessage::build(new OutgoingMail(
            to: 'a@example.test',
            subject: "Hi\r\nBcc: evil@example.test",
            text: 'x',
            toName: "Name\"\r\nBcc: evil@example.test",
            inReplyTo: "<x@y>\r\nBcc: evil@example.test",
        ), self::BOUNDARY);

        $this->assertStringNotContainsString("\r\nBcc:", $raw);
        $this->assertStringNotContainsString('In-Reply-To', $raw);
        $this->assertStringContainsString('Subject: Hi Bcc: evil@example.test', $raw);
        $this->assertStringContainsString('To: "Name Bcc: evil@example.test" <a@example.test>', $raw);
    }

    public function test_invalid_recipient_and_empty_text_are_refused(): void
    {
        $mails = [
            new OutgoingMail("a@example.test\r\nBcc: b@example.test", 's', 'x'),
            new OutgoingMail('not-an-email', 's', 'x'),
            new OutgoingMail('a@example.test', 's', '  '),
        ];
        foreach ($mails as $mail) {
            try {
                MimeMessage::build($mail, self::BOUNDARY);
                $this->fail('expected invalid_mail');
            } catch (GoogleException $e) {
                $this->assertSame('invalid_mail', $e->errorCode);
            }
        }
    }

    public function test_body_is_plain_text_plus_escaped_html(): void
    {
        $raw = MimeMessage::build(new OutgoingMail('a@example.test', 'S', "Рядок 1\n<img src=x onerror=alert(1)>"), self::BOUNDARY);

        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary="'.self::BOUNDARY.'"', $raw);
        $this->assertStringNotContainsString('<img', $raw);
        preg_match_all('/base64\r\n\r\n(.*?)\r\n--/s', $raw, $m);
        $this->assertSame("Рядок 1\r\n<img src=x onerror=alert(1)>", base64_decode(str_replace("\r\n", '', $m[1][0])));
        $this->assertStringContainsString("Рядок 1<br>\n&lt;img src=x onerror=alert(1)&gt;", (string) base64_decode(str_replace("\r\n", '', $m[1][1])));
    }

    public function test_base64url_has_no_padding_or_unsafe_characters(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', MimeMessage::base64Url("\xff\xfe\xfd?>"));
    }
}
