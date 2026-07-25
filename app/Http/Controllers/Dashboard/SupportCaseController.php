<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\PortalAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupportCases\ReplySupportCaseRequest;
use App\Http\Requests\SupportCases\StoreSupportCaseRequest;
use App\Models\SupportCase;
use App\Services\FrontendPortalAudienceResolver;
use App\SupportCases\DTOs\CreateSupportCaseData;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use App\SupportCases\Enums\SupportCaseType;
use App\SupportCases\Exceptions\InvalidSupportCaseTransitionException;
use App\SupportCases\Exceptions\UnauthorizedLinkedRecordException;
use App\SupportCases\Services\SupportCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Shared by both student and instructor audiences (GAP-016 owns no
 * separate instructor-only route group — a case's SupportCaseType is
 * derived from the acting user's active workspace via
 * FrontendPortalAudienceResolver, the same dual-role resolution
 * already used elsewhere in the frontend portal). Every write
 * delegates to SupportCaseService; this controller never touches
 * `support_cases`/`support_case_replies` directly.
 */
final class SupportCaseController extends Controller
{
    public function __construct(
        private readonly SupportCaseService $cases,
        private readonly FrontendPortalAudienceResolver $audience,
    ) {}

    public function index(): View
    {
        return view('dashboard.support-cases.index');
    }

    public function create(): View
    {
        return view('dashboard.support-cases.create');
    }

    public function store(StoreSupportCaseRequest $request): RedirectResponse
    {
        $user = $request->user();
        $audience = $this->audience->resolve($user);

        $type = $audience === PortalAudience::Instructor ? SupportCaseType::Instructor : SupportCaseType::Student;

        try {
            $case = $this->cases->create(new CreateSupportCaseData(
                creator: $user,
                type: $type,
                category: SupportCaseCategory::from($request->validated('category')),
                priority: $request->validated('priority') !== null ? SupportCasePriority::from($request->validated('priority')) : SupportCasePriority::Medium,
                subject: $request->validated('subject'),
                description: $request->validated('description'),
                student: $type === SupportCaseType::Student ? $user : null,
                instructor: $type === SupportCaseType::Instructor ? $user : null,
                linkedRecordType: $request->validated('linked_record_type'),
                linkedRecordId: $request->validated('linked_record_id'),
            ));
        } catch (UnauthorizedLinkedRecordException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.support-cases.show', $case)
            ->with('success', sprintf('Support case %s has been received.', $case->case_number));
    }

    public function show(SupportCase $supportCase): View
    {
        Gate::authorize('view', $supportCase);

        return view('dashboard.support-cases.show', ['case' => $supportCase]);
    }

    public function reply(SupportCase $supportCase, ReplySupportCaseRequest $request): RedirectResponse
    {
        Gate::authorize('reply', $supportCase);

        $this->cases->addReply($supportCase, $request->user(), $request->validated('body'), SupportCaseReplyVisibility::RequesterVisible);

        return back()->with('success', 'Your reply has been added.');
    }

    public function reopen(SupportCase $supportCase): RedirectResponse
    {
        Gate::authorize('reopen', $supportCase);

        try {
            $this->cases->reopen($supportCase, auth()->user());
        } catch (InvalidSupportCaseTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Case reopened.');
    }
}
