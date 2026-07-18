<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the Phase 23L boundary: instructor finance visibility is a
 * pure read layer over the existing Earnings/Settlement domain — no
 * duplicate financial model/service, no mutation route, no payout
 * execution/gateway/wallet/commission logic touched.
 */
final class Phase23LArchitectureTest extends TestCase
{
    public function test_no_duplicate_financial_domain_was_created(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/InstructorIncome.php'));
        $this->assertFileDoesNotExist(app_path('Models/TeacherEarning.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorFinanceService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorSettlementService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorFinanceDashboardService.php'));
        $this->assertFileDoesNotExist(app_path('Earnings/Enums/InstructorSettlementStatus.php'));

        // Exactly one concrete implementation of the earning repository contract.
        $this->assertFileExists(app_path('Earnings/Repositories/InstructorEarningRepository.php'));
        $matches = [];
        foreach (glob(app_path('Earnings/Repositories/*.php')) as $file) {
            if (str_contains(basename($file), 'InstructorEarningRepository')) {
                $matches[] = $file;
            }
        }
        $this->assertCount(1, $matches);
    }

    public function test_finance_read_layer_extends_the_existing_repository_contract(): void
    {
        $interface = file_get_contents(app_path('Earnings/Contracts/InstructorEarningRepositoryInterface.php'));
        $this->assertIsString($interface);
        $this->assertStringContainsString('paginatedForInstructor', $interface);
        $this->assertStringContainsString('summaryForInstructor', $interface);

        // Only one DTO of this name exists, in the existing instructor DTO namespace.
        $this->assertFileExists(app_path('DTOs/InstructorDashboard/InstructorFinanceSummaryData.php'));
        $duplicates = 0;
        foreach ($this->phpFilesUnder(app_path('DTOs')) as $file) {
            if (basename($file) === 'InstructorFinanceSummaryData.php') {
                $duplicates++;
            }
        }
        $this->assertSame(1, $duplicates);
    }

    public function test_finance_pages_are_read_only_no_mutation_methods(): void
    {
        $earningsComponent = file_get_contents(app_path('Livewire/Frontend/Instructor/EarningsOverview.php'));
        $settlementsComponent = file_get_contents(app_path('Livewire/Frontend/Instructor/SettlementsOverview.php'));
        $this->assertIsString($earningsComponent);
        $this->assertIsString($settlementsComponent);

        foreach ([$earningsComponent, $settlementsComponent] as $component) {
            $this->assertStringNotContainsString('->save()', $component);
            $this->assertStringNotContainsString('::create(', $component);
            $this->assertStringNotContainsString('->update(', $component);
            $this->assertStringNotContainsString('->delete(', $component);
            $this->assertStringNotContainsString('release(', $component);
            $this->assertStringNotContainsString('reverse(', $component);
            $this->assertStringNotContainsString('markSettlementBatchPaid', $component);
            $this->assertStringNotContainsString('approveSettlementBatch', $component);
            $this->assertStringNotContainsString('createSettlementBatch', $component);
        }

        // Only boot() (DI wiring) and render() exist — no action methods.
        $this->assertSame(2, preg_match_all('/public function \w+\(/', $earningsComponent));
        $this->assertSame(1, preg_match_all('/public function \w+\(/', $settlementsComponent));
    }

    public function test_no_payment_gateway_wallet_or_payout_execution_code_was_touched(): void
    {
        $earningsComponent = file_get_contents(app_path('Livewire/Frontend/Instructor/EarningsOverview.php'));
        $settlementsComponent = file_get_contents(app_path('Livewire/Frontend/Instructor/SettlementsOverview.php'));

        foreach ([$earningsComponent, $settlementsComponent] as $component) {
            $this->assertStringNotContainsString('Razorpay', $component);
            $this->assertStringNotContainsString('Stripe', $component);
            $this->assertStringNotContainsString('Wallet', $component);
            $this->assertStringNotContainsString('InstructorPayoutExecutionServiceInterface', $component);
            $this->assertStringNotContainsString('InstructorWithdrawalServiceInterface', $component);
        }
    }

    public function test_finance_pages_query_the_model_scoped_to_the_instructor(): void
    {
        $repository = file_get_contents(app_path('Earnings/Repositories/InstructorEarningRepository.php'));
        $this->assertIsString($repository);
        $this->assertStringContainsString('paginatedForInstructor', $repository);
        $this->assertStringContainsString('->forInstructor($instructorId)', $repository);

        $settlementsComponent = file_get_contents(app_path('Livewire/Frontend/Instructor/SettlementsOverview.php'));
        $this->assertIsString($settlementsComponent);
        $this->assertStringContainsString('forInstructor($instructorId)', $settlementsComponent);
    }

    public function test_earnings_list_is_paginated_and_eager_loaded(): void
    {
        $repository = file_get_contents(app_path('Earnings/Repositories/InstructorEarningRepository.php'));
        $this->assertIsString($repository);

        $method = substr($repository, (int) strpos($repository, 'function paginatedForInstructor'));
        $method = substr($method, 0, (int) strpos($method, "\n    }"));

        $this->assertStringContainsString('->paginate($perPage)', $method);
        $this->assertStringContainsString("->with([", $method);
        $this->assertStringNotContainsString('->get()', $method);
    }

    public function test_navigation_adds_earnings_and_settlements_without_removing_existing_items(): void
    {
        $menu = file_get_contents(app_path('Services/Account/AccountMenuService.php'));
        $this->assertIsString($menu);

        $this->assertStringContainsString("'Earnings', 'dashboard.instructor.earnings'", $menu);
        $this->assertStringContainsString("'Settlements', 'dashboard.instructor.settlements'", $menu);
        $this->assertStringContainsString("'Payout Methods', 'dashboard.instructor.payout-methods'", $menu);
        $this->assertStringContainsString("'Withdrawals', 'dashboard.instructor.withdrawals'", $menu);
    }

    public function test_dashboard_finance_widget_reuses_the_existing_dashboard_service(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorDashboardService.php'));
        $this->assertIsString($service);

        // The earnings() aggregation method is untouched by this phase —
        // still exactly one private earnings() computation, no second one.
        $this->assertSame(1, substr_count($service, 'private function earnings('));

        $view = file_get_contents(resource_path('views/livewire/frontend/instructor/dashboard-overview.blade.php'));
        $this->assertIsString($view);
        $this->assertStringContainsString("route('dashboard.instructor.earnings')", $view);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
