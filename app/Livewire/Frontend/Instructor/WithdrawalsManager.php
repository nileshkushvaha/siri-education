<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Exceptions\WithdrawalException;
use App\Earnings\Support\InstructorPayoutEligibility;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Support\MoneyFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Instructor withdrawal requests (no money moves here).
 * The browser only ever chooses a payout method (ownership-verified
 * server-side) and types an amount; balance, currency, limits, and
 * eligibility are all recalculated by InstructorWithdrawalService
 * inside a locked transaction. The idempotency key is minted when the
 * form opens, so a double click / retry of the same form submission
 * cannot create two requests.
 */
final class WithdrawalsManager extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public bool $showForm = false;

    public bool $confirming = false;

    public string $payoutMethodId = '';

    public string $amount = '';

    public string $note = '';

    /** Minted server-side when the form opens; locked thereafter. */
    public string $idempotencyKey = '';

    /** Whether the instructor may request a withdrawal right now — drives the "Request withdrawal" button visibility. */
    public bool $eligible = false;

    /** UI-safe reason when $eligible is false; null once eligible. */
    public ?string $ineligibilityReason = null;

    private InstructorWithdrawalServiceInterface $withdrawals;

    private InstructorWithdrawalBalanceServiceInterface $balances;

    public function boot(
        InstructorWithdrawalServiceInterface $withdrawals,
        InstructorWithdrawalBalanceServiceInterface $balances,
        InstructorPayoutEligibility $eligibility,
    ): void {
        $this->withdrawals = $withdrawals;
        $this->balances = $balances;
        $this->eligible = $eligibility->isEligible(auth()->user());
        $this->ineligibilityReason = $eligibility->reasonForIneligibility(auth()->user());
    }

    public function openForm(): void
    {
        if (! $this->authorizeOrDeny('create', InstructorWithdrawalRequest::class)) {
            return;
        }

        $this->reset(['confirming', 'payoutMethodId', 'amount', 'note']);
        $this->resetErrorBag();
        $this->idempotencyKey = (string) Str::uuid();

        $default = $this->usableMethods()->firstWhere('is_default', true) ?? $this->usableMethods()->first();
        $this->payoutMethodId = $default?->id ?? '';
        $this->showForm = true;
    }

    /** Step 1 → 2: validate the form and show the masked confirmation. */
    public function confirm(): void
    {
        $this->validateForm();
        $this->confirming = true;
    }

    public function back(): void
    {
        $this->confirming = false;
    }

    public function submit(): void
    {
        if (! RateLimiter::attempt('withdrawals:'.auth()->id(), 5, fn () => null)) {
            $this->addError('form', 'Too many attempts — please wait a minute and try again.');

            return;
        }

        [$method, $amountMinor] = $this->validateForm();

        try {
            $this->withdrawals->requestWithdrawal(
                auth()->user(),
                $method,
                $amountMinor,
                $this->note !== '' ? $this->note : null,
                $this->idempotencyKey,
            );
        } catch (WithdrawalException $e) {
            $this->confirming = false;
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->reset(['showForm', 'confirming', 'payoutMethodId', 'amount', 'note', 'idempotencyKey']);
        session()->flash('withdrawals-status', 'Withdrawal request submitted. We will notify you as it progresses.');
    }

    public function cancelRequest(string $withdrawalId): void
    {
        $withdrawal = InstructorWithdrawalRequest::query()
            ->forInstructor((int) auth()->id())
            ->findOrFail($withdrawalId);

        if (! $this->authorizeOrDeny('cancel', $withdrawal)) {
            return;
        }

        try {
            $this->withdrawals->cancelByInstructor($withdrawal, auth()->user());
            session()->flash('withdrawals-status', 'Withdrawal request cancelled — the amount is available again.');
        } catch (WithdrawalException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function cancelForm(): void
    {
        $this->reset(['showForm', 'confirming', 'payoutMethodId', 'amount', 'note', 'idempotencyKey']);
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $user = auth()->user();

        $balances = collect($this->balances->currenciesWithBalance($user))
            ->map(fn (string $code) => $this->balances->calculate($user, $code));

        return view('livewire.frontend.instructor.withdrawals-manager', [
            'balances' => $balances,
            'methods' => $this->usableMethods(),
            'withdrawals' => InstructorWithdrawalRequest::query()
                ->forInstructor((int) $user->id)
                ->orderByDesc('requested_at')
                ->paginate(10),
            'selectedMethod' => $this->payoutMethodId !== ''
                ? $this->usableMethods()->firstWhere('id', $this->payoutMethodId)
                : null,
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Runs a policy check without letting a denial surface as an
     * uncaught AuthorizationException (a raw 403 error page) — the UI
     * already hides actions an ineligible/non-owning instructor
     * shouldn't reach, so a denial here means the browser state is
     * stale or was tampered with; report it inline like any other
     * form error instead of crashing.
     */
    private function authorizeOrDeny(string $ability, mixed $arg): bool
    {
        try {
            $this->authorize($ability, $arg);

            return true;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage() ?: 'You are not authorized to perform this action.');

            return false;
        }
    }

    /** @return array{0: InstructorPayoutMethod, 1: int} */
    private function validateForm(): array
    {
        $this->validate([
            'payoutMethodId' => ['required', 'string'],
            'amount' => ['required', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Ownership-scoped, verified-only lookup — a foreign or unusable
        // method 404s/fails server-side no matter what the browser sent.
        $method = InstructorPayoutMethod::query()
            ->forInstructor((int) auth()->id())
            ->usable()
            ->findOrFail($this->payoutMethodId);

        // Currency-aware conversion: precision beyond the currency's
        // minor units is rejected, never truncated. Integer math only.
        try {
            $amountMinor = MoneyFormatter::toMinor($this->amount, MoneyFormatter::minorUnitsFor($method->currency_code));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return [$method, $amountMinor];
    }

    private function usableMethods()
    {
        return InstructorPayoutMethod::query()
            ->forInstructor((int) auth()->id())
            ->where('status', PayoutMethodStatus::Verified)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
    }
}
