<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** An organisation-wide non-working day. */
class Holiday extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'date',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }

    public function scopeOnDate(Builder $query, CarbonInterface $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }
}
