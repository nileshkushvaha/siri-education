<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Instructor\InstructorService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstructorController extends Controller
{
    public function __construct(
        private readonly InstructorService $instructorService,
    ) {}

    public function index(Request $request): View
    {
        $instructors = $this->instructorService->directory($request);
        $filters = $this->instructorService->filters();

        return view('instructors.index', compact('instructors', 'filters'));
    }

    public function show(Request $request, User $user): View
    {
        return view('instructors.show', $this->instructorService->publicProfile($user, $request->user()));
    }
}
