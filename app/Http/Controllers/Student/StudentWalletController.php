<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Settings\FeatureSettings;
use Illuminate\View\View;

final class StudentWalletController extends Controller
{
    public function index(FeatureSettings $features): View
    {
        abort_unless($features->wallet_enabled, 404);

        return view('student.wallet.index');
    }
}
