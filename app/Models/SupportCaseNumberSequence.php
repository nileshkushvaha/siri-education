<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Internal allocator state only — never exposed via a route or
 * resource. Mirrors InvoiceNumberSequence.
 */
class SupportCaseNumberSequence extends Model
{
    protected $fillable = ['scope_key', 'next_number'];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
        ];
    }
}
