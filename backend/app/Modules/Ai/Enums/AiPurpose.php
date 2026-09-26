<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/** What an AI request is for. Each purpose has its own prompt, result handler and on/off setting. */
enum AiPurpose: string
{
    case ScriptEvaluation = 'script_evaluation';
    case MailClassification = 'mail_classification';
    case CandidateScreening = 'candidate_screening';
    /** "Test prompt" button in the admin: a tiny fixed prompt, no personal data. */
    case Test = 'test';

    /** Name of the capability select in the ai_broker settings (the test prompt uses the default "capability"). */
    public function capabilitySetting(): string
    {
        return $this === self::Test ? 'capability' : 'capability_'.$this->value;
    }

    /** Name of the on/off select in the ai_broker settings; null = cannot be switched off separately. */
    public function settingName(): ?string
    {
        return $this === self::Test ? null : 'ai_'.$this->value;
    }
}
