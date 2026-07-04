<?php

declare(strict_types=1);

namespace App\Models;

use App\Forms\Enums\PublicFormType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PublicFormSubmission extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'meta',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'type' => PublicFormType::class,
            'meta' => 'array',
        ];
    }
}
