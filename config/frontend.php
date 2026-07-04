<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public Frontend defaults
    |--------------------------------------------------------------------------
    |
    | Structural/theme defaults for the public frontend that aren't tied to
    | a specific record (unlike page-level SEO, which already comes from
    | GeneralSettings/SeoSettings via Spatie Settings — see docs/frontend.md).
    | Env-driven because these are deploy-time constants, not admin-editable.
    |
    */

    'default_og_image' => env('FRONTEND_DEFAULT_OG_IMAGE'),

    'announcement' => [
        'enabled' => env('FRONTEND_ANNOUNCEMENT_ENABLED', false),
        'message' => env('FRONTEND_ANNOUNCEMENT_MESSAGE'),
        'url' => env('FRONTEND_ANNOUNCEMENT_URL'),
        'action_label' => env('FRONTEND_ANNOUNCEMENT_ACTION_LABEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Responsive breakpoints (px)
    |--------------------------------------------------------------------------
    |
    | Mirrors Tailwind's default scale so JS (e.g. Alpine viewport checks)
    | can stay in sync with the CSS breakpoints without hardcoding numbers
    | in multiple places.
    |
    */

    'breakpoints' => [
        'sm' => 640,
        'md' => 768,
        'lg' => 1024,
        'xl' => 1280,
    ],

];
