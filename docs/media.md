# Media

## Package

Spatie Laravel Media Library 11.23.1 (`^11.23`).

## Current usage

- User avatars — stored via Media Library on the `User` model
- Post featured images — stored on `Post` model
- General media attachments — stored on applicable models

## Admin UI

Media Library admin UI is planned but not yet complete. See `roadmap.md`.

## Adding media to a model

```php
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class MyModel extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->singleFile();
    }
}
```

## Storage

Configured via `config/media-library.php`. Default disk: `public`.
