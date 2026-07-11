{{-- Decrypted payout details, rendered only inside the permission-gated
     modal. Values never enter table state, URLs, or logs. --}}
<dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
    @foreach ([
        'account_holder_name' => 'Account holder',
        'bank_name' => 'Bank',
        'branch_name' => 'Branch',
        'account_number' => 'Account number',
        'iban' => 'IBAN',
        'routing_type' => 'Routing type',
        'routing_number' => 'Routing number',
        'swift_bic' => 'SWIFT / BIC',
        'account_type' => 'Account type',
        'beneficiary_address' => 'Beneficiary address',
    ] as $key => $label)
        @if (! empty($details[$key]))
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="mt-1 font-mono text-gray-950 dark:text-white">{{ $details[$key] }}</dd>
            </div>
        @endif
    @endforeach
</dl>
