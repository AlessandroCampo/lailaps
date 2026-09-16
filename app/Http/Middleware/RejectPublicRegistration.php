<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectPublicRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.registration_enabled') && $request->is('register')) {
            abort(404);
        }

        return $next($request);
    }
}
