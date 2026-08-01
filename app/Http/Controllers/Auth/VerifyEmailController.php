<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Auth\EmailVerificationOutcome;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AccountEmailVerificationService;
use App\Services\PortalResolver;
use App\Support\InstructorApplicationIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Follows a signed email-verification link.
 *
 * PUBLIC BY DESIGN. The previous implementation sat behind `auth` and
 * used `EmailVerificationRequest`, which authorises against the
 * *authenticated* user — so the link only worked in the same browser
 * that submitted the registration form. Opening it on a phone bounced
 * the visitor to a login that could not succeed, because the account is
 * created `pending_verification` and `LoginService` rejects any
 * non-active account. That deadlock is the defect this controller
 * removes.
 *
 * Nothing about security is relaxed. The route still requires a valid,
 * unexpired signature; the user is resolved from the signed `{id}` and
 * the `{hash}` is compared against the account's *current* email with a
 * constant-time comparison, so a link minted before an address change
 * cannot verify the new address.
 *
 * The guest is never silently authenticated — proving control of a
 * mailbox is not proof of possession of the password, and a link
 * forwarded to somebody else must not hand them a session. They land on
 * a public success page and continue to the normal login.
 */
final class VerifyEmailController extends Controller
{
    public function __construct(
        private readonly AccountEmailVerificationService $verification,
    ) {}

    public function __invoke(Request $request, string $id, string $hash): View|RedirectResponse
    {
        $user = $this->resolveUser($id, $hash);

        $outcome = $this->verification->verifyAndActivate($user);

        // Already signed in as this same account: keep the session and
        // send them onward through the normal portal resolution rather
        // than making them log in again.
        if ($outcome->accountIsUsable() && $this->isAuthenticatedAs($request, $user)) {
            return $this->redirectAuthenticatedUser($user, $outcome);
        }

        // Anyone else — a guest, or a browser signed in as a DIFFERENT
        // account — gets the public page. The other session is left
        // completely untouched: not replaced, not logged out.
        return view('auth.verified', [
            'outcome' => $outcome,
            'accountIsUsable' => $outcome->accountIsUsable(),
        ]);
    }

    /**
     * Resolves the signed link's subject.
     *
     * Every failure raises the same 403 the `signed` middleware would,
     * so a probe cannot tell an unknown id from a wrong hash, and the
     * response never confirms whether a given account exists.
     */
    private function resolveUser(string $id, string $hash): User
    {
        // A non-numeric id would otherwise be silently cast to 0.
        if (! ctype_digit($id)) {
            $this->reject('non-numeric user id');
        }

        $user = User::query()->find((int) $id);

        if ($user === null) {
            $this->reject('unknown user id');
        }

        // Compared against the CURRENT email, so a link issued for a
        // previous address stops working the moment the address changes.
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            $this->reject('verification hash does not match the current email address');
        }

        return $user;
    }

    private function reject(string $reason): never
    {
        Log::info('Rejected an email-verification link.', ['reason' => $reason]);

        throw new AccessDeniedHttpException('Invalid verification link.');
    }

    private function isAuthenticatedAs(Request $request, User $user): bool
    {
        $current = $request->user();

        return $current !== null && $current->getAuthIdentifier() === $user->getAuthIdentifier();
    }

    private function redirectAuthenticatedUser(User $user, EmailVerificationOutcome $outcome): RedirectResponse
    {
        // Same-browser instructor flow, unchanged: the session-scoped
        // intent is only ever consulted when the session belongs to the
        // account being verified, so it can neither leak across devices
        // nor be relied upon by the cross-device path.
        if (InstructorApplicationIntent::consume()) {
            return redirect()->route('dashboard.instructor.onboarding')
                ->with('success', $outcome->message());
        }

        return redirect(app(PortalResolver::class)->loginRedirect($user))
            ->with('success', $outcome->message());
    }
}
