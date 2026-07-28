<?php

namespace App\Filament\Resources\PageBlocks\Pages;

use App\Actions\ValidateBlockContentAction;
use App\Enums\BlockType;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\PageBlocks\PageBlockResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\Page;
use App\Models\Post;
use App\Services\BlockContentConverter;
use App\Services\BlockContentHydrator;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditPageBlock extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = PageBlockResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $blockTypeRaw = $data['block_type'] ?? null;
        $blockType = $blockTypeRaw instanceof BlockType
            ? $blockTypeRaw
            : BlockType::tryFrom((string) $blockTypeRaw);

        $isPost = ($data['blockable_type'] ?? '') === (new Post)->getMorphClass();

        $data[$isPost ? 'post_id' : 'page_id'] = $data['blockable_id'] ?? $this->record->blockable_id;

        if ($blockType) {
            $data = array_merge($data, BlockContentHydrator::hydrate($blockType, $this->record->content ?? []));
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $blockableType = $data['blockable_type'] ?? $this->record->blockable_type;

        if ($blockableType === (new Post)->getMorphClass()) {
            $ownerId = $data['post_id'] ?? $this->record->blockable_id;
            $data['blockable_type'] = (new Post)->getMorphClass();
            $data['blockable_id'] = $ownerId;
        } else {
            $ownerId = $data['page_id'] ?? $this->record->blockable_id;
            $data['blockable_type'] = (new Page)->getMorphClass();
            $data['blockable_id'] = $ownerId;
        }

        unset($data['page_id'], $data['post_id']);

        if (isset($data['block_type'])) {
            $blockType = $data['block_type'] instanceof BlockType
                ? $data['block_type']
                : BlockType::tryFrom($data['block_type']);

            if ($blockType) {
                $data['content'] = BlockContentConverter::convert($blockType, $data);
                $data['settings'] = $data['settings'] ?? [];

                $errors = app(ValidateBlockContentAction::class)->execute($blockType, $data['content']);
                if ($errors !== []) {
                    throw ValidationException::withMessages([
                        'block_type' => implode(' ', $errors),
                    ]);
                }
            }
        }

        $data['position'] ??= 'after_content';

        $databaseColumns = [
            'id', 'blockable_type', 'blockable_id', 'block_type', 'name', 'content',
            'settings', 'sort_order', 'position', 'is_active', 'created_at', 'updated_at', 'deleted_at',
        ];

        return array_intersect_key($data, array_flip($databaseColumns));
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Content Blocks'),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ]);
    }
}
