<?php

namespace Modules\Organisation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Organisation\Services\TillDeviceService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a paired till device (X-Till-Token header). The resolved till is
 * available to controllers as $request->attributes->get('till').
 */
class EnsureTillDevice
{
    public function __construct(private readonly TillDeviceService $tills) {}

    public function handle(Request $request, Closure $next): Response
    {
        $till = $this->tills->resolve($request->header(TillDeviceService::HEADER));

        if (! $till) {
            return response()->json([
                'success' => false,
                'message' => 'This device is not set up as a till.',
                'statusCode' => 403,
                'errors' => ['device' => ['unpaired']],
            ], 403);
        }

        $request->attributes->set('till', $till);

        return $next($request);
    }
}
