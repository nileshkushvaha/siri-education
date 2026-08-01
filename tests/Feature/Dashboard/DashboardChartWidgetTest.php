<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Dashboard\BookingsPerDayChartWidget;
use App\Filament\Widgets\Dashboard\HomeworkActivityChartWidget;
use App\Filament\Widgets\Dashboard\LessonOutcomeChartWidget;
use App\Filament\Widgets\Dashboard\StudentRegistrationChartWidget;
use App\Filament\Widgets\Dashboard\SupplyDemandChartWidget;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chart widgets.
 *
 * A chart widget never queries: it receives the dashboard's global
 * context as Livewire properties and reads the already-composed,
 * cached dashboard. A chart the viewer may not see is absent from that
 * composition, so the widget renders empty rather than exposing data —
 * which is the property these tests pin down, since a widget rendered
 * through `@livewire` does not go through Filament's own `canView()`
 * filter.
 */
class DashboardChartWidgetTest extends TestCase
{
    use RefreshDatabase;

    private const WIDGETS = [
        LessonOutcomeChartWidget::class,
        StudentRegistrationChartWidget::class,
        BookingsPerDayChartWidget::class,
        SupplyDemandChartWidget::class,
        HomeworkActivityChartWidget::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
    }

    public function test_every_chart_widget_mounts_for_a_permitted_manager(): void
    {
        $manager = $this->manager();

        foreach (self::WIDGETS as $widget) {
            Livewire::actingAs($manager)
                ->test($widget, $this->contextProps())
                ->assertSuccessful();
        }
    }

    public function test_lesson_outcome_chart_renders_real_finalized_outcomes(): void
    {
        $manager = $this->manager();

        Lesson::factory()->count(2)->create([
            'status' => LessonStatus::Completed,
            'outcome' => LessonOutcome::Completed,
            'outcome_finalized_at' => now()->subDay(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);
        Lesson::factory()->create([
            'status' => LessonStatus::StudentNoShow,
            'outcome' => LessonOutcome::StudentNoShow,
            'outcome_finalized_at' => now()->subDay(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);

        Livewire::actingAs($manager)
            ->test(LessonOutcomeChartWidget::class, $this->contextProps())
            ->assertSuccessful()
            ->assertSee('Lesson outcomes');
    }

    public function test_the_dashboard_page_renders_with_chart_data_present(): void
    {
        $manager = $this->manager();

        Lesson::factory()->create([
            'status' => LessonStatus::Completed,
            'outcome' => LessonOutcome::Completed,
            'outcome_finalized_at' => now()->subDay(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);

        // Exercises the non-empty chart branch of the Blade layout,
        // where the widgets are actually mounted.
        $this->actingAs($manager)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Marketplace performance');
    }

    public function test_a_widget_mounted_directly_yields_nothing_without_permission(): void
    {
        // A widget rendered through @livewire bypasses Filament's own
        // canView() filter, so the composition layer must be the gate:
        // no chart in the composition means no data in the widget.
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $role->syncPermissions([]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Lesson::factory()->create([
            'status' => LessonStatus::Completed,
            'outcome' => LessonOutcome::Completed,
            'outcome_finalized_at' => now()->subDay(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);

        Livewire::actingAs($user)
            ->test(LessonOutcomeChartWidget::class, $this->contextProps())
            ->assertSuccessful()
            ->assertDontSee('Lesson outcomes');
    }

    public function test_widgets_accept_a_custom_period_without_throwing(): void
    {
        Livewire::actingAs($this->manager())
            ->test(BookingsPerDayChartWidget::class, [
                'periodPreset' => 'custom',
                'customStart' => now()->subDays(5)->toDateString(),
                'customEnd' => now()->toDateString(),
                'countryId' => null,
            ])
            ->assertSuccessful();
    }

    public function test_widgets_survive_an_invalid_period_in_their_props(): void
    {
        Livewire::actingAs($this->manager())
            ->test(StudentRegistrationChartWidget::class, [
                'periodPreset' => 'nonsense',
                'customStart' => 'not-a-date',
                'customEnd' => null,
                'countryId' => 'not-an-id',
            ])
            ->assertSuccessful();
    }

    /** @return array<string, string|null> */
    private function contextProps(): array
    {
        return [
            'periodPreset' => 'last_30_days',
            'customStart' => null,
            'customEnd' => null,
            'countryId' => null,
        ];
    }

    private function manager(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        return $user;
    }
}
