<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identité visuelle, sans authentification : l'écran de connexion affiche déjà le nom, le logo et les couleurs.
 * Rien de confidentiel : ce sont les informations imprimées sur les reçus.
 */
class BrandingController extends Controller
{
    public function show(): JsonResponse
    {
        $branding = Setting::branding();

        return response()->json(['data' => $branding + [
            'logo_url' => $branding['logo_version'] ? url('/api/v1/branding/logo').'?v='.$branding['logo_version'] : null,
        ]]);
    }

    public function logo(): Response
    {
        $logo = Setting::get(Setting::BRAND_LOGO);
        if (! is_array($logo) || ! Storage::disk('local')->exists($logo['path'])) {
            abort(404);
        }

        // L'adresse change avec la version (?v=) : le navigateur peut garder l'image en cache longtemps.
        return Storage::disk('local')->response($logo['path'], null, [
            'Content-Type' => $logo['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
