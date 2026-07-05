<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\InstructorStatus;
use App\Models\Country;
use App\Models\State;
use App\Services\Security\PasswordRuleBuilder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $isCreate = $schema->getLivewire() instanceof CreateRecord;

        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('General')
                            ->icon('heroicon-o-user')
                            ->schema([
                                Section::make('General Information')
                                    ->description('Basic account details for this user.')
                                    ->icon('heroicon-o-information-circle')
                                    ->schema([
                                        Grid::make(2)->schema([
                                            TextInput::make('name')
                                                ->label('Full Name')
                                                ->required()
                                                ->maxLength(255)
                                                ->placeholder('John Doe'),

                                            TextInput::make('email')
                                                ->label('Email Address')
                                                ->email()
                                                ->required()
                                                ->maxLength(255)
                                                ->unique(
                                                    table: 'users',
                                                    column: 'email',
                                                    ignoreRecord: true,
                                                )
                                                ->placeholder('john@example.com'),
                                        ]),

                                        Grid::make(2)->schema([
                                            TextInput::make('password')
                                                ->label('Password')
                                                ->password()
                                                ->revealable()
                                                ->required($isCreate)
                                                ->confirmed()
                                                ->rule(app(PasswordRuleBuilder::class)->build())
                                                ->dehydrated(fn (?string $state): bool => filled($state))
                                                ->dehydrateStateUsing(fn (string $state): string => $state),

                                            TextInput::make('password_confirmation')
                                                ->label('Confirm Password')
                                                ->password()
                                                ->revealable()
                                                ->required($isCreate)
                                                ->dehydrated(false),
                                        ]),

                                        Select::make('status')
                                            ->label('Status')
                                            ->options([
                                                'active' => 'Active',
                                                'inactive' => 'Inactive',
                                            ])
                                            ->default('active')
                                            ->required()
                                            ->native(false),
                                    ]),
                            ]),

                        Tab::make('Profile')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Section::make('Public Profile')
                                    ->description('Headline, designation, and bio shown on this user\'s profile.')
                                    ->icon('heroicon-o-identification')
                                    ->relationship('profile')
                                    ->schema([
                                        Grid::make(2)->schema([
                                            TextInput::make('headline')
                                                ->maxLength(255)
                                                ->placeholder('e.g. Senior Instructor'),

                                            TextInput::make('designation')
                                                ->maxLength(255)
                                                ->placeholder('e.g. Head of Mathematics'),
                                        ]),

                                        TextInput::make('short_bio')
                                            ->label('Short Bio')
                                            ->maxLength(160)
                                            ->helperText('A one-line summary (max 160 characters).')
                                            ->columnSpanFull(),

                                        Textarea::make('bio')
                                            ->label('Full Bio')
                                            ->maxLength(2000)
                                            ->rows(4)
                                            ->columnSpanFull(),

                                        Grid::make(2)->schema([
                                            Select::make('gender')
                                                ->options([
                                                    'male' => 'Male',
                                                    'female' => 'Female',
                                                    'other' => 'Other',
                                                    'prefer_not_to_say' => 'Prefer not to say',
                                                ])
                                                ->native(false),

                                            DatePicker::make('date_of_birth')
                                                ->native(false)
                                                ->maxDate(now()->subYears(5)),
                                        ]),
                                    ]),
                            ]),

                        Tab::make('Address')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                Section::make('Address')
                                    ->description('Contact number and location — integrates with the Countries/States masters.')
                                    ->icon('heroicon-o-map-pin')
                                    ->relationship('profile')
                                    ->schema([
                                        TextInput::make('phone')
                                            ->tel()
                                            ->maxLength(20),

                                        Textarea::make('address')
                                            ->rows(2)
                                            ->maxLength(500)
                                            ->columnSpanFull(),

                                        Grid::make(3)->schema([
                                            Select::make('country_id')
                                                ->label('Country')
                                                ->options(fn () => Country::query()->active()->orderBy('name')->pluck('name', 'id'))
                                                ->searchable()
                                                ->native(false)
                                                ->live()
                                                ->afterStateUpdated(fn ($set) => $set('state_id', null)),

                                            Select::make('state_id')
                                                ->label('State')
                                                ->options(function ($get) {
                                                    $countryId = $get('country_id');

                                                    if (! $countryId) {
                                                        return [];
                                                    }

                                                    return State::query()->active()->where('country_id', $countryId)->orderBy('name')->pluck('name', 'id');
                                                })
                                                ->searchable()
                                                ->native(false),

                                            TextInput::make('city')
                                                ->maxLength(100),
                                        ]),

                                        TextInput::make('postal_code')
                                            ->maxLength(20),
                                    ]),
                            ]),

                        Tab::make('Social Links')
                            ->icon('heroicon-o-link')
                            ->schema([
                                Section::make('Social Links')
                                    ->description('Public links shown on this user\'s profile, if visibility allows.')
                                    ->icon('heroicon-o-link')
                                    ->relationship('profile')
                                    ->schema([
                                        Grid::make(2)->schema([
                                            TextInput::make('website')->url()->maxLength(255),
                                            TextInput::make('facebook')->url()->maxLength(255),
                                            TextInput::make('twitter')->url()->maxLength(255),
                                            TextInput::make('linkedin')->url()->maxLength(255),
                                            TextInput::make('github')->url()->maxLength(255),
                                            TextInput::make('instagram')->url()->maxLength(255),
                                            TextInput::make('youtube')->url()->maxLength(255),
                                        ]),
                                    ]),
                            ]),

                        Tab::make('Media')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Section::make('Avatar & Cover')
                                    ->description('Profile picture and cover banner.')
                                    ->icon('heroicon-o-camera')
                                    ->relationship('profile')
                                    ->schema([
                                        SpatieMediaLibraryFileUpload::make('avatar')
                                            ->collection('avatar')
                                            ->image()
                                            ->imageEditor()
                                            ->circleCropper()
                                            ->maxSize(2048),

                                        SpatieMediaLibraryFileUpload::make('cover')
                                            ->collection('cover')
                                            ->image()
                                            ->imageEditor()
                                            ->maxSize(4096),
                                    ]),
                            ]),

                        Tab::make('Security')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Section::make('Security')
                                    ->description('Password and login-alert controls for this account.')
                                    ->icon('heroicon-o-lock-closed')
                                    ->schema([
                                        Toggle::make('must_change_password')
                                            ->label('Require Password Change on Next Login')
                                            ->helperText('User will be forced to set a new password before accessing the application.')
                                            ->default(false)
                                            ->visible(! $isCreate),

                                        Grid::make(2)->schema([
                                            Toggle::make('login_alerts_enabled')
                                                ->label('Login Alert Emails')
                                                ->default(true),

                                            Toggle::make('new_device_alerts_enabled')
                                                ->label('New Device Alerts')
                                                ->default(true),
                                        ]),
                                    ]),
                            ]),

                        Tab::make('Roles')
                            ->icon('heroicon-o-key')
                            ->schema([
                                Section::make('Assign Roles')
                                    ->description('Select one or more roles to assign to this user.')
                                    ->icon('heroicon-o-key')
                                    ->schema([
                                        Select::make('roles')
                                            ->label('Roles')
                                            ->relationship('roles', 'name')
                                            ->multiple()
                                            ->preload()
                                            ->searchable()
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Instructor')
                            ->icon('heroicon-o-academic-cap')
                            ->visible(fn ($record) => $record && $record->hasRole('instructor'))
                            ->schema([
                                Section::make('Instructor Profile Review')
                                    ->description('Read-only summary of the instructor\'s public-facing profile.')
                                    ->icon('heroicon-o-eye')
                                    ->relationship('profile')
                                    ->schema([
                                        Placeholder::make('profile_visibility_display')
                                            ->label('Profile Visibility')
                                            ->content(fn ($record) => $record?->profile_visibility ?? '—'),

                                        Placeholder::make('bio_preview')
                                            ->label('Bio')
                                            ->content(fn ($record) => $record?->bio ? Str::limit($record->bio, 200) : '—')
                                            ->columnSpanFull(),

                                        Placeholder::make('teaching_experience_summary_preview')
                                            ->label('Teaching Experience')
                                            ->content(fn ($record) => $record?->instructor_teaching_experience_summary ? Str::limit($record->instructor_teaching_experience_summary, 200) : '—')
                                            ->columnSpanFull(),

                                        Placeholder::make('teaching_philosophy_preview')
                                            ->label('Teaching Philosophy')
                                            ->content(fn ($record) => $record?->instructor_teaching_philosophy ? Str::limit($record->instructor_teaching_philosophy, 200) : '—')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Instructor Controls')
                                    ->description('Admin controls for the instructor\'s public profile status and visibility.')
                                    ->icon('heroicon-o-cog-6-tooth')
                                    ->relationship('profile')
                                    ->schema([
                                        Select::make('instructor_status')
                                            ->label('Profile Status')
                                            ->options(InstructorStatus::class)
                                            ->native(false)
                                            ->placeholder('Not set'),

                                        Grid::make(2)->schema([
                                            Toggle::make('is_featured')
                                                ->label('Featured Instructor')
                                                ->helperText('Show this instructor in featured sections.'),

                                            Toggle::make('is_instructor_verified')
                                                ->label('Verified Instructor')
                                                ->helperText('Display a verification badge on the public profile.'),
                                        ]),

                                        TextInput::make('featured_order')
                                            ->label('Featured Order')
                                            ->numeric()
                                            ->minValue(0)
                                            ->helperText('Lower numbers appear first in the featured listing.'),
                                    ]),

                                Section::make('Verification Documents')
                                    ->description('Private documents used for instructor verification.')
                                    ->icon('heroicon-o-document-check')
                                    ->relationship('profile')
                                    ->schema([
                                        Grid::make(2)->schema([
                                            SpatieMediaLibraryFileUpload::make('government_id')
                                                ->label('Government ID')
                                                ->collection('government_id')
                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                                ->maxSize(4096),

                                            SpatieMediaLibraryFileUpload::make('address_proof')
                                                ->label('Address Proof')
                                                ->collection('address_proof')
                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                                ->maxSize(4096),

                                            SpatieMediaLibraryFileUpload::make('education_certificate')
                                                ->label('Education Certificate')
                                                ->collection('education_certificate')
                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                                ->maxSize(4096),

                                            SpatieMediaLibraryFileUpload::make('teaching_certificate')
                                                ->label('Teaching Certificate')
                                                ->collection('teaching_certificate')
                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                                ->maxSize(4096),

                                            SpatieMediaLibraryFileUpload::make('resume')
                                                ->label('Resume')
                                                ->collection('resume')
                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                                ->maxSize(4096),

                                            SpatieMediaLibraryFileUpload::make('introduction_video')
                                                ->label('Introduction Video')
                                                ->collection('introduction_video')
                                                ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime'])
                                                ->maxSize(51200),
                                        ]),
                                    ]),

                                Section::make('Instructor Cover')
                                    ->description('Banner image shown at the top of the instructor\'s public profile page.')
                                    ->icon('heroicon-o-photo')
                                    // No ->relationship() here — instructor_cover lives on the User model directly
                                    ->schema([
                                        SpatieMediaLibraryFileUpload::make('instructor_cover')
                                            ->label('Cover Image')
                                            ->collection('instructor_cover')
                                            ->image()
                                            ->imageEditor()
                                            ->maxSize(4096)
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Student')
                            ->icon('heroicon-o-user-group')
                            ->visible(fn ($record) => $record && $record->hasRole('student'))
                            ->schema([
                                Section::make('Learning Overview')
                                    ->description('Enrollment, progress, and order data will be available in a future phase.')
                                    ->icon('heroicon-o-academic-cap')
                                    ->schema([
                                        Grid::make(3)->schema([
                                            Placeholder::make('enrollments')
                                                ->label('Enrollments')
                                                ->content('—'),

                                            Placeholder::make('certificates')
                                                ->label('Certificates')
                                                ->content('—'),

                                            Placeholder::make('orders')
                                                ->label('Orders')
                                                ->content('—'),
                                        ]),
                                    ]),

                                Section::make('Account Info')
                                    ->icon('heroicon-o-information-circle')
                                    ->schema([
                                        Placeholder::make('member_since')
                                            ->label('Member Since')
                                            ->content(fn ($record) => $record?->created_at?->format('F j, Y') ?? '—'),

                                        Placeholder::make('last_login')
                                            ->label('Last Login')
                                            ->content(fn ($record) => $record?->last_login_at?->diffForHumans() ?? 'Never'),

                                        Placeholder::make('portal')
                                            ->label('Portal')
                                            ->content('Frontend Portal (Student)'),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
