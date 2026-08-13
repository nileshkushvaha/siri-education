<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Page shell for the instructor's package-proposal creation area; all
 * behavior lives in PackageProposalCreator, all rules in
 * InstructorPackageProposalService.
 */
final class InstructorPackageProposalController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.packages.index');
    }
}
