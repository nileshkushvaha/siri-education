<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PaymentConfigurationSettings extends Settings
{
    public string $currency;

    public string $currency_symbol;

    public int $decimal_precision;

    public float $default_tax_percent;

    public string $invoice_prefix;

    public int $invoice_number_length;

    public int $payment_due_days;

    public bool $allow_partial_payment;

    public bool $auto_generate_invoice;

    public bool $auto_capture_payment;

    public static function group(): string
    {
        return 'payment_configuration';
    }
}
