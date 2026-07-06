<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UpdateProfileVisibilityRequest;
use App\Http\Requests\Profile\UploadAvatarRequest;
use App\Http\Requests\Profile\UploadCoverRequest;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\Language;
use App\Models\State;
use App\Models\Subject;
use App\Services\Profile\ProfileCompletionService;
use App\Services\Profile\ProfileService;
use App\Services\Profile\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly SessionService $sessionService,
        private readonly ProfileCompletionService $completionService,
    ) {}

    public function show(Request $request): View
    {
        $user = auth()->user()->load(['profile.country', 'profile.state', 'profile.media', 'profile.studentAcademicLevel', 'profile.studentPreferredLanguage', 'preferredSubjects']);
        $countries = Country::active()->orderBy('name')->get(['id', 'name', 'iso2', 'flag']);
        $states = State::active()->orderBy('name')->get(['id', 'country_id', 'name']);
        $subjects = Subject::availableForAssignment()->orderBy('name')->get(['id', 'name']);
        $academicLevels = AcademicLevel::availableForAssignment()->orderBy('display_order')->orderBy('name')->get(['id', 'name']);
        $languages = Language::active()->orderBy('name')->get(['id', 'name', 'code']);
        $timezones = \DateTimeZone::listIdentifiers();
        $loginHistory = $user->loginHistories()->limit(10)->get();
        $activeSessions = $this->sessionService->getSessionsForUser($user);
        $currentSessionId = $request->session()->getId();
        $completionBreakdown = $this->completionService->breakdown($user);

        return view('profile.show', compact(
            'user', 'countries', 'states', 'subjects', 'academicLevels', 'languages', 'timezones', 'loginHistory', 'activeSessions',
            'currentSessionId', 'completionBreakdown'
        ));
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $this->profileService->update(auth()->user(), $request->validated());

        return back()->with('success', 'Profile updated successfully.');
    }

    public function changePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $this->profileService->changePassword(
            auth()->user(),
            $request->validated('password'),
            $request->ip() ?? '127.0.0.1',
        );

        // Rebind session password hash so user stays logged in
        $request->session()->put(
            'password_hash_web',
            auth()->user()->getAuthPassword(),
        );

        return back()
            ->with('success', 'Password changed successfully.')
            ->with('active_tab', 'security');
    }

    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = auth()->user();
        $this->profileService->uploadAvatar($user, $request->file('avatar'));

        return response()->json([
            'success' => true,
            'url' => $user->profile->refresh()->avatar_url,
        ]);
    }

    public function deleteAvatar(): JsonResponse
    {
        $this->profileService->deleteAvatar(auth()->user());

        return response()->json(['success' => true]);
    }

    public function uploadCover(UploadCoverRequest $request): JsonResponse
    {
        $user = auth()->user();
        $this->profileService->uploadCover($user, $request->file('cover'));

        return response()->json([
            'success' => true,
            'url' => $user->profile->refresh()->cover_url,
        ]);
    }

    public function deleteCover(): JsonResponse
    {
        $this->profileService->deleteCover(auth()->user());

        return response()->json(['success' => true]);
    }

    public function updateVisibility(UpdateProfileVisibilityRequest $request): RedirectResponse
    {
        $this->profileService->updateVisibility(auth()->user(), $request->validated());

        return back()->with('success', 'Profile visibility updated.');
    }
}
