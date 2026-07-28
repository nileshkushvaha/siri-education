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
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreatePageBlock extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = PageBlockResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Content Blocks'),
        ]);
    }

    protected function getFillableFields(): array
    {
        $postId = request()->query('post_id');
        $pageId = request()->query('page_id');

        if ($postId) {
            return [
                'blockable_type' => (new Post)->getMorphClass(),
                'post_id' => $postId,
            ];
        }

        if ($pageId) {
            return [
                'blockable_type' => (new Page)->getMorphClass(),
                'page_id' => $pageId,
            ];
        }

        return ['blockable_type' => 'page'];
    }

    public function mount(): void
    {
        parent::mount();
        $this->form->fill($this->getFillableFields());
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Determine owner type — form sends blockable_type + page_id or post_id.
        // URL query params (?page_id=... or ?post_id=...) pre-fill on direct links.
        $blockableType = $data['blockable_type'] ?? null;

        // Resolve from query string when arriving via direct link
        if (blank($blockableType)) {
            if (request()->query('page_id')) {
                $blockableType = (new Page)->getMorphClass();
            } elseif (request()->query('post_id')) {
                $blockableType = (new Post)->getMorphClass();
            }
        }

        if ($blockableType === (new Post)->getMorphClass()) {
            $ownerId = $data['post_id'] ?? request()->query('post_id');
            if (blank($ownerId)) {
                throw ValidationException::withMessages(['post_id' => 'Please select a post.']);
            }
            $data['blockable_type'] = (new Post)->getMorphClass();
            $data['blockable_id'] = $ownerId;
        } else {
            $ownerId = $data['page_id'] ?? request()->query('page_id');
            if (blank($ownerId)) {
                throw ValidationException::withMessages(['page_id' => 'Please select a page before creating a block.']);
            }
            $data['blockable_type'] = (new Page)->getMorphClass();
            $data['blockable_id'] = $ownerId;
        }

        unset($data['page_id'], $data['post_id']);

        if (isset($data['block_type'])) {
            $blockTypeValue = $data['block_type'] instanceof BlockType ? $data['block_type']->value : $data['block_type'];
            $blockType = BlockType::tryFrom((string) $blockTypeValue);
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
            'blockable_type', 'blockable_id', 'block_type', 'name', 'content',
            'settings', 'sort_order', 'position', 'is_active', 'created_at', 'updated_at',
        ];

        return array_intersect_key($data, array_flip($databaseColumns));
    }
}
