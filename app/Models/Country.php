<?php

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'iso2', 'iso3', 'phone_code', 'nationality',
        'flag', 'default_currency_id', 'default_language_id',
        'default_timezone', 'support_email', 'support_phone',
        'date_format', 'time_format', 'number_format',
        'feature_flags', 'payment_routing', 'sort_order', 'status', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'feature_flags' => 'array',
            'payment_routing' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }

    public function userProfiles(): HasMany
    {
        return $this->hasMany(UserProfile::class);
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    public function defaultLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'default_language_id');
    }

    /** Subjects explicitly scoped to this country. A subject with no country rows is available everywhere. */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'subject_country');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('countries')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
