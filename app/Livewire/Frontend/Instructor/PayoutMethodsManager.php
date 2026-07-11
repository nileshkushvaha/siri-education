<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\DTOs\PayoutMethodDetails;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Exceptions\PayoutMethodException;
use App\Models\Country;
use App\Models\Currency;
use App\Models\InstructorPayoutMethod;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Instructor self-service payout methods (bank transfer, Phase 15).
 * Every mutation goes through InstructorPayoutMethodService after a
 * policy check on a server-resolved, ownership-scoped record — nothing
 * from the browser is trusted. Sensitive fields exist here only while
 * the instructor is typing them: stored values are NEVER loaded back
 * into these properties (editing a rejected method requires re-entering
 * them), and they are cleared the moment the form closes.
 */
final class PayoutMethodsManager extends Component
{
    use AuthorizesRequests;

    public bool $showForm = false;

    /** Editing target (draft/rejected only); null = creating. */
    public ?string $editingMethodId = null;

    public ?int $country_id = null;

    public string $currency_code = '';

    // ── Sensitive input (write-only; never repopulated from storage) ──
    public string $account_holder_name = '';

    public string $bank_name = '';

    public string $account_number = '';

    public string $routing_type = 'ifsc';

    public string $routing_number = '';

    public string $iban = '';

    public string $swift_bic = '';

    public string $branch_name = '';

    public string $account_type = '';

    private InstructorPayoutMethodServiceInterface $service;

    public function boot(InstructorPayoutMethodServiceInterface $service): void
    {
        $this->service = $service;
    }

    public function startCreate(): void
    {
        $this->authorize('create', InstructorPayoutMethod::class);

        $this->resetForm();
        $this->currency_code = Currency::query()->active()->orderBy('sort_order')->value('code') ?? 'INR';
        $this->showForm = true;
    }

    /** Correction flow: context fields prefill, sensitive fields stay blank. */
    public function startEdit(string $methodId): void
    {
        $method = $this->ownMethod($methodId);

        $this->authorize('update', $method);

        $this->resetForm();
        $this->editingMethodId = $method->id;
        $this->country_id = $method->country_id;
        $this->currency_code = $method->currency_code;
        $this->showForm = true;
    }

    public function save(): void
    {
        if (! RateLimiter::attempt('payout-methods:'.auth()->id(), 10, fn () => null)) {
            $this->addError('form', 'Too many attempts — please wait a minute and try again.');

            return;
        }

        $this->validate([
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'currency_code' => ['required', 'string', 'size:3'],
            'account_holder_name' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['required_without:iban', 'nullable', 'string', 'max:64'],
            'routing_type' => ['nullable', 'string', 'max:32'],
            'routing_number' => ['required_with:account_number', 'nullable', 'string', 'max:32'],
            'iban' => ['required_without:account_number', 'nullable', 'string', 'max:64'],
            'swift_bic' => ['nullable', 'string', 'max:16'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'account_type' => ['nullable', 'string', 'max:32'],
        ]);

        $details = new PayoutMethodDetails(
            accountHolderName: $this->account_holder_name,
            bankName: $this->bank_name !== '' ? $this->bank_name : null,
            accountNumber: $this->account_number !== '' ? $this->account_number : null,
            iban: $this->iban !== '' ? $this->iban : null,
            routingType: $this->routing_type !== '' ? $this->routing_type : null,
            routingNumber: $this->routing_number !== '' ? $this->routing_number : null,
            swiftBic: $this->swift_bic !== '' ? $this->swift_bic : null,
            branchName: $this->branch_name !== '' ? $this->branch_name : null,
            accountType: $this->account_type !== '' ? $this->account_type : null,
        );

        try {
            if ($this->editingMethodId !== null) {
                $method = $this->ownMethod($this->editingMethodId);
                $this->authorize('update', $method);
                $this->service->updateDraft($method, $this->country_id, $this->currency_code, $details);
            } else {
                $this->authorize('create', InstructorPayoutMethod::class);
                $this->service->createDraft(auth()->user(), PayoutMethodType::BankTransfer, $this->country_id, $this->currency_code, $details);
            }
        } catch (PayoutMethodException $e) {
            $this->addError('form', $e->getMessage());

            return;
        } finally {
            // Sensitive values must not survive in component state.
            $this->clearSensitiveFields();
        }

        $this->resetForm();
        session()->flash('payout-methods-status', 'Payout method saved as draft. Submit it for verification when ready.');
    }

    public function submitForVerification(string $methodId): void
    {
        $method = $this->ownMethod($methodId);

        $this->authorize('submit', $method);

        try {
            $this->service->submitForVerification($method, auth()->user());
            session()->flash('payout-methods-status', 'Payout method submitted for verification.');
        } catch (PayoutMethodException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function makeDefault(string $methodId): void
    {
        $method = $this->ownMethod($methodId);

        $this->authorize('setDefault', $method);

        try {
            $this->service->setDefault($method, auth()->user());
            session()->flash('payout-methods-status', 'Default payout method updated.');
        } catch (PayoutMethodException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function disableMethod(string $methodId): void
    {
        $method = $this->ownMethod($methodId);

        $this->authorize('disable', $method);

        try {
            $this->service->disable($method, auth()->user());
            session()->flash('payout-methods-status', 'Payout method disabled.');
        } catch (PayoutMethodException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.frontend.instructor.payout-methods-manager', [
            'methods' => InstructorPayoutMethod::query()
                ->forInstructor((int) auth()->id())
                ->with('country')
                ->orderByDesc('created_at')
                ->get(),
            'countries' => Country::query()->active()->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::query()->active()->orderBy('sort_order')->get(['id', 'code', 'name']),
        ]);
    }

    /** Ownership-scoped lookup — a foreign ID 404s instead of leaking. */
    private function ownMethod(string $methodId): InstructorPayoutMethod
    {
        return InstructorPayoutMethod::query()
            ->forInstructor((int) auth()->id())
            ->findOrFail($methodId);
    }

    private function resetForm(): void
    {
        $this->reset(['showForm', 'editingMethodId', 'country_id', 'currency_code']);
        $this->clearSensitiveFields();
        $this->resetErrorBag();
    }

    private function clearSensitiveFields(): void
    {
        $this->reset([
            'account_holder_name', 'bank_name', 'account_number', 'routing_type',
            'routing_number', 'iban', 'swift_bic', 'branch_name', 'account_type',
        ]);
    }
}
