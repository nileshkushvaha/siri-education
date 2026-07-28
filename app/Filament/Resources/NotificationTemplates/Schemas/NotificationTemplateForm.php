<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Models\NotificationTemplate;
use App\Notifications\Templates\NotificationTemplateRegistry;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Key/channel are always displayed read-only
 * (never a Select/TextInput an admin could repurpose to a different
 * key or channel); only subject/body are ever editable here.
 */
class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')
                ->schema([
                    Grid::make(2)->schema([
                        Placeholder::make('template_key_display')
                            ->label('Template key')
                            ->content(fn (NotificationTemplate $record): string => $record->template_key->value),
                        Placeholder::make('channel_display')
                            ->label('Channel')
                            ->content(fn (NotificationTemplate $record): string => ucfirst($record->channel->value)),
                    ]),
                    Placeholder::make('variables_display')
                        ->label('Allowed variables')
                        ->content(function (NotificationTemplate $record): HtmlString {
                            $content = NotificationTemplateRegistry::get($record->template_key)->contentFor($record->channel);

                            $list = collect($content->variables)
                                ->map(fn (string $v): string => sprintf('<code>{{%s}}</code>', e($v)))
                                ->implode(', ');

                            return new HtmlString($list !== '' ? $list : '<em>No variables for this channel.</em>');
                        }),
                ])
                ->columnSpanFull(),

            Section::make('Content')
                ->description('Leave blank to use the default text shown as placeholder text below.')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('subject')
                        ->label(fn (NotificationTemplate $record): string => $record->channel->value === 'database' ? 'Title override' : 'Subject override')
                        ->placeholder(fn (NotificationTemplate $record): string => NotificationTemplateRegistry::get($record->template_key)->contentFor($record->channel)->defaultSubject)
                        ->maxLength(255),
                    Textarea::make('body')
                        ->label('Body override')
                        ->placeholder(fn (NotificationTemplate $record): string => NotificationTemplateRegistry::get($record->template_key)->contentFor($record->channel)->defaultBody)
                        ->rows(6)
                        ->maxLength(4000),
                ]),
        ]);
    }
}
