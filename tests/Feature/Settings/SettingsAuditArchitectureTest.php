<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Tests\TestCase;

/**
 * Closes an audit-coverage gap: earlier tests only proved
 * GeneralSettingsPage-style atomicity
 * for the Payment and Homework Reminder pages. Every other settings
 * mutation surface (General, Mail, SEO, Meeting, Instructor Earnings,
 * Platform Foundation, RazorpayX Payout, Reviews & Quality, and all six
 * Security pages via SecuritySettingsService) either had no audit
 * coverage at all, or wrote its audit record as a second, separate,
 * non-transactional step — this test guards against a regression back
 * to either state.
 *
 * Every file below is asserted to (a) reference
 * LogsSettingsUpdates::saveSettingsWithAudit() — directly, or via a
 * documented compliant equivalent for the Payment pages, which delegate
 * to protected save*Settings() helpers on their shared abstract base —
 * and (b) never call ->save() directly on a Settings object outside
 * that one shared, atomic, audited path.
 */
class SettingsAuditArchitectureTest extends TestCase
{
    /**
     * Pages/services that persist a Settings mutation and must route
     * through the shared atomic+audited helper.
     *
     * @return list<string>
     */
    public static function governedFiles(): array
    {
        $base = base_path();

        return [
            $base.'/app/Filament/Pages/Settings/GeneralSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/MailSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/SeoSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/MeetingSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/InstructorEarningSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/PlatformFoundationSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/RazorpayXPayoutSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/ReviewQualitySettingsPage.php',
            $base.'/app/Filament/Pages/Settings/AiSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/HomeworkReminderSettingsPage.php',
            // Governed like the rest: use LogsSettingsUpdates and persist
            // through saveSettingsWithAudit(), never a bare ->save().
            $base.'/app/Filament/Pages/Settings/DemoConversionIncentiveSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/WhatsAppSettingsPage.php',
            $base.'/app/Filament/Pages/Settings/PaymentSettingsPage.php',
            $base.'/app/Services/Security/SecuritySettingsService.php',
        ];
    }

    /**
     * These pages persist nothing themselves — they delegate every
     * save*Settings() call to the protected helpers already defined on
     * PaymentSettingsPage (their abstract base, included in
     * governedFiles() above), so they are a documented compliant
     * equivalent rather than a second, independent audit path.
     *
     * @return list<string>
     */
    public static function delegatingToBaseClass(): array
    {
        $base = base_path();

        return [
            $base.'/app/Filament/Pages/Settings/PaymentAdvancedPage.php',
            $base.'/app/Filament/Pages/Settings/PaymentConfigurationPage.php',
            $base.'/app/Filament/Pages/Settings/PaymentGatewayPage.php',
        ];
    }

    /** Pages/traits with no settings-persistence logic of their own. @return list<string> */
    public static function readOnlyOrInfrastructure(): array
    {
        $base = base_path();

        return [
            $base.'/app/Filament/Pages/Settings/HasSettingsAccess.php',
            $base.'/app/Filament/Pages/Settings/LogsSettingsUpdates.php',
            $base.'/app/Filament/Pages/Security/HasSecurityAccess.php',
        ];
    }

    /** @return list<string> The six Security pages — persist via SecuritySettingsService, not the trait directly. */
    public static function securityPagesDelegatingToService(): array
    {
        $base = base_path();

        return [
            $base.'/app/Filament/Pages/Security/AuthenticationPage.php',
            $base.'/app/Filament/Pages/Security/PasswordPolicyPage.php',
            $base.'/app/Filament/Pages/Security/LoginSecurityPage.php',
            $base.'/app/Filament/Pages/Security/SessionPage.php',
            $base.'/app/Filament/Pages/Security/RegistrationPage.php',
            $base.'/app/Filament/Pages/Security/AccountProtectionPage.php',
        ];
    }

    public function test_governed_pages_reference_the_shared_atomic_audited_helper(): void
    {
        foreach (self::governedFiles() as $file) {
            $this->assertFileExists($file);
            $source = file_get_contents($file);

            $this->assertTrue(
                str_contains($source, 'saveSettingsWithAudit('),
                basename($file).' must persist settings via LogsSettingsUpdates::saveSettingsWithAudit(), not a manual save()+log sequence.'
            );
        }
    }

    public function test_payment_subpages_delegate_to_the_governed_base_class(): void
    {
        foreach (self::delegatingToBaseClass() as $file) {
            $this->assertFileExists($file);
            $source = file_get_contents($file);

            $this->assertMatchesRegularExpression(
                '/\$this->save\w+Settings\(/',
                $source,
                basename($file).' must delegate to a save*Settings() helper on its governed PaymentSettingsPage base, not persist settings itself.'
            );
        }
    }

    public function test_security_pages_delegate_to_the_governed_service(): void
    {
        foreach (self::securityPagesDelegatingToService() as $file) {
            $this->assertFileExists($file);
            $source = file_get_contents($file);

            $this->assertTrue(
                str_contains($source, 'SecuritySettingsService'),
                basename($file).' must persist Security settings via SecuritySettingsService, the sole governed surface for these six pages.'
            );
        }
    }

    /**
     * The defining regression this test exists to catch: a Settings
     * object saved directly, bypassing the atomic+audited path — for
     * example GeneralSettingsPage::resetDefaults() previously
     * called $settings->save() a second time outside
     * saveSettingsWithAudit(), producing a completely unaudited reset.
     */
    public function test_no_governed_or_delegating_file_calls_save_directly_on_a_settings_object(): void
    {
        foreach ([...self::governedFiles(), ...self::delegatingToBaseClass(), ...self::securityPagesDelegatingToService()] as $file) {
            if (str_ends_with($file, 'LogsSettingsUpdates.php')) {
                continue;
            }

            $source = file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/\$\w+->save\(\)/',
                $source,
                basename($file).' calls ->save() directly on a Settings object — this must go through saveSettingsWithAudit() instead so the settings write and its audit record commit atomically.'
            );
        }
    }

    /**
     * Future-proofing: any new file added under Settings/Pages or
     * Security/Pages must be explicitly classified above (governed,
     * delegating, read-only/infrastructure, or Security-service-backed)
     * — a new mutation surface added without wiring in audit routing
     * fails this test instead of silently going unaudited.
     */
    public function test_no_undeclared_settings_or_security_page_exists_without_classification(): void
    {
        $classified = [
            ...self::governedFiles(),
            ...self::delegatingToBaseClass(),
            ...self::readOnlyOrInfrastructure(),
            ...self::securityPagesDelegatingToService(),
        ];

        $directories = [
            base_path('app/Filament/Pages/Settings'),
            base_path('app/Filament/Pages/Security'),
        ];

        foreach ($directories as $directory) {
            foreach (glob($directory.'/*.php') as $file) {
                $this->assertContains(
                    $file,
                    $classified,
                    basename($file).' is a new/undeclared Settings or Security page — classify it in '.self::class.' as governed, delegating, read-only, or service-backed.'
                );
            }
        }
    }
}
