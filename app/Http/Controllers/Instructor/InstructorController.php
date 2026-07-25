<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Booking\Services\MarketplaceCountryResolver;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\User;
use App\Reviews\Contracts\PublicInstructorReviewServiceInterface;
use App\Reviews\DTOs\InstructorRatingSummaryData;
use App\Services\Instructor\InstructorService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstructorController extends Controller
{
    public function __construct(
        private readonly InstructorService $instructorService,
        private readonly PublicInstructorReviewServiceInterface $publicReviews,
        private readonly MarketplaceCountryResolver $marketplaceCountry,
    ) {}

    public function index(Request $request): View
    {
        $instructors = $this->instructorService->directory($request);
        $filters = $this->instructorService->filters();
        $pricingCountryContext = $this->marketplaceCountry->resolve($request->user(), $request);
        $pricingCountries = $request->user() === null
            ? Country::query()->active()->orderBy('name')->get(['id', 'iso2', 'name'])
            : collect();

        return view('instructors.index', compact('instructors', 'filters', 'pricingCountryContext', 'pricingCountries'));
    }

    public function show(Request $request, User $user): View
    {
        $profile = $this->instructorService->publicProfile($user, $request);

        $reviewSummary = $this->publicReviews->summaryFor($user);
        $reviews = $this->publicReviews->paginatedReviewsFor($user);
        $dimensionLabels = InstructorRatingSummaryData::dimensionLabels();

        return view('instructors.show', [
            ...$profile,
            'reviewSummary' => $reviewSummary,
            'reviews' => $reviews,
            'dimensionLabels' => $dimensionLabels,
        ]);
    }
}
