<?php

namespace Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Authorization\Support\Permissions;
use Modules\Settings\Services\SettingsSchema;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Public branding for the login page, branding images, and image uploads. */
class BrandingController extends Controller
{
    public function __construct(private readonly SettingsSchema $schema) {}

    #[OA\Get(path: '/api/v1/settings/public', summary: 'Branding for the login page (no sign-in needed)', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Branding')])]
    public function public(): JsonResponse
    {
        return $this->success('Branding.', $this->schema->publicBranding());
    }

    #[OA\Post(path: '/api/v1/settings/uploads', summary: 'Upload a branding image (logo, receipt logo, icon, login background)', tags: ['Settings'], responses: [new OA\Response(response: 201, description: 'Uploaded')])]
    public function upload(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::SETTINGS_BUSINESS, Permissions::SETTINGS_PLATFORM]), 403);
        $request->validate([
            'file' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg,svg,webp'],
        ]);

        $file = $request->file('file');
        $path = $file->storeAs('branding', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

        return $this->success('Image uploaded.', ['path' => $path, 'url' => route('api.v1.settings.files', ['path' => $path])], 201);
    }

    #[OA\Get(path: '/api/v1/settings/files/{path}', summary: 'A branding image (public)', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Image')])]
    public function file(string $path): StreamedResponse
    {
        abort_unless(str_starts_with($path, 'branding/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
            // An uploaded SVG must never run script, even when opened directly.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
