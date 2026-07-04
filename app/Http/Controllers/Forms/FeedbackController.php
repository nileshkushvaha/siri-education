<?php

declare(strict_types=1);

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class FeedbackController extends Controller
{
    public function show(): View
    {
        return view('forms.feedback');
    }
}
