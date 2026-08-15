<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Pages\Settings\InstructorEarningSettingsPage;
use App\Filament\Resources\Academic\AcademicCategoryResource;
use App\Filament\Resources\Academic\InstructorSubjectTopicResource;
use App\Filament\Resources\Academic\SubjectResource;
use App\Filament\Resources\Academic\SubjectTopicResource;
use App\Filament\Resources\BookingPaymentReconciliationIssues\BookingPaymentReconciliationIssueResource;
use App\Filament\Resources\BookingPayments\BookingPaymentResource;
use App\Filament\Resources\InstructorCompensationAgreements\InstructorCompensationAgreementResource;
use App\Filament\Resources\InstructorCompensationExceptions\InstructorCompensationExceptionResource;
use App\Filament\Resources\InstructorEarnings\InstructorEarningResource;
use App\Filament\Resources\InstructorPackageProposals\InstructorPackageProposalResource;
use App\Filament\Resources\InstructorPayoutAttempts\InstructorPayoutAttemptResource;
use App\Filament\Resources\InstructorPayoutMethods\InstructorPayoutMethodResource;
use App\Filament\Resources\InstructorPayoutReconciliationIssues\InstructorPayoutReconciliationIssueResource;
use App\Filament\Resources\InstructorSettlementBatches\InstructorSettlementBatchResource;
use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PackageBenefitRules\PackageBenefitRuleResource;
use App\Filament\Resources\PaymentReconciliationIssues\PaymentReconciliationIssueResource;
use App\Filament\Resources\StudentPackageEntitlements\StudentPackageEntitlementResource;
use App\Filament\Resources\StudentPackagePurchases\StudentPackagePurchaseResource;

final class RelatedResourceLinkGroups
{
    /** @return array<int, array{label: string, url: string}> */
    public static function subjects(): array
    {
        return self::visible([
            [SubjectResource::canViewAny(), 'Subjects', SubjectResource::getUrl()],
            [AcademicCategoryResource::canViewAny(), 'Academic Categories', AcademicCategoryResource::getUrl()],
            [SubjectTopicResource::canViewAny(), 'Subject Topics', SubjectTopicResource::getUrl()],
            [InstructorSubjectTopicResource::canViewAny(), 'Instructor Topic Coverage', InstructorSubjectTopicResource::getUrl()],
        ]);
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function paymentCollection(): array
    {
        return self::visible([
            [BookingPaymentResource::canViewAny(), 'Payments', BookingPaymentResource::getUrl()],
            [BookingPaymentReconciliationIssueResource::canViewAny(), 'Lesson Payment Reconciliation', BookingPaymentReconciliationIssueResource::getUrl()],
            [PaymentReconciliationIssueResource::canViewAny(), 'Package Payment Discrepancies', PaymentReconciliationIssueResource::getUrl()],
            [InvoiceResource::canViewAny(), 'Invoices', InvoiceResource::getUrl()],
        ]);
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function packages(): array
    {
        return self::visible([
            [PackageBenefitRuleResource::canViewAny(), 'Package Offers', PackageBenefitRuleResource::getUrl()],
            [InstructorPackageProposalResource::canViewAny(), 'Proposal Reviews', InstructorPackageProposalResource::getUrl()],
            [StudentPackagePurchaseResource::canViewAny(), 'Package Payments', StudentPackagePurchaseResource::getUrl()],
            [StudentPackageEntitlementResource::canViewAny(), 'Student Lesson Balances', StudentPackageEntitlementResource::getUrl()],
        ]);
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function instructorFinance(): array
    {
        return self::visible([
            [InstructorCompensationAgreementResource::canViewAny(), 'Compensation Agreements', InstructorCompensationAgreementResource::getUrl()],
            [InstructorCompensationExceptionResource::canViewAny(), 'Compensation Exceptions', InstructorCompensationExceptionResource::getUrl()],
            [InstructorEarningResource::canViewAny(), 'Instructor Earnings', InstructorEarningResource::getUrl()],
            [InstructorSettlementBatchResource::canViewAny(), 'Settlement Batches', InstructorSettlementBatchResource::getUrl()],
            [InstructorWithdrawalRequestResource::canViewAny(), 'Withdrawal Requests', InstructorWithdrawalRequestResource::getUrl()],
            [InstructorPayoutMethodResource::canViewAny(), 'Payout Methods', InstructorPayoutMethodResource::getUrl()],
            [InstructorPayoutAttemptResource::canViewAny(), 'Payout Attempts', InstructorPayoutAttemptResource::getUrl()],
            [InstructorPayoutReconciliationIssueResource::canViewAny(), 'Payout Reconciliation', InstructorPayoutReconciliationIssueResource::getUrl()],
            [InstructorEarningSettingsPage::canAccess(), 'Earnings Rules', InstructorEarningSettingsPage::getUrl()],
        ]);
    }

    /**
     * @param  array<int, array{0: bool, 1: string, 2: string}>  $links
     * @return array<int, array{label: string, url: string}>
     */
    private static function visible(array $links): array
    {
        return array_values(array_map(
            fn (array $link): array => ['label' => $link[1], 'url' => $link[2]],
            array_filter($links, fn (array $link): bool => $link[0]),
        ));
    }
}
