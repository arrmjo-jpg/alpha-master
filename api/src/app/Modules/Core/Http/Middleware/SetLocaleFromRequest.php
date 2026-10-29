<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the response language from Accept-Language (ADR-0007). Unsupported
 * or missing values fall back to app.locale; the locale never affects authorization.
 */
class SetLocaleFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::negotiate($request->header('Accept-Language'));
        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        $response->setVary('Accept-Language', false);

        return $response;
    }

    public static function negotiate(?string $header): string
    {
        $supported = config('app.supported_locales');
        $default = in_array(config('app.locale'), $supported, true) ? config('app.locale') : $supported[0];

        $best = null;
        $bestQuality = 0.0;

        foreach (explode(',', (string) $header) as $part) {
            $segments = explode(';', trim($part));
            $language = strtolower(explode('-', trim($segments[0]))[0]);
            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (preg_match('/^\s*q=([01](?:\.\d{1,3})?)\s*$/', $parameter, $m)) {
                    $quality = (float) $m[1];
                }
            }

            if ($quality > $bestQuality && in_array($language, $supported, true)) {
                $best = $language;
                $bestQuality = $quality;
            }
        }

        return $best ?? $default;
    }
}
