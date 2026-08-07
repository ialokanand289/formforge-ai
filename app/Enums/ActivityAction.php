<?php

namespace App\Enums;

/**
 * Activity action type definition only.
 * Cases are reserved for later phases — do not depend on unused values yet.
 */
enum ActivityAction: string
{
    case FormCreated = 'form.created';
    case FormUpdated = 'form.updated';
    case FormPublished = 'form.published';
    case FormArchived = 'form.archived';
    case FormDeleted = 'form.deleted';
    case FormRolledBack = 'form.rolled_back';
    case AiGenerate = 'ai.generate';
    case AiEdit = 'ai.edit';
    case ImportUploaded = 'import.uploaded';
    case ImportPreviewReady = 'import.preview_ready';
    case ImportCommitted = 'import.committed';
    case ImportFailed = 'import.failed';
    case SubmissionReceived = 'submission.received';
}
