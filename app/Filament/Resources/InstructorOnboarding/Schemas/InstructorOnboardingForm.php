<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorOnboarding\Schemas;

use App\Enums\InstructorResponseTime;
use App\Filament\Components\InstructorDocumentViewer;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorOnboardingService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class InstructorOnboardingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Placeholder::make('application_review_overview')
                ->hiddenLabel()
                ->content(fn (?User $record) => $record !== null
                    ? new HtmlString(view('filament.components.instructor-review-overview', [
                        'record' => $record,
                        'progress' => app(InstructorOnboardingService::class)->progress($record),
                    ])->render())
                    : null)
                ->columnSpanFull(),

            Section::make('Professional Profile')
                ->description('Review the information students will use to evaluate this instructor. These fields are read-only here.')
                ->icon('heroicon-o-eye')
                ->relationship('profile')
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('profile_visibility_display')
                        ->label('Marketplace visibility')
                        ->content(fn ($record) => filled($record?->profile_visibility) ? str($record->profile_visibility)->headline() : 'Not configured'),

                    Placeholder::make('bio_preview')
                        ->label('Public biography')
                        ->content(fn ($record) => self::preview($record?->bio, 'No biography provided.'))
                        ->columnSpanFull(),

                    Placeholder::make('teaching_experience_summary_preview')
                        ->label('Teaching experience summary')
                        ->content(fn ($record) => self::preview($record?->instructor_teaching_experience_summary, 'No teaching experience summary provided.'))
                        ->columnSpanFull(),

                    Placeholder::make('teaching_philosophy_preview')
                        ->label('Teaching approach')
                        ->content(fn ($record) => self::preview($record?->instructor_teaching_philosophy, 'No teaching approach provided.'))
                        ->columnSpanFull(),
                ]),

            Section::make('Marketplace Controls')
                ->description('Manage discovery and public-profile presentation. These controls do not approve the application or change its lifecycle status.')
                ->icon('heroicon-o-adjustments-horizontal')
                ->relationship('profile')
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('instructor_status_display')
                        ->label('Application lifecycle')
                        ->content(fn ($record) => $record?->instructor_status?->label() ?? 'Not started'),

                    Grid::make(2)->schema([
                        Toggle::make('is_featured')
                            ->label('Feature in discovery')
                            ->helperText('Include this instructor in curated and featured marketplace sections.'),

                        Toggle::make('is_instructor_verified')
                            ->label('Show verified badge')
                            ->helperText('Display the public verification badge. Use only after evidence review.'),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('featured_order')
                            ->label('Featured position')
                            ->numeric()
                            ->minValue(0)
                            ->placeholder('e.g. 10')
                            ->helperText('Optional. Lower numbers rank earlier among featured instructors.'),

                        Select::make('response_time_minutes')
                            ->label('Public response time')
                            ->options(InstructorResponseTime::options())
                            ->native(false)
                            ->placeholder('Not displayed')
                            ->helperText('Public expectation shown as “Usually responds within …”.'),
                    ]),

                    Toggle::make('offers_demo')
                        ->label('Offer a free demo')
                        ->helperText('Show the “Book a Demo” action when demo booking is available.'),
                ]),

            Section::make('Verification Evidence')
                ->description('Private, access-controlled evidence used to confirm identity and professional claims. Downloads are authorized and audited.')
                ->icon('heroicon-o-document-check')
                ->relationship('profile')
                ->columnSpanFull()
                ->visible(function (?User $record): bool {
                    $admin = auth()->user();
                    $profile = $record?->profile;

                    return $profile !== null
                        && $admin instanceof User
                        && Gate::forUser($admin)->allows('instructor.viewDocuments', $profile);
                })
                ->schema([
                    Placeholder::make('kyc_documents')
                        ->hiddenLabel()
                        ->content(fn (?UserProfile $record) => $record !== null
                            ? new HtmlString(view('filament.components.instructor-document-viewer', [
                                'rows' => InstructorDocumentViewer::rows($record),
                            ])->render())
                            : null
                        )
                        ->columnSpanFull(),
                ]),

            Section::make('Public Profile Cover')
                ->description('Optional banner shown behind the instructor identity area. Avoid embedded contact details or promotional claims.')
                ->icon('heroicon-o-photo')
                ->columnSpanFull()
                ->schema([
                    SpatieMediaLibraryFileUpload::make('instructor_cover')
                        ->label('Cover image')
                        ->collection('instructor_cover')
                        ->image()
                        ->imageEditor()
                        ->maxSize(4096)
                        ->helperText('JPG, PNG, or WebP up to 4 MB. Recommended ratio: 3:1.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function preview(?string $value, string $emptyMessage): HtmlString
    {
        if (blank($value)) {
            return new HtmlString('<p class="rounded-lg border border-dashed border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-700 dark:border-warning-400/20 dark:bg-warning-400/5 dark:text-warning-300">'.e($emptyMessage).'</p>');
        }

        return new HtmlString('<p class="whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-200">'.e(Str::limit($value, 800)).'</p>');
    }
}
