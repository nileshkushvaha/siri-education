<?php

declare(strict_types=1);

namespace App\Services\Phone;

use App\DTOs\PhoneNumberData;
use App\Models\Country;
use Illuminate\Validation\ValidationException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Throwable;

final class PhoneNumberService
{
    public function normalize(?string $input, ?string $countryIso2): ?PhoneNumberData
    {
        if (blank($input)) {
            return null;
        }

        $iso2 = strtoupper(trim((string) $countryIso2));
        if (mb_strlen($input) > 40 || ! Country::query()->active()->where('iso2', $iso2)->exists()) {
            $this->invalid();
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($input, $iso2);
            if (! $util->isPossibleNumber($number) || ! $util->isValidNumberForRegion($number, $iso2)) {
                $this->invalid();
            }

            return new PhoneNumberData(
                countryIso2: $iso2,
                dialCode: '+'.$number->getCountryCode(),
                nationalNumber: (string) $number->getNationalNumber(),
                e164: $util->format($number, PhoneNumberFormat::E164),
            );
        } catch (Throwable) {
            $this->invalid();
        }
    }

    public function masked(?string $e164): ?string
    {
        if (blank($e164)) {
            return null;
        }

        return mb_substr($e164, 0, 2).' ••• ••• '.mb_substr($e164, -4);
    }

    public function exampleNationalNumber(string $countryIso2): string
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->getExampleNumberForType(strtoupper($countryIso2), PhoneNumberType::MOBILE)
                ?? $util->getExampleNumber(strtoupper($countryIso2));

            return $number === null
                ? 'Enter mobile number'
                : $util->format($number, PhoneNumberFormat::NATIONAL);
        } catch (Throwable) {
            return 'Enter mobile number';
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['phone' => 'Please enter a valid mobile number for the selected phone country.']);
    }
}
