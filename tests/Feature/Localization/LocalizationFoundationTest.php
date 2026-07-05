<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Language;
use Database\Seeders\CountrySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_can_reference_default_currency_and_language(): void
    {
        $currency = Currency::factory()->create(['code' => 'USD']);
        $language = Language::factory()->create(['code' => 'en']);

        $country = Country::factory()->create([
            'iso2' => 'US',
            'iso3' => 'USA',
            'default_currency_id' => $currency->id,
            'default_language_id' => $language->id,
            'default_timezone' => 'America/New_York',
            'support_email' => 'support-us@example.com',
            'support_phone' => '+1 000 000 0000',
            'date_format' => 'm/d/Y',
            'time_format' => 'h:i A',
            'number_format' => '1,234.56',
        ]);

        $this->assertTrue($country->defaultCurrency->is($currency));
        $this->assertTrue($country->defaultLanguage->is($language));
        $this->assertSame('America/New_York', $country->default_timezone);
    }

    public function test_inactive_country_keeps_localization_relationships_for_historical_data(): void
    {
        $currency = Currency::factory()->create(['code' => 'CAD']);
        $language = Language::factory()->create(['code' => 'en']);

        $country = Country::factory()->create([
            'status' => 'inactive',
            'default_currency_id' => $currency->id,
            'default_language_id' => $language->id,
        ]);

        $this->assertSame('inactive', $country->status);
        $this->assertSame('CAD', $country->defaultCurrency->code);
        $this->assertSame('en', $country->defaultLanguage->code);
        $this->assertFalse(Country::active()->whereKey($country)->exists());
    }

    public function test_seeders_create_required_currencies_and_country_defaults(): void
    {
        $this->seed([
            CurrencySeeder::class,
            LanguageSeeder::class,
            CountrySeeder::class,
        ]);

        $this->assertDatabaseHas('currencies', ['code' => 'INR', 'status' => 'active']);
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'status' => 'active']);
        $this->assertDatabaseHas('currencies', ['code' => 'GBP', 'status' => 'active']);
        $this->assertDatabaseHas('currencies', ['code' => 'CAD', 'status' => 'active']);
        $this->assertDatabaseHas('currencies', ['code' => 'AUD', 'status' => 'active']);

        $india = Country::query()->where('iso2', 'IN')->firstOrFail();
        $usa = Country::query()->where('iso2', 'US')->firstOrFail();
        $uk = Country::query()->where('iso2', 'GB')->firstOrFail();
        $canada = Country::query()->where('iso2', 'CA')->firstOrFail();
        $australia = Country::query()->where('iso2', 'AU')->firstOrFail();

        $this->assertSame('INR', $india->defaultCurrency->code);
        $this->assertSame('USD', $usa->defaultCurrency->code);
        $this->assertSame('GBP', $uk->defaultCurrency->code);
        $this->assertSame('CAD', $canada->defaultCurrency->code);
        $this->assertSame('AUD', $australia->defaultCurrency->code);
        $this->assertSame('Asia/Kolkata', $india->default_timezone);
        $this->assertSame('en', $india->defaultLanguage->code);
    }
}
