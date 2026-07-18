<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Contracts\PhoneVerificationServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PhoneVerificationController extends Controller
{
    public function send(Request $request, PhoneVerificationServiceInterface $verification): RedirectResponse
    {
        $verification->send($request->user(), $request->ip() ?? 'unknown');

        return back()->with('success', 'A verification code was sent to your mobile number.');
    }

    public function verify(Request $request, PhoneVerificationServiceInterface $verification): RedirectResponse
    {
        $data = $request->validate(['otp' => ['required', 'digits:6']]);
        $verification->verify($request->user(), $data['otp'], $request->ip() ?? 'unknown');

        return back()->with('success', 'Your mobile number is verified.');
    }
}
