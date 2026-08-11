<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\RelationManagers;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Curriculum\Exceptions\CurriculumException;
use App\Curriculum\Services\CurriculumService;
use App\Filament\Resources\Academic\CurriculumVersionResource;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only listing of a curriculum's versions — structural/lifecycle
 * mutation happens only through CurriculumService, reached here via
 * "Create New Version" and the "Open" link to CurriculumVersionResource
 * (never a direct EditAction on this table).
 */
class CurriculumVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version_number')
            ->columns([
                TextColumn::make('version_number')
                    ->label('Version')
                    ->formatStateUsing(fn (int $state): string => "v{$state}")
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (CurriculumVersionStatus $state): string => $state->color())
                    ->formatStateUsing(fn (CurriculumVersionStatus $state): string => $state->label()),
                TextColumn::make('modules_count')
                    ->label('Modules')
                    ->counts('modules'),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('createNewVersion')
                    ->label('Create New Version')
                    ->icon('heroicon-m-plus-circle')
                    ->color('primary')
                    ->visible(fn (): bool => auth()->user()?->can('create', CurriculumVersion::class) ?? false)
                    ->action(function (): void {
                        /** @var Curriculum $curriculum */
                        $curriculum = $this->getOwnerRecord();
                        $latest = $curriculum->latestVersion();

                        try {
                            $version = app(CurriculumService::class)->createNewVersion(auth()->user(), $curriculum, $latest);
                        } catch (CurriculumException $e) {
                            Notification::make()->title('Version not created')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title("Version {$version->version_number} created")->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (CurriculumVersion $record): string => CurriculumVersionResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('version_number', 'desc');
    }
}
