<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PortalAudience;
use App\Services\FrontendPortalAudienceResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSupportedFrontendPortalAudience
{
    public function __construct(private readonly FrontendPortalAudienceResolver $audiences) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            $this->audiences->resolve($request->user()) === PortalAudience::AdminOrUnsupported,
            403,
        );

        return $next($request);
    }
}
