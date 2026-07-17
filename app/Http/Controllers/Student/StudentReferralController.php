<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Settings\FeatureSettings;
use Illuminate\View\View;

final class StudentReferralController extends Controller
{
    public function index(FeatureSettings $features): View
    {
        abort_unless($features->referral_enabled, 404);
        abort_unless(auth()->user()?->hasRole('student'), 403);

        return view('student.referrals.index');
    }
}
