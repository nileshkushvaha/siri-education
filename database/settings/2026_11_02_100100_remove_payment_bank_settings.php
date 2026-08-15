<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Removes the offline bank-transfer settings group along with the Bank
 * Account settings page.
 *
 * Nothing in the payment domain ever read these values to collect,
 * reconcile, or settle money — they only drove an admin form and two
 * display toggles. Payment collection runs entirely through the
 * gateway/attempt-ledger architecture, so this group was configuration
 * with no consumer.
 *
 * `down()` restores the group with its original defaults; it cannot
 * restore the account details that were stored, which is the expected
 * trade-off for deleting a settings group.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach ([
            'enable_offline_payment',
            'account_holder_name',
            'bank_name',
            'branch_name',
            'account_number',
            'ifsc_code',
            'swift_code',
            'iban',
            'account_type',
            'upi_id',
            'qr_code_image',
            'payment_instructions',
            'display_on_invoice',
            'display_on_payment_page',
        ] as $field) {
            $this->migrator->deleteIfExists("payment_bank.{$field}");
        }
    }

    public function down(): void
    {
        $this->migrator->add('payment_bank.enable_offline_payment', false);
        $this->migrator->add('payment_bank.account_holder_name', null);
        $this->migrator->add('payment_bank.bank_name', null);
        $this->migrator->add('payment_bank.branch_name', null);
        $this->migrator->add('payment_bank.account_number', null);
        $this->migrator->add('payment_bank.ifsc_code', null);
        $this->migrator->add('payment_bank.swift_code', null);
        $this->migrator->add('payment_bank.iban', null);
        $this->migrator->add('payment_bank.account_type', 'savings');
        $this->migrator->add('payment_bank.upi_id', null);
        $this->migrator->add('payment_bank.qr_code_image', null);
        $this->migrator->add('payment_bank.payment_instructions', null);
        $this->migrator->add('payment_bank.display_on_invoice', false);
        $this->migrator->add('payment_bank.display_on_payment_page', false);
    }
};
