<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single-responsibility action: persists the new user. Does NOT dispatch
 * jobs or assign roles — that is the Service's concern.
 *
 * Does NOT create the profile itself — UserObserver::created() already
 * guarantees exactly one UserProfile per user (enforced by a unique
 * index on user_profiles.user_id). Creating a second one here used to
 * silently violate that 1:1 guarantee; this only fills in the one field
 * (phone) the registration form collects that the profile doesn't
 * default.
 */
final class RegisterUserAction
{
    public function execute(
        array $data,
        string $status = User::STATUS_PENDING,
        bool $mustChangePassword = false,
    ): User {
        return DB::transaction(function () use ($data, $status, $mustChangePassword): User {
            $fullName = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
            $acceptedAt = ($data['terms'] ?? false) ? now() : null;

            $user = User::create([
                'name' => $fullName ?: $data['first_name'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'],
                'status' => $status,
                'must_change_password' => $mustChangePassword,
                'terms_accepted_at' => $acceptedAt,
                'privacy_accepted_at' => $acceptedAt,
                'terms_version' => $data['terms_version'] ?? null,
                'privacy_version' => $data['privacy_version'] ?? null,
                'terms_accepted_ip' => $acceptedAt ? ($data['accepted_ip'] ?? null) : null,
                'privacy_accepted_ip' => $acceptedAt ? ($data['accepted_ip'] ?? null) : null,
                'terms_accepted_user_agent' => $acceptedAt ? ($data['accepted_user_agent'] ?? null) : null,
                'privacy_accepted_user_agent' => $acceptedAt ? ($data['accepted_user_agent'] ?? null) : null,
            ]);

            if (filled($data['phone'] ?? null)) {
                $user->profile()->update(['phone' => $data['phone']]);
            }

            return $user;
        });
    }
}
