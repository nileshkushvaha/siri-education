<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => 'active']);
        $this->student->assignRole('student');
    }

    #[DataProvider('stubRouteProvider')]
    public function test_student_can_load_stub_pages(string $route, string $expectedText): void
    {
        $this->actingAs($this->student)
            ->get(route($route))
            ->assertOk()
            ->assertSee($expectedText);
    }

    public static function stubRouteProvider(): array
    {
        return [
            'progress' => ['dashboard.progress',     'My Progress'],
            'certificates' => ['dashboard.certificates', 'Certificates'],
            'orders' => ['dashboard.orders',       'Orders'],
            'wishlist' => ['dashboard.wishlist',     'Wishlist'],
            'reviews' => ['dashboard.reviews',      'Reviews'],
        ];
    }

    #[DataProvider('stubRouteProvider')]
    public function test_guest_is_redirected_from_stub_pages(string $route, string $_expectedText): void
    {
        $this->get(route($route))->assertRedirect(route('auth.login'));
    }

    public function test_certificates_page_shows_no_certificates_yet(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.certificates'))
            ->assertOk()
            ->assertSee('No Certificates Yet');
    }

    public function test_orders_page_shows_no_orders_yet(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.orders'))
            ->assertOk()
            ->assertSee('No Orders Yet');
    }
}
