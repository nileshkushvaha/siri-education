<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX;

use App\Earnings\Exceptions\EarningException;

/** Provisioning-domain failures (Contact/Fund Account). Messages must stay safe for the UI — never account numbers, IFSC, or raw provider payloads. */
class RazorpayXProvisioningException extends EarningException {}
