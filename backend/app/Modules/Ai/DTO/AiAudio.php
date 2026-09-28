<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTO;

use SensitiveParameter;

/** A voice recording to transcribe (Whisper through the AI Broker). Never logged or dumped: it is personal data. */
final readonly class AiAudio
{
    public function __construct(
        #[SensitiveParameter] public string $bytes,
        public string $filename,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['filename' => $this->filename, 'bytes' => strlen($this->bytes)];
    }
}
