<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\ReportDefinition;
use App\Reporting\Enums\ReportCategory;
use App\Reporting\Exceptions\DuplicateReportKeyException;
use App\Reporting\Support\UniqueDefinitionKeys;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The code-defined report catalogue. */
class ReportRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
    }

    public function test_report_keys_are_unique(): void
    {
        $keys = array_map(fn (ReportDefinition $d) => $d->key, app(ReportRegistryInterface::class)->all());

        $this->assertSame($keys, array_unique($keys));
    }

    public function test_duplicate_report_key_is_rejected(): void
    {
        // ReportRegistry/MetricRegistry both build their catalogue through
        // this exact shared helper — testing it directly (rather than
        // subclassing a `final` registry) proves the guard itself, without
        // needing a real duplicate to exist in the actual Version 1 catalogue.
        $one = app(ReportRegistryInterface::class)->all()[0];

        $this->expectException(DuplicateReportKeyException::class);

        UniqueDefinitionKeys::index(
            [$one, $one],
            fn (ReportDefinition $d): string => $d->key,
            fn (string $key) => throw DuplicateReportKeyException::forKey($key),
        );
    }

    public function test_unauthorized_reports_are_excluded_from_available_for(): void
    {
        $stranger = User::factory()->create(['status' => 'active']);

        $available = app(ReportRegistryInterface::class)->availableFor($stranger);

        $this->assertEmpty($available);
    }

    public function test_financial_reports_are_excluded_without_finance_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ViewOperationalReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $access = app(ReportAccessContextInterface::class);

        $this->assertFalse($access->canViewFinancialValues($user));

        $available = app(ReportRegistryInterface::class)->availableFor($user);
        $this->assertTrue(collect($available)->every(fn (ReportDefinition $d) => ! $d->financial));
    }

    public function test_instructor_compensation_report_requires_its_own_specific_permission(): void
    {
        $financeOnly = User::factory()->create(['status' => 'active']);
        $financeOnly->givePermissionTo('ViewFinanceReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $access = app(ReportAccessContextInterface::class);
        $definition = app(ReportRegistryInterface::class)->find('earnings_settlements');
        $this->assertNotNull($definition);
        $this->assertSame('ViewInstructorCompensationReports', $definition->requiredViewPermission);

        // ViewFinanceReports alone must NOT unlock instructor-compensation
        // access (category permission != the report's own, stricter permission).
        $this->assertTrue($access->canViewFinancialValues($financeOnly));
        $this->assertFalse($access->canViewInstructorCompensation($financeOnly));

        $compensationUser = User::factory()->create(['status' => 'active']);
        $compensationUser->givePermissionTo(['ViewFinanceReports', 'ViewInstructorCompensationReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($access->canViewInstructorCompensation($compensationUser));
    }

    public function test_registry_listing_performs_no_database_query(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        app(ReportRegistryInterface::class)->all();

        $this->assertEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_future_unavailable_report_has_no_route(): void
    {
        $planned = collect(app(ReportRegistryInterface::class)->all())->firstWhere('available', false);

        $this->assertNotNull($planned);
        $this->assertNull($planned->routeName);
    }

    public function test_available_report_declares_a_resolvable_route(): void
    {
        $available = collect(app(ReportRegistryInterface::class)->all())->firstWhere('available', true);

        $this->assertNotNull($available);
        $this->assertNotNull($available->routeName);
        $this->assertTrue(class_exists($available->routeName));
    }

    public function test_every_definition_declares_supported_filters_freshness_and_permission(): void
    {
        foreach (app(ReportRegistryInterface::class)->all() as $definition) {
            $this->assertIsArray($definition->supportedFilters);
            $this->assertNotEmpty($definition->freshness->value);
            $this->assertNotEmpty($definition->requiredViewPermission);
        }
    }

    public function test_no_category_exists_for_unsupported_future_modules(): void
    {
        $categoryValues = array_map(fn ($c) => $c->value, ReportCategory::cases());

        foreach (['group_classes', 'packages', 'subscriptions', 'corporate_accounts', 'counselling', 'parent_meeting', 'webinar'] as $forbidden) {
            $this->assertNotContains($forbidden, $categoryValues);
        }
    }

    public function test_super_admin_receives_every_report(): void
    {
        $superAdmin = User::factory()->create(['status' => 'active']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->assignRole('super_admin');

        $available = app(ReportRegistryInterface::class)->availableFor($superAdmin);
        $allAvailableDefinitions = collect(app(ReportRegistryInterface::class)->all())->where('available', true);

        $this->assertCount($allAvailableDefinitions->count(), $available);
    }
}
