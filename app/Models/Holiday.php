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

    /**
     * TZ-2A: accepts a plain `Y-m-d` string so callers can pass a date
     * they derived in the OWNING calendar (see LocalDay), rather than
     * handing over an instant and letting `whereDate` reduce it to a
     * UTC date. `whereDate` is correct here — `holidays.date` is a
     * date-only column with no timezone semantics of its own.
     */
    public function scopeOnDate(Builder $query, CarbonInterface|string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }
}
