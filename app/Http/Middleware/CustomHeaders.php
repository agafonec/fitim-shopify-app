<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Answer preflights here: they carry no session token, so letting them
        // through to `verify.shopify` would fail them before CORS is negotiated.
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        // A single origin, echoed back: admin UI extensions call this app from the
        // Shopify CDN sandbox, and a list of origins is not valid CORS.
        $response->headers->set('Access-Control-Allow-Origin', $this->allowedOrigin($request));
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }

    protected function allowedOrigin(Request $request): string
    {
        $user = $request->user();

        $allowed = array_filter([
            'https://extensions.shopifycdn.com',
            'https://admin.shopify.com',
            $user instanceof User ? 'https://'.$user->name : null,
            config('app.url'),
        ]);

        $origin = $request->headers->get('Origin');

        return $origin && in_array($origin, $allowed, true) ? $origin : '*';
    }
}
