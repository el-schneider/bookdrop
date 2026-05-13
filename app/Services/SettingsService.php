<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingsService
{
    public function settings(): Setting
    {
        $settings = Setting::query()->firstOrNew(['id' => 1]);

        $changed = false;

        if (blank($settings->kobo_token)) {
            $settings->kobo_token = Str::random(64);
            $changed = true;
        }

        if (blank($settings->public_base_url) && filled(config('bookdrop.public_base_url'))) {
            $settings->public_base_url = rtrim((string) config('bookdrop.public_base_url'), '/');
            $changed = true;
        }

        if (! $settings->exists || $changed) {
            $settings->save();
        }

        return $settings;
    }

    public function koboToken(): string
    {
        return $this->settings()->kobo_token;
    }

    public function publicBaseUrl(?Request $request = null): string
    {
        $configured = $this->settings()->public_base_url;

        if (filled($configured)) {
            return rtrim($configured, '/');
        }

        abort_if(app()->isProduction(), 500, 'BOOKDROP_PUBLIC_BASE_URL must be set in production.');

        if ($request !== null) {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return rtrim((string) config('app.url'), '/');
    }

    public function koboEndpoint(?Request $request = null): string
    {
        return $this->publicBaseUrl($request).'/kobo/'.$this->koboToken();
    }
}
