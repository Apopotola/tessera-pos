<?php

namespace Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Settings\Http\Requests\SettingScopeRequest;
use Modules\Settings\Services\SettingsSchema;
use Modules\Settings\Services\SettingsService;
use OpenApi\Attributes as OA;

/** The Settings area: schema with current values per scope, changes, reset, undo, history and presets. */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SettingsSchema $schema,
    ) {}

    #[OA\Get(path: '/api/v1/settings/schema', summary: 'Every setting with its value at a scope, who may change it, presets and locked items', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Schema')])]
    public function schema(SettingScopeRequest $request): JsonResponse
    {
        return $this->success('Settings.', $this->schema->build($request->user(), $request->scope(), $request->scopeId()));
    }

    #[OA\Put(path: '/api/v1/settings/values/{key}', summary: 'Change a setting at a scope (logged)', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Updated field')])]
    public function update(SettingScopeRequest $request, string $key): JsonResponse
    {
        $this->settings->set($request->user(), $key, $request->scope(), $request->scopeId(), $request->input('value'));

        return $this->success('Setting saved.', $this->schema->field($request->user(), $key, $request->scope(), $request->scopeId()));
    }

    #[OA\Delete(path: '/api/v1/settings/values/{key}', summary: 'Remove the override at a scope so the parent value applies', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Updated field')])]
    public function reset(SettingScopeRequest $request, string $key): JsonResponse
    {
        $this->settings->reset($request->user(), $key, $request->scope(), $request->scopeId());

        return $this->success('Back to the inherited value.', $this->schema->field($request->user(), $key, $request->scope(), $request->scopeId()));
    }

    #[OA\Post(path: '/api/v1/settings/values/{key}/undo', summary: 'Undo the last change of a setting at a scope', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Updated field')])]
    public function undo(SettingScopeRequest $request, string $key): JsonResponse
    {
        $this->settings->undo($request->user(), $key, $request->scope(), $request->scopeId());

        return $this->success('Change undone.', $this->schema->field($request->user(), $key, $request->scope(), $request->scopeId()));
    }

    #[OA\Get(path: '/api/v1/settings/values/{key}/history', summary: 'Last 20 changes of a setting at a scope', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'History')])]
    public function history(SettingScopeRequest $request, string $key): JsonResponse
    {
        return $this->success('History.', $this->settings->history($key, $request->scope(), $request->scopeId()));
    }

    #[OA\Get(path: '/api/v1/settings/presets/{preset}/preview', summary: 'What applying an industry preset would change', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Changes')])]
    public function presetPreview(SettingScopeRequest $request, string $preset): JsonResponse
    {
        return $this->success('Preset preview.', $this->settings->presetPreview($preset));
    }

    #[OA\Post(path: '/api/v1/settings/presets/{preset}/apply', summary: 'Apply an industry preset (keeps your own changes unless told otherwise)', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Applied')])]
    public function presetApply(SettingScopeRequest $request, string $preset): JsonResponse
    {
        $written = $this->settings->applyPreset($request->user(), $preset, $request->boolean('overwriteYourChanges'));

        return $this->success("Preset applied: {$written} setting(s) changed.", ['written' => $written]);
    }

    #[OA\Get(path: '/api/v1/settings/app', summary: 'Settings the back office needs for the signed-in user (no secrets)', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Values')])]
    public function app(Request $request): JsonResponse
    {
        return $this->success('Settings.', $this->schema->forUser($request->user(), null));
    }

    #[OA\Get(path: '/api/v1/settings/till', summary: 'Settings for this till (paired device)', tags: ['Settings'], responses: [new OA\Response(response: 200, description: 'Values')])]
    public function till(Request $request): JsonResponse
    {
        return $this->success('Settings.', $this->schema->forUser($request->user(), $request->attributes->get('till')));
    }
}
