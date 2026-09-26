<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

/** Kind of a task in the unified task list ("Мої задачі"). */
enum TaskType: string
{
    case Followup = 'followup';
    case Manual = 'manual';
    /** "Call the new applicant within an hour" — created by the mail agent for a job-board application. */
    case NewApplicant = 'new_applicant';
    /** A step of an onboarding/offboarding workflow (Workflows module) assigned to a person. */
    case Workflow = 'workflow';
    /** "Read and acknowledge the document" for the employee (Documents module). */
    case Document = 'document';
    /** "Team mood dropped" for a manager (Pulse mood alerts). */
    case MoodAlert = 'mood_alert';
    /** "A helpdesk case breached its SLA" for the assignee / HR (Desk module, job desk.sla). */
    case DeskSla = 'desk_sla';
    /** "Approve the hiring request" for the approver of the current route step / SLA escalation to HR (HiringRequests). */
    case HiringApproval = 'hiring_approval';
    /** "Fill in / submit your timesheet for the week" for the employee (Time, job time.reminders). */
    case TimesheetReminder = 'time_reminder';

    /** Source group of the "Мої задачі" filter. */
    public function source(): TaskSource
    {
        return match ($this) {
            self::Workflow => TaskSource::Workflows,
            self::Document => TaskSource::Documents,
            self::MoodAlert => TaskSource::Pulse,
            self::DeskSla => TaskSource::Desk,
            self::HiringApproval => TaskSource::Hiring,
            self::TimesheetReminder => TaskSource::Time,
            self::Followup, self::Manual, self::NewApplicant => TaskSource::Recruiting,
        };
    }
}
