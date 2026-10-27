<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse toute requête d'un compte désactivé, même avec un jeton encore valide.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->active) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'Ce compte est désactivé.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
