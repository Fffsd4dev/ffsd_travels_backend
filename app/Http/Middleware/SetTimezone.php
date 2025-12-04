<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

class SetTimezone
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Set the default timezone to Africa/Lagos
        date_default_timezone_set('Africa/Lagos');

        // Optionally, set Carbon locale if needed
        Carbon::setLocale('en');  // Adjust locale if you need date formatting

        return $next($request);
    }
}
