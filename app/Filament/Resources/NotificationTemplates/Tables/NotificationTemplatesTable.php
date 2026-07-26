<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates\Tables;

use App\Models\NotificationTemplate;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Notifications\Templates\NotificationTemplateRegistry;
use App\Notifications\Templates\NotificationTemplateRenderer;
use App\Notifications\Templates\NotificationTemplateSampleData;
use App\Services\Notifications\NotificationTemplateService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class NotificationTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('template_key')
            ->columns([
                TextColumn::make('template_key')
                    ->label('Template')
                    ->formatStateUsing(fn (NotificationTemplateKey $state): string => NotificationTemplateRegistry::get($state)->description)
                    ->description(fn (NotificationTemplate $record): string => $record->template_key->value)
                    ->searchable(),
                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn (NotificationTemplate $record): string => NotificationTemplateRegistry::get($record->template_key)->category)
                    ->badge(),
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst($state->value)),
                TextColumn::make('override_state')
                    ->label('Content')
                    ->state(fn (NotificationTemplate $record): string => $record->hasOverride() ? 'Customized' : 'Default')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Customized' ? 'warning' : 'gray'),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->afterStateUpdated(function (NotificationTemplate $record, bool $state): void {
                        app(NotificationTemplateService::class)->setActive($record, auth()->user(), $state);
                    }),
                TextColumn::make('version')->label('Version')->sortable(),
                TextColumn::make('editor.name')->label('Last edited by')->placeholder('—'),
                TextColumn::make('updated_at')->dateTime()->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(collect(NotificationTemplateRegistry::all())
                        ->pluck('category', 'category')
                        ->unique()
                        ->all())
                    ->query(function ($query, array $data) {
                        $category = $data['value'] ?? null;

                        if ($category === null) {
                            return $query;
                        }

                        $keys = collect(NotificationTemplateRegistry::all())
                            ->filter(fn ($definition) => $definition->category === $category)
                            ->map(fn ($definition) => $definition->key->value)
                            ->all();

                        return $query->whereIn('template_key', $keys);
                    }),
                SelectFilter::make('channel')->options(['mail' => 'Mail', 'database' => 'Database']),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Template preview (safe sample data)')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (NotificationTemplate $record): View {
                        $content = NotificationTemplateRegistry::get($record->template_key)->contentFor($record->channel);
                        $sample = array_intersect_key(NotificationTemplateSampleData::for($record->template_key), array_flip($content->variables));
                        $rendered = app(NotificationTemplateRenderer::class)->render($record->template_key, $record->channel, $sample);

                        return view('filament.notification-templates.preview', [
                            'channel' => $record->channel->value,
                            'subject' => $rendered->subject,
                            'lines' => $rendered->lines,
                        ]);
                    }),
                Action::make('restoreDefault')
                    ->label('Restore default')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (NotificationTemplate $record): bool => $record->hasOverride())
                    ->action(function (NotificationTemplate $record): void {
                        app(NotificationTemplateService::class)->restoreDefault($record, auth()->user());

                        FilamentNotification::make()
                            ->title('Template restored to default')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->paginated([15, 25, 50]);
    }
}
