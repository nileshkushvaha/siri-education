<?php

declare(strict_types=1);

namespace App\Reporting\Filters;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\SettlementBatchStatus;
use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Reporting\Enums\ReportingBookingType;
use App\Reporting\Enums\ReportingRecurrenceType;
use App\Reporting\Exceptions\UnsupportedReportFilterException;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Enums\StudentReviewStatus;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletLedgerStatus;

/**
 * Shared, immutable, typed report filter set (SRS §8). Carries
 * only safe scalars/enums/ids — never a hydrated Eloquent model — so it
 * can be logged, serialized to a query string, or round-tripped through
 * a Livewire component without any risk of leaking a full record.
 *
 * A report definition declares which of these dimensions it supports
 * (`ReportDefinition::$supportedFilters`); `restrictedTo()` enforces
 * that containment by nulling out everything else, so a filter can
 * never broaden a report beyond what its own definition allows.
 */
final readonly class ReportFilters
{
    /** @var array<string, ReportFilterKey> property name => filter key, for the optional (non-period) dimensions only. */
    private const array KEY_MAP = [
        'countryId' => ReportFilterKey::Country,
        'currencyCode' => ReportFilterKey::Currency,
        'subjectId' => ReportFilterKey::Subject,
        'educationLevelId' => ReportFilterKey::EducationLevel,
        'studentId' => ReportFilterKey::Student,
        'instructorId' => ReportFilterKey::Instructor,
        'studentStatus' => ReportFilterKey::StudentStatus,
        'instructorStatus' => ReportFilterKey::InstructorStatus,
        'bookingType' => ReportFilterKey::BookingType,
        'recurrenceType' => ReportFilterKey::RecurrenceType,
        'bookingStatus' => ReportFilterKey::BookingStatus,
        'lessonStatus' => ReportFilterKey::LessonStatus,
        'lessonOutcome' => ReportFilterKey::LessonOutcome,
        'meetingStatus' => ReportFilterKey::MeetingStatus,
        'paymentStatus' => ReportFilterKey::PaymentStatus,
        'walletTransactionType' => ReportFilterKey::WalletTransactionType,
        'walletTransactionStatus' => ReportFilterKey::WalletTransactionStatus,
        'earningStatus' => ReportFilterKey::EarningStatus,
        'settlementStatus' => ReportFilterKey::SettlementStatus,
        'withdrawalStatus' => ReportFilterKey::WithdrawalStatus,
        'reviewStatus' => ReportFilterKey::ReviewStatus,
        'reviewReportStatus' => ReportFilterKey::ReviewReportStatus,
        'qualityAlertStatus' => ReportFilterKey::QualityAlertStatus,
        'learningPlanStatus' => ReportFilterKey::LearningPlanStatus,
        'learningGoalStatus' => ReportFilterKey::LearningGoalStatus,
        'homeworkStatus' => ReportFilterKey::HomeworkStatus,
    ];

    public function __construct(
        public ReportingPeriod $period,
        public ?int $countryId = null,
        public ?string $currencyCode = null,
        // Subjects/academic levels use UUID primary keys — these are id
        // strings, never auto-increment ints.
        public ?string $subjectId = null,
        public ?string $educationLevelId = null,
        public ?int $studentId = null,
        public ?int $instructorId = null,
        public ?StudentStatus $studentStatus = null,
        public ?InstructorStatus $instructorStatus = null,
        public ?ReportingBookingType $bookingType = null,
        public ?ReportingRecurrenceType $recurrenceType = null,
        public ?BookingStatus $bookingStatus = null,
        public ?LessonStatus $lessonStatus = null,
        public ?LessonOutcome $lessonOutcome = null,
        public ?MeetingStatus $meetingStatus = null,
        public ?BookingPaymentStatus $paymentStatus = null,
        public ?WalletLedgerEntryType $walletTransactionType = null,
        public ?WalletLedgerStatus $walletTransactionStatus = null,
        public ?InstructorEarningStatus $earningStatus = null,
        public ?SettlementBatchStatus $settlementStatus = null,
        public ?InstructorWithdrawalStatus $withdrawalStatus = null,
        public ?StudentReviewStatus $reviewStatus = null,
        public ?ReviewReportStatus $reviewReportStatus = null,
        public ?InstructorQualityAlertStatus $qualityAlertStatus = null,
        public ?LearningPlanStatus $learningPlanStatus = null,
        public ?LearningGoalStatus $learningGoalStatus = null,
        public ?HomeworkStatus $homeworkStatus = null,
    ) {}

    /**
     * Returns a copy with only the declared-supported dimensions
     * preserved — everything else is nulled out. This is what makes it
     * structurally impossible for a filter to broaden a report beyond
     * its own definition, independent of whatever a caller supplied.
     *
     * @param  list<ReportFilterKey>  $supportedKeys
     */
    public function restrictedTo(array $supportedKeys): self
    {
        $values = ['period' => $this->period];

        foreach (self::KEY_MAP as $property => $key) {
            $values[$property] = in_array($key, $supportedKeys, true) ? $this->{$property} : null;
        }

        return new self(...$values);
    }

    /** @return array<string, mixed> safe scalars only — never a hydrated model. */
    public function toSafeArray(): array
    {
        $out = [
            'period' => [
                'preset' => $this->period->preset->value,
                'start' => $this->period->start->toDateString(),
                'end' => $this->period->end->subDay()->toDateString(),
                'timezone' => $this->period->timezone,
            ],
        ];

        foreach (self::KEY_MAP as $property => $key) {
            $value = $this->{$property};

            if ($value === null) {
                continue;
            }

            $out[$key->value] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $out;
    }

    /**
     * Restores a `ReportFilters` from a safe array (e.g. Livewire state
     * or a query string) — the `$period` is always supplied separately
     * since it is computed, never trusted raw from client input for its
     * UTC boundaries. Any recognized key with a value that fails to
     * resolve to a valid enum case throws — an unknown enum value never
     * fails safe-by-silently-ignoring, it fails loud. Keys not present
     * in `ReportFilterKey` at all are ignored (not every filter a
     * caller sends need be one this contract recognizes).
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws UnsupportedReportFilterException
     */
    public static function fromSafeArray(ReportingPeriod $period, array $raw): self
    {
        $values = ['period' => $period];

        foreach (self::KEY_MAP as $property => $key) {
            $value = $raw[$key->value] ?? null;
            $values[$property] = $value === null ? null : self::castValue($key, $property, $value);
        }

        return new self(...$values);
    }

    private static function castValue(ReportFilterKey $key, string $property, mixed $value): mixed
    {
        $enumClass = match ($key) {
            ReportFilterKey::StudentStatus => StudentStatus::class,
            ReportFilterKey::InstructorStatus => InstructorStatus::class,
            ReportFilterKey::BookingType => ReportingBookingType::class,
            ReportFilterKey::RecurrenceType => ReportingRecurrenceType::class,
            ReportFilterKey::BookingStatus => BookingStatus::class,
            ReportFilterKey::LessonStatus => LessonStatus::class,
            ReportFilterKey::LessonOutcome => LessonOutcome::class,
            ReportFilterKey::MeetingStatus => MeetingStatus::class,
            ReportFilterKey::PaymentStatus => BookingPaymentStatus::class,
            ReportFilterKey::WalletTransactionType => WalletLedgerEntryType::class,
            ReportFilterKey::WalletTransactionStatus => WalletLedgerStatus::class,
            ReportFilterKey::EarningStatus => InstructorEarningStatus::class,
            ReportFilterKey::SettlementStatus => SettlementBatchStatus::class,
            ReportFilterKey::WithdrawalStatus => InstructorWithdrawalStatus::class,
            ReportFilterKey::ReviewStatus => StudentReviewStatus::class,
            ReportFilterKey::ReviewReportStatus => ReviewReportStatus::class,
            ReportFilterKey::QualityAlertStatus => InstructorQualityAlertStatus::class,
            ReportFilterKey::LearningPlanStatus => LearningPlanStatus::class,
            ReportFilterKey::LearningGoalStatus => LearningGoalStatus::class,
            ReportFilterKey::HomeworkStatus => HomeworkStatus::class,
            ReportFilterKey::Country, ReportFilterKey::Subject, ReportFilterKey::EducationLevel,
            ReportFilterKey::Student, ReportFilterKey::Instructor => null,
            ReportFilterKey::Currency => null,
        };

        if ($enumClass === null) {
            // Subject/education-level ids are UUID strings; the rest are ints.
            return in_array($property, ['countryId', 'studentId', 'instructorId'], true)
                ? (int) $value
                : (string) $value;
        }

        $cast = $enumClass::tryFrom((string) $value);

        if ($cast === null) {
            throw UnsupportedReportFilterException::unknownValue($key->value, (string) $value);
        }

        return $cast;
    }
}
