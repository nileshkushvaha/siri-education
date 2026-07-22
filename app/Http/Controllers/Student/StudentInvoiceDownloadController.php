<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The real security boundary for invoice downloads — every request
 * re-checks InvoicePolicy::view() live (owner or the View:Invoice
 * permission), mirroring InstructorDocumentDownloadController's
 * pattern. Rendered on demand from the invoice's own immutable
 * snapshot fields only — never a stored file, never a re-query of the
 * student's current profile/settings.
 */
final class StudentInvoiceDownloadController extends Controller
{
    public function __invoke(Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice]);

        // The invoice number legitimately contains "/" (SRS §14.22's own
        // example: STEM/INV/2026/000001) — invalid in a Content-Disposition
        // filename, so it is sanitized for the download name only; the
        // stored invoice_number itself is never altered.
        $filename = str_replace(['/', '\\'], '-', $invoice->invoice_number).'.pdf';

        return $pdf->download($filename);
    }
}
