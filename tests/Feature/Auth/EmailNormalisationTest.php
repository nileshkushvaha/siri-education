<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailNormalisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_stored_trimmed_and_lowercase_whatever_the_caller_sends(): void
    {
        $user = User::factory()->create(['email' => '  Mixed.Case@Example.COM ']);

        $this->assertSame('mixed.case@example.com', $user->email);
        $this->assertDatabaseHas('users', ['email' => 'mixed.case@example.com']);

        $user->update(['email' => 'Another@Example.com']);
        $this->assertSame('another@example.com', $user->fresh()->email);
    }

    public function test_case_variant_duplicate_is_rejected_by_the_database(): void
    {
        User::factory()->create(['email' => 'dupe@example.com']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'DUPE@example.com']);
    }
}
