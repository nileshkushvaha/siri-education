<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorOnboarding\Pages;

use App\Filament\Concerns\HasInstructorLifecycleActions;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Filament\Resources\InstructorOnboarding\Schemas\InstructorOnboardingForm;
use App\Filament\Support\Presentation\BackAction;
use App\Services\AuditTrailService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;

/**
 * The dedicated onboarding review page — instructor fields and
 * lifecycle actions only, none of UserResource's unrelated tabs
 * (General, Address, Social Links, Security, Roles, ...).
 */
class EditInstructorOnboarding extends EditRecord
{
    use HasInstructorLifecycleActions;
    use HasSectionBreadcrumb;

    protected static string $resource = InstructorOnboardingResource::class;

    public function form(Schema $schema): Schema
    {
        return InstructorOnboardingForm::configure($schema);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Review '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Verify professional information and evidence, then choose the correct application outcome.';
    }

    /** Same one-time KYC-view audit as UserResource's EditUser::mount(). */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $admin = $this->actingAdmin();
        $profile = $this->record->profile;

        if ($admin !== null && $profile !== null && Gate::forUser($admin)->allows('instructor.viewDocuments', $profile)) {
            app(AuditTrailService::class)->logUser(
                $admin,
                'instructor',
                'instructor_document_viewed',
                'Instructor documents viewed',
                $this->record,
                ['instructor_id' => $this->record->id],
            );
        }
    }

    protected function getHeaderActions(): array
    {
        // Back is placed first, outside/before the lifecycle action group,
        // so it reads as page navigation rather than another review
        // decision alongside Approve/Reject/Suspend/etc.
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Instructor Onboarding'),
            ...$this->instructorLifecycleActions(),
        ]);
    }

    /**
     * Display each review dataset as its own full-width section instead of
     * hiding Experience, Education, and Activity behind relation tabs.
     */
    public function getRelationManagersContentComponent(): Component
    {
        $ownerRecord = $this->getRecord();
        $managerData = [
            'ownerRecord' => $ownerRecord,
            'pageClass' => static::class,
        ];

        return Group::make(
            collect($this->getRelationManagers())
                ->map(function (string $manager, int|string $key) use ($managerData): Livewire {
                    $managerClass = $this->normalizeRelationManagerClass($manager);

                    return Livewire::make(
                        $managerClass,
                        [...$managerData, ...$managerClass::getDefaultProperties()],
                    )->key("{$managerClass}-section-{$key}");
                })
                ->all(),
        )->columns(1);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Save marketplace settings')
            ->icon('heroicon-m-check')
            ->color('primary');
    }
}
