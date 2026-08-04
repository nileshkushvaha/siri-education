<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EmailVerificationChallenge extends Model
{
    protected $fillable = ['user_id', 'email_fingerprint', 'code_hash', 'attempts_remaining', 'expires_at', 'consumed_at', 'invalidated_at'];

    protected $hidden = ['code_hash', 'email_fingerprint'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }
}
