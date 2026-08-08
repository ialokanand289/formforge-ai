<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Services\SubmissionExportService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionExportController extends Controller
{
    /**
     * Download a form's submissions as CSV.
     *
     * The form arrives through route model binding, so ownership is decided by
     * the policy against a server resolved record; nothing about the owner is
     * read from the request. Authorization runs before the first submission
     * query.
     */
    public function __invoke(Form $form, SubmissionExportService $exports): StreamedResponse
    {
        // The Laravel 11 base controller carries no AuthorizesRequests trait.
        Gate::authorize('view', $form);

        return $exports->download($form);
    }
}
