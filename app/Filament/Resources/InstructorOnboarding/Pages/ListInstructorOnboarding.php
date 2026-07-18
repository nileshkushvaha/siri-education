<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorOnboarding\Pages;

use App\Enums\InstructorStatus;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInstructorOnboarding extends ListRecords
{
    protected static string $resource = InstructorOnboardingResource::class;

    public function getTabs(): array
    {
        return [
            'needs_review' => Tab::make('Needs Review')
                ->badge(InstructorOnboardingResource::pendingReviewQuery()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('user_profiles.instructor_status', InstructorStatus::needsReview())
                    ->orderBy('user_profiles.instructor_application_submitted_at')),
            'active' => Tab::make('Approved & Active')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('user_profiles.instructor_status', InstructorStatus::publiclyVisible())),
            'inactive' => Tab::make('Rejected, Suspended & Archived')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('user_profiles.instructor_status', [
                        InstructorStatus::Rejected,
                        InstructorStatus::Suspended,
                        InstructorStatus::Archived,
                    ])),
            'all' => Tab::make('All'),
        ];
    }
}
