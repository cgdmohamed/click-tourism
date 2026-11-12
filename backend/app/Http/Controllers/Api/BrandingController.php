<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\CompanyLogoService;
use App\Modules\Core\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Company branding. The read endpoints are public on purpose (the login page
 * needs them) and expose only the two company names and the logo image: no
 * storage path, no other setting. Upload and removal need settings.update.
 */
class BrandingController extends ApiController
{
    public function show(SettingService $settings, CompanyLogoService $logo): JsonResponse
    {
        return $this->ok($this->payload($settings, $logo));
    }

    public function logo(CompanyLogoService $logo): Response
    {
        $image = $logo->image();
        abort_if($image === null, 404);

        return response($image['binary'], 200, [
            'Content-Type' => $image['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function upload(Request $request, SettingService $settings, CompanyLogoService $logo, AuditService $audit): JsonResponse
    {
        $request->validate(['logo' => ['required', 'file', 'max:1024']]);

        $before = $logo->version();
        $logo->store($request->file('logo'));
        $audit->log('settings.logo_updated', null, ['has_logo' => $before !== null], ['has_logo' => true, 'logo_version' => $logo->version()], $this->actorId());

        return $this->ok($this->payload($settings, $logo));
    }

    public function destroy(SettingService $settings, CompanyLogoService $logo, AuditService $audit): JsonResponse
    {
        $had = $logo->has();
        $logo->remove();
        $audit->log('settings.logo_removed', null, ['has_logo' => $had], ['has_logo' => false], $this->actorId());

        return $this->ok($this->payload($settings, $logo));
    }

    /** @return array{company_name_ar: string, company_name_en: string, has_logo: bool, logo_version: ?string} */
    private function payload(SettingService $settings, CompanyLogoService $logo): array
    {
        return [
            'company_name_ar' => (string) $settings->get('general', 'company_name_ar', ''),
            'company_name_en' => (string) $settings->get('general', 'company_name_en', ''),
            'has_logo' => $logo->has(),
            'logo_version' => $logo->version(),
        ];
    }
}
