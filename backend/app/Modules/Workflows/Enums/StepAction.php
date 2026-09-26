<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Enums;

/** What a workflow step does when it is due. Each value has exactly one StepExecutor (Executors/*). */
enum StepAction: string
{
    case CreateTask = 'create_task';
    case RequestForm = 'request_form';
    case SendEmailTemplate = 'send_email_template';
    case AddCalendarEvent = 'add_calendar_event';
    case CreateDocument = 'create_document';
    case UploadDocumentRequest = 'upload_document_request';
    case Webhook = 'webhook';
    case StartWorkflow = 'start_workflow';
    case NotifyManager = 'notify_manager';
    case AssignBuddy = 'assign_buddy';
}
