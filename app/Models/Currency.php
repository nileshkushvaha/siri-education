<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'numeric_code',
        'minor_units',
        'minimum_recharge_minor',
        'maximum_recharge_minor',
        'low_balance_threshold_minor',
        'recharge_multiple_minor',
        'status',
        'sort_order',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'minor_units' => 'integer',
            // Integer minor units in THIS currency's own exponent, never
            // a float and never a cross-currency scalar — see the
            // add_recharge_limits_to_currencies_table migration. NULL is
            // "unconfigured", which is not the same as 0.
            'minimum_recharge_minor' => 'integer',
            'maximum_recharge_minor' => 'integer',
            'low_balance_threshold_minor' => 'integer',
            'recharge_multiple_minor' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function countries(): HasMany
    {
        return $this->hasMany(Country::class, 'default_currency_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('currencies')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
