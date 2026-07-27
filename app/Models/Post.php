<?php

namespace App\Models;

use App\Content\Contracts\HasContentBlocks;
use App\Content\Models\ContentBlock;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Support\Media\Concerns\HasStandardImageConversions;
use App\Support\Media\StandardImageConversion;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Post extends Model implements HasContentBlocks, HasMedia
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasStandardImageConversions, HasUuids, InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'author_id',
        'status',
        'visibility',
        'published_at',
        'reading_time',
        'featured',
        'allow_comments',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'robots',
    ];

    protected $casts = [
        'status' => PageStatus::class,
        'visibility' => PageVisibility::class,
        'published_at' => 'datetime',
        'featured' => 'boolean',
        'allow_comments' => 'boolean',
        'reading_time' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Post $post): void {
            $post->created_by = auth()->id() ?? null;
            $post->updated_by = auth()->id() ?? null;
        });

        static::updating(function (Post $post): void {
            $post->updated_by = auth()->id() ?? null;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('featured-image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    /** 800px display conversion for the public blog featured image. gallery is left unconverted (no confirmed display consumer). */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($this->skipStandardImageConversions($media)) {
            return;
        }

        $this->addDisplayConversion('featured-image');
    }

    /** Safely falls back to the original until the queued conversion is generated. */
    public function featuredImageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->urlForStandardConversion('featured-image', StandardImageConversion::Display),
        );
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function blocks(): MorphMany
    {
        return $this->morphMany(ContentBlock::class, 'blockable')->orderBy('sort_order');
    }

    public function activeBlocks(): MorphMany
    {
        return $this->morphMany(ContentBlock::class, 'blockable')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function beforeBlocks(): MorphMany
    {
        return $this->morphMany(ContentBlock::class, 'blockable')
            ->where('is_active', true)
            ->where('position', 'before_content')
            ->orderBy('sort_order');
    }

    public function afterBlocks(): MorphMany
    {
        return $this->morphMany(ContentBlock::class, 'blockable')
            ->where('is_active', true)
            ->where('position', 'after_content')
            ->orderBy('sort_order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(PostCategory::class, 'post_category_post')->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag_post')->withTimestamps();
    }

    public function relatedPosts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'post_related_post', 'post_id', 'related_post_id')
            ->withTimestamps();
    }

    public function relatedToPosts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'post_related_post', 'related_post_id', 'post_id')
            ->withTimestamps();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', PageStatus::Published)
            ->where('visibility', PageVisibility::Public)
            ->where(function (Builder $query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', PageStatus::Draft);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', PageStatus::Scheduled)
            ->where('published_at', '<=', now());
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $query) use ($term): void {
            $query->where('title', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%")
                ->orWhere('excerpt', 'like', "%{$term}%")
                ->orWhere('content', 'like', "%{$term}%")
                ->orWhereHas('author', fn (Builder $authorQuery) => $authorQuery->where('name', 'like', "%{$term}%"));
        });
    }

    public function publish(): bool
    {
        return $this->update([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'published_at' => $this->published_at ?? now(),
        ]);
    }

    public function unpublish(): bool
    {
        return $this->update([
            'status' => PageStatus::Draft,
            'visibility' => PageVisibility::Private,
        ]);
    }

    public function archive(): bool
    {
        return $this->update([
            'status' => PageStatus::Archived,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'title',
                'slug',
                'excerpt',
                'author_id',
                'status',
                'visibility',
                'published_at',
                'reading_time',
                'featured',
                'allow_comments',
                'meta_title',
                'meta_description',
                'meta_keywords',
                'canonical_url',
                'robots',
            ])
            ->useLogName('posts')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
