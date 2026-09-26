<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/**
 * HOW a candidate record got into SinHRM (tz3 "method of adding") — stored separately from the channel (WHERE FROM).
 * null on candidates created before the channels migration when it could not be inferred.
 */
enum AddedVia: string
{
    /** A recruiter in the UI (candidate form, "create from inbox message"). */
    case Manual = 'manual';
    /** Generic import (API / CSV-like rows). */
    case Import = 'import';
    /** The Gmail mail agent (job-board application e-mails). */
    case Mail = 'mail';
    /** The Chrome clipper extension. */
    case Extension = 'extension';
    /** Reserved for inbound lead-form webhooks (no such endpoint yet). */
    case Webhook = 'webhook';
    /** Google Sheets import. */
    case Sheets = 'sheets';
}
