<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Country;
use App\Models\State;
use App\Support\Timezone\IanaTimezone;
use App\Support\UserTimezoneResolver;
use BackedEnum;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class AdminProfile extends EditProfile
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    public static function getLabel(): string
    {
        return 'My Profile';
    }

    public function getTitle(): string|Htmlable
    {
        return 'My Profile';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Manage your personal information and account preferences.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '#' => 'My Profile',
        ];
    }

    // ── Email: read-only, never saved ─────────────────────────────────
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email Address')
            ->email()
            ->disabled()
            ->dehydrated(false)
            ->helperText('Email address cannot be changed. Contact your administrator.')
            ->columnSpanFull();
    }

    // ── No password fields here (use Change Password page) ───────────
    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('_pw')->hidden()->dehydrated(false);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('_pwc')->hidden()->dehydrated(false);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('_cpw')->hidden()->dehydrated(false);
    }

    // ── Fill form: merge user + profile data ─────────────────────────
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $profile = $this->getUser()->profile;

        $data['phone'] = $profile->phone;
        $data['gender'] = $profile->gender;
        $data['date_of_birth'] = $profile->date_of_birth?->format('Y-m-d');
        $data['address'] = $profile->address;
        $data['city'] = $profile->city;
        $data['state_id'] = $profile->state_id;
        $data['country_id'] = $profile->country_id;
        $data['postal_code'] = $profile->postal_code;
        $data['timezone'] = $profile->timezone;
        $data['language'] = $profile->language;

        return $data;
    }

    // ── Save: user fields + profile upsert ───────────────────────────
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['email'], $data['_pw'], $data['_pwc'], $data['_cpw']);

        $profileFields = [
            'phone', 'gender', 'date_of_birth',
            'address', 'city', 'state_id', 'country_id', 'postal_code',
            'timezone', 'language',
        ];

        $profileData = [];
        foreach ($profileFields as $field) {
            if (array_key_exists($field, $data)) {
                $profileData[$field] = $data[$field];
                unset($data[$field]);
            }
        }

        $data['name'] = trim(($data['first_name'] ?? $record->first_name ?? '').' '.($data['last_name'] ?? $record->last_name ?? ''));

        $record->update($data);

        if (! empty($profileData)) {
            $record->profile->update($profileData);
        }

        return $record;
    }

    // ── Full form schema ──────────────────────────────────────────────
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // ── Section 1: Account Info ──────────────────────────
                Section::make('Account Information')
                    ->description('Your login email and display name.')
                    ->schema([
                        $this->getEmailFormComponent(),

                        TextInput::make('name')
                            ->label('Display Name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Shown across the admin panel.'),
                    ]),

                // ── Section 2: Personal Details ──────────────────────
                Section::make('Personal Details')
                    ->description('Your name and basic personal information.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('first_name')
                                    ->label('First Name')
                                    ->required()
                                    ->maxLength(100),

                                TextInput::make('last_name')
                                    ->label('Last Name')
                                    ->maxLength(100),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Select::make('gender')
                                    ->label('Gender')
                                    ->options([
                                        'male' => 'Male',
                                        'female' => 'Female',
                                        'other' => 'Other',
                                        'prefer_not_to_say' => 'Prefer not to say',
                                    ])
                                    ->native(false),

                                DatePicker::make('date_of_birth')
                                    ->label('Date of Birth')
                                    ->native(false)
                                    ->maxDate(now()->subYears(16)),
                            ]),

                        TextInput::make('phone')
                            ->label('Phone Number')
                            ->tel()
                            ->maxLength(20),
                    ]),

                // ── Section 3: Location ──────────────────────────────
                Section::make('Location')
                    ->description('Your address and regional settings.')
                    ->schema([
                        Textarea::make('address')
                            ->label('Street Address')
                            ->rows(2)
                            ->maxLength(255),

                        Grid::make(2)
                            ->schema([
                                Select::make('country_id')
                                    ->label('Country')
                                    ->options(Country::query()->active()->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(fn ($set) => $set('state_id', null)),

                                Select::make('state_id')
                                    ->label('State / Province')
                                    ->options(function ($get) {
                                        $countryId = $get('country_id');

                                        if (! $countryId) {
                                            return [];
                                        }

                                        return State::query()->active()->where('country_id', $countryId)->orderBy('name')->pluck('name', 'id');
                                    })
                                    ->searchable()
                                    ->native(false),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('city')
                                    ->label('City')
                                    ->maxLength(100),

                                TextInput::make('postal_code')
                                    ->label('Postal Code')
                                    ->maxLength(20),
                            ]),
                    ]),

                // ── Section 4: Preferences ───────────────────────────
                Section::make('Preferences')
                    ->description('Your timezone and language settings.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('timezone')
                                    ->label('Timezone')
                                    ->options(
                                        collect(IanaTimezone::identifiers())
                                            ->mapWithKeys(fn (string $tz): array => [$tz => $tz])
                                            ->all()
                                    )
                                    ->searchable()
                                    ->native(false)
                                    // TZ-1: an admin who has never set a timezone
                                    // is pre-selected onto the PLATFORM default,
                                    // not onto India. This is a form default only —
                                    // it prefills the control, and the value still
                                    // has to be saved before it means anything.
                                    ->default(fn (): string => UserTimezoneResolver::platformDefault()),

                                Select::make('language')
                                    ->label('Language')
                                    ->options([
                                        'en' => 'English',
                                        'hi' => 'Hindi',
                                        'fr' => 'French',
                                        'de' => 'German',
                                        'es' => 'Spanish',
                                        'ar' => 'Arabic',
                                        'zh' => 'Chinese',
                                        'ja' => 'Japanese',
                                    ])
                                    ->native(false)
                                    ->default('en'),
                            ]),
                    ]),
            ]);
    }
}
