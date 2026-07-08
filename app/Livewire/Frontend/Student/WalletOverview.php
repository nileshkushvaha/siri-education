<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\Wallet;
use App\Wallet\Services\WalletLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorImpl;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only. A wallet is never created just by viewing this page —
 * Phase 9 has no working recharge flow yet, so an empty state is the
 * only safe thing to show a student without one. Recharge is a
 * disabled "coming soon" button, never a Razorpay call.
 *
 * Single-currency assumption (documented, not a bug): this page shows
 * only the student's first wallet with no currency scoping/selector.
 * The schema supports one wallet per currency per user, but the
 * platform is INR-primary (Phase 10 Razorpay is INR-only) and nothing
 * before a future multi-currency recharge flow can give a student a
 * second currency wallet without a deliberate admin action. Add a
 * currency-aware selector here before that changes.
 */
final class WalletOverview extends Component
{
    use WithPagination;

    private WalletLedgerService $ledger;

    public function boot(WalletLedgerService $ledger): void
    {
        $this->ledger = $ledger;
    }

    public function render(): View
    {
        $wallet = Wallet::query()->forUser(auth()->id())->first();

        return view('livewire.frontend.student.wallet-overview', [
            'wallet' => $wallet,
            'entries' => $wallet ? $this->ledger->statement($wallet, 10) : $this->emptyStatement(),
        ]);
    }

    private function emptyStatement(): LengthAwarePaginator
    {
        return new LengthAwarePaginatorImpl([], 0, 10);
    }
}
