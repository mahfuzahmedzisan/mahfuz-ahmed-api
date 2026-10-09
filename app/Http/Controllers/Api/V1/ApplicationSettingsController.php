<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApplicationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationSettingsController extends Controller
{
    public function publicShow(ApplicationSettings $settings): JsonResponse
    {
        return $this->apiSuccess('Application settings.', [
            'settings' => $settings->publicGeneral(),
        ]);
    }

    public function show(ApplicationSettings $settings): JsonResponse
    {
        return $this->apiSuccess('Application settings.', [
            'settings' => $settings->adminPayload(),
        ]);
    }

    public function updateGeneral(Request $request, ApplicationSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'short_name' => ['nullable', 'string', 'max:40'],
            'registration_enabled' => ['required', 'boolean'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
        ]);

        $this->putOrForget($settings, 'general', 'name', $validated['name'] ?? null);
        $this->putOrForget($settings, 'general', 'short_name', $validated['short_name'] ?? null);
        $settings->put('general', 'registration_enabled', (bool) $validated['registration_enabled']);

        if ($request->boolean('remove_logo')) {
            $settings->removeBrand('logo');
        }

        if ($request->boolean('remove_favicon')) {
            $settings->removeBrand('favicon');
        }

        return $this->apiSuccess('General settings saved.', [
            'settings' => $settings->adminPayload(),
        ]);
    }

    public function updateSmtp(Request $request, ApplicationSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', 'string', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validated['enabled']) {
            $request->validate([
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', 'min:1', 'max:65535'],
                'from_address' => ['required', 'email', 'max:255'],
            ]);
        }

        $settings->put('smtp', 'enabled', (bool) $validated['enabled']);
        $this->putOrForget($settings, 'smtp', 'host', $validated['host'] ?? null);
        $settings->put('smtp', 'port', (int) ($validated['port'] ?? 587));
        $settings->put('smtp', 'encryption', $validated['encryption'] ?? 'tls');
        $this->putOrForget($settings, 'smtp', 'username', $validated['username'] ?? null);
        $this->putOrForget($settings, 'smtp', 'from_address', $validated['from_address'] ?? null);
        $this->putOrForget($settings, 'smtp', 'from_name', $validated['from_name'] ?? null);

        $password = $validated['password'] ?? null;
        if (is_string($password) && $password !== '') {
            $settings->storeSmtpPassword($password);
        }

        return $this->apiSuccess('Mail settings saved.', [
            'settings' => $settings->adminPayload(),
        ]);
    }

    public function uploadLogo(Request $request, ApplicationSettings $settings): JsonResponse
    {
        return $this->uploadBrand($request, $settings, 'logo');
    }

    public function uploadFavicon(Request $request, ApplicationSettings $settings): JsonResponse
    {
        return $this->uploadBrand($request, $settings, 'favicon');
    }

    private function uploadBrand(Request $request, ApplicationSettings $settings, string $kind): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'extensions:jpg,jpeg,png,gif,webp,svg,ico'],
        ]);

        $settings->storeBrand($kind, $request->file('file'));

        return $this->apiSuccess('Brand file saved.', [
            'settings' => $settings->adminPayload(),
        ]);
    }

    private function putOrForget(ApplicationSettings $settings, string $group, string $key, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            $settings->forget($group, $key);

            return;
        }

        $settings->put($group, $key, trim($value));
    }
}
