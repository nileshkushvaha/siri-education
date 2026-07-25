<?php

declare(strict_types=1);

namespace App\Http\Requests\SupportCases;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\InstructorWithdrawalRequest;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * SRS §25.42 Validation Rules: subject/category are required; priority
 * defaults to Medium when not supplied. Linked-record *authorization*
 * (ownership) is enforced by LinkedRecordAuthorizer inside
 * SupportCaseService — this request only validates shape.
 */
class StoreSupportCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', new Enum(SupportCaseCategory::class)],
            'priority' => ['nullable', new Enum(SupportCasePriority::class)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:4000'],
            'linked_record_type' => ['nullable', 'string', Rule::in([
                Booking::class,
                Lesson::class,
                BookingPayment::class,
                Invoice::class,
                WalletLedgerEntry::class,
                InstructorWithdrawalRequest::class,
                User::class,
            ])],
            'linked_record_id' => ['nullable', 'required_with:linked_record_type', 'string', 'max:60'],
        ];
    }
}
