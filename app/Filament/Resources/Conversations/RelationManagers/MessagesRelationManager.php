<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\RelationManagers;

use App\Messaging\Safety\Enums\MessageSafetyCategory;
use App\Messaging\Safety\Enums\MessageSafetyFindingStatus;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only message history (SRS §17.36 "Review communication history
 * for disputes" / "Search flagged messages"), extended in P4 with
 * communication-safety findings.
 *
 * Deliberately NOT a new admin screen. Per-message safety evidence
 * belongs where an admin already reads messages, and account-level
 * concerns escalate into the existing Compliance Flags queue — so the
 * platform gained no second moderation surface.
 *
 * The source of every finding is always shown. "Automatic rule" is a
 * verifiable fact about the text; "AI intent analysis" is an opinion
 * with a confidence that may be wrong, and an admin acting on the two
 * is making very different judgements.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Message History';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedChatBubbleLeftRight;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sent_at')->dateTime()->sortable(),
                TextColumn::make('sender.name')->label('From'),
                TextColumn::make('body')->wrap()->limit(300),
                IconColumn::make('flagged_leakage')
                    ->label('Flagged for shared contact info')
                    ->boolean(),
                TextColumn::make('safetyFindings.category')
                    ->label('Safety findings')
                    ->badge()
                    ->separator(',')
                    ->formatStateUsing(fn ($state): string => $state instanceof MessageSafetyCategory ? $state->label() : (string) $state)
                    ->color(fn ($state): string => $state instanceof MessageSafetyCategory ? $state->color() : 'gray')
                    ->placeholder('—')
                    ->tooltip('Open the Compliance Flags queue for account-level review. A finding is evidence, never an action.'),
                TextColumn::make('read_at')->dateTime()->placeholder('Unread'),
            ])
            ->filters([
                TernaryFilter::make('flagged_leakage')->label('Flagged for shared contact info'),
                Filter::make('has_open_safety_finding')
                    ->label('Has an open safety finding')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'safetyFindings',
                        fn (Builder $findings): Builder => $findings->where('status', MessageSafetyFindingStatus::Open),
                    )),
            ])
            ->headerActions([])
            ->recordActions([])
            ->bulkActions([])
            ->defaultSort('sent_at', 'asc');
    }
}
