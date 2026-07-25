<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Schemas;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\InstructorWithdrawalRequest;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Message;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/** Admin-operational case creation only (§25.16) — see CreateSupportCase. */
class SupportCaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Case')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('category')
                            ->options(collect(SupportCaseCategory::cases())
                                ->mapWithKeys(fn (SupportCaseCategory $c) => [$c->value => $c->label()]))
                            ->required()
                            ->native(false),
                        Select::make('priority')
                            ->options(collect(SupportCasePriority::cases())
                                ->mapWithKeys(fn (SupportCasePriority $p) => [$p->value => $p->label()]))
                            ->default(SupportCasePriority::Medium->value)
                            ->required()
                            ->native(false),
                    ]),
                    TextInput::make('subject')->required()->maxLength(255),
                    Textarea::make('description')->required()->maxLength(4000)->rows(4),
                ]),

            Section::make('Who this case is about')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('student_id')
                            ->label('Student')
                            ->relationship('student', 'name', fn (Builder $query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'student')))
                            ->searchable()
                            ->preload(),
                        Select::make('instructor_id')
                            ->label('Instructor')
                            ->relationship('instructor', 'name', fn (Builder $query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'instructor')))
                            ->searchable()
                            ->preload(),
                    ]),
                ]),

            Section::make('Linked record (optional)')
                ->description('Staff may link any record — the requester-ownership check only applies to student/instructor self-service cases.')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('linked_record_type')
                            ->label('Type')
                            ->options([
                                Booking::class => 'Booking',
                                Lesson::class => 'Lesson',
                                BookingPayment::class => 'Payment',
                                Invoice::class => 'Invoice',
                                WalletLedgerEntry::class => 'Wallet Transaction',
                                InstructorWithdrawalRequest::class => 'Withdrawal',
                                User::class => 'Instructor',
                                Message::class => 'Message',
                            ])
                            ->native(false),
                        TextInput::make('linked_record_id')
                            ->label('Record ID')
                            ->maxLength(60),
                    ]),
                ]),
        ]);
    }
}
