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
    /** "Спробувати" in the prompt editor: a draft prompt on a built-in synthetic sample; nothing is applied. */
    case PromptTrial = 'prompt_trial';

    /** @return list<self> purposes whose prompt can be edited and whose stats are shown per row in the admin */
    public static function editable(): array
    {
        return [self::ScriptEvaluation, self::MailClassification, self::CandidateScreening];
    }

    /** Name of the capability select in the ai_broker settings (the test prompt uses the default "capability"). */
    public function capabilitySetting(): string
    {
        return $this === self::Test || $this === self::PromptTrial ? 'capability' : 'capability_'.$this->value;
    }

    /** Name of the on/off select in the ai_broker settings; null = cannot be switched off separately. */
    public function settingName(): ?string
    {
        return $this === self::Test || $this === self::PromptTrial ? null : 'ai_'.$this->value;
    }
}
