<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\PortalAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\ReportMessageRequest;
use App\Http\Requests\Messaging\SendMessageRequest;
use App\Messaging\Enums\MessageReportReason;
use App\Messaging\Exceptions\MessagingException;
use App\Messaging\Services\MessagingService;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\FrontendPortalAudienceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Shared by both student and instructor audiences (GAP-017), mirroring
 * the Support Case controller's shape — no separate instructor-only
 * route group. Every write delegates to MessagingService; this
 * controller never touches `conversations`/`messages`/`message_reports`
 * directly.
 */
final class MessagingController extends Controller
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly FrontendPortalAudienceResolver $audience,
    ) {}

    public function index(): View
    {
        return view('dashboard.messages.index');
    }

    /** SRS §17.42 workflow entry point: "Student opens lesson or learning plan context." */
    public function start(User $target): RedirectResponse
    {
        $user = auth()->user();
        $audience = $this->audience->resolve($user);

        if ($audience === PortalAudience::Student && $target->hasRole('instructor')) {
            $student = $user;
            $instructor = $target;
        } elseif ($audience === PortalAudience::Instructor && $target->hasRole('student')) {
            $student = $target;
            $instructor = $user;
        } else {
            return redirect()->route('dashboard.messages')->with('error', 'Invalid conversation participants.');
        }

        try {
            $conversation = $this->messaging->openOrFindConversation($student, $instructor, $user);
        } catch (MessagingException $e) {
            return redirect()->route('dashboard.messages')->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.messages.show', $conversation);
    }

    public function show(Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        $this->messaging->markRead($conversation, auth()->user());

        return view('dashboard.messages.show', ['conversation' => $conversation->load('messages.sender')]);
    }

    public function reply(Conversation $conversation, SendMessageRequest $request): RedirectResponse
    {
        Gate::authorize('reply', $conversation);

        try {
            $this->messaging->send(
                $conversation,
                $request->user(),
                $request->validated('body'),
                $request->file('attachment'),
            );
        } catch (MessagingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Message sent.');
    }

    public function report(Conversation $conversation, Message $message, ReportMessageRequest $request): RedirectResponse
    {
        Gate::authorize('report', $conversation);

        $this->messaging->reportMessage(
            $message,
            $request->user(),
            MessageReportReason::from($request->validated('reason')),
            $request->validated('details'),
        );

        return back()->with('success', 'Message reported. An administrator will review it.');
    }

    public function close(Conversation $conversation): RedirectResponse
    {
        Gate::authorize('close', $conversation);

        try {
            $this->messaging->close($conversation, auth()->user());
        } catch (MessagingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.messages')->with('success', 'Conversation closed.');
    }
}
