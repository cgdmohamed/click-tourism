<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language');
        if ($locale) {
            $locale = substr(strtolower($locale), 0, 2);
        }

        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = $request->user()?->locale ?: config('app.locale', 'ar');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
