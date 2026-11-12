<?php

namespace App\Http\Controllers\Api;

use App\Modules\Integrations\Services\IntegrationSettings;
use App\Modules\Integrations\Services\MetaLeadIngestionService;
use App\Modules\Integrations\Services\WhatsAppWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta Lead Ads / WhatsApp Cloud API webhooks (PRD FR-INT-*).
 *
 * Both integrations are optional and DISABLED by default (settings
 * `integrations.meta_leads_enabled` / `integrations.whatsapp_enabled`); a
 * disabled integration answers 404 and ingests nothing. Secrets come from env.
 * Live acceptance is blocked until credentials and Meta app review exist; the
 * verification and signature paths are covered by fixture tests.
 *
 * UNVERIFIED (confirm in official Meta webhook docs): verification handshake
 * parameters hub.mode / hub.verify_token / hub.challenge, and the
 * `X-Hub-Signature-256: sha256=<HMAC-SHA256(raw body, app secret)>` header.
 */
class WebhookController extends ApiController
{
    public function verifyMeta(Request $request, IntegrationSettings $settings): Response|JsonResponse
    {
        if (! $settings->metaEnabled()) {
            return $this->disabled();
        }

        $response = $this->verify($request, (string) config('services.meta.verify_token'));
        if ($response->getStatusCode() === 200) {
            $settings->markVerified('meta');
        }

        return $response;
    }

    public function verifyWhatsApp(Request $request, IntegrationSettings $settings): Response|JsonResponse
    {
        if (! $settings->whatsappEnabled()) {
            return $this->disabled();
        }

        $response = $this->verify($request, (string) config('services.whatsapp.verify_token'));
        if ($response->getStatusCode() === 200) {
            $settings->markVerified('whatsapp');
        }

        return $response;
    }

    public function receiveMeta(Request $request, IntegrationSettings $settings, MetaLeadIngestionService $meta): JsonResponse
    {
        if (! $settings->metaEnabled()) {
            return $this->disabled();
        }
        if ($failure = $this->rejectUnsigned($request, (string) config('services.meta.app_secret'))) {
            return $failure;
        }

        $data = json_decode($request->getContent(), true);
        if (! is_array($data)) {
            return response()->json(['message' => 'Invalid JSON payload.', 'code' => 'invalid_payload'], 400);
        }

        return $this->ok(['status' => $meta->handleWebhook($data, $request->getContent())]);
    }

    public function receiveWhatsApp(Request $request, IntegrationSettings $settings, WhatsAppWebhookHandler $whatsapp): JsonResponse
    {
        if (! $settings->whatsappEnabled()) {
            return $this->disabled();
        }
        if ($failure = $this->rejectUnsigned($request, (string) config('services.whatsapp.app_secret'))) {
            return $failure;
        }

        $data = json_decode($request->getContent(), true);
        if (! is_array($data)) {
            return response()->json(['message' => 'Invalid JSON payload.', 'code' => 'invalid_payload'], 400);
        }

        return $this->ok(['status' => $whatsapp->handle($data, $request->getContent())]);
    }

    private function disabled(): JsonResponse
    {
        return response()->json(['message' => 'This integration is disabled.', 'code' => 'integration_disabled'], 404);
    }

    private function verify(Request $request, string $expectedToken): Response|JsonResponse
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($expectedToken === '') {
            return response()->json([
                'message' => 'Webhook verification token is not configured.',
                'code' => 'webhook_not_configured',
            ], 503);
        }

        if ($mode !== 'subscribe' || ! hash_equals($expectedToken, $token)) {
            return response()->json(['message' => 'Verification failed.', 'code' => 'verification_failed'], 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /** Returns an error response when the HMAC signature is missing/wrong, else null. */
    private function rejectUnsigned(Request $request, string $secret): ?JsonResponse
    {
        if ($secret === '') {
            // A missing secret is a configuration error everywhere except
            // local/test, which may ingest unsigned fixture payloads.
            if (! app()->environment(['local', 'testing'])) {
                return response()->json([
                    'message' => 'Webhook app secret is not configured.',
                    'code' => 'webhook_not_configured',
                ], 503);
            }

            return null;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, (string) $request->header('X-Hub-Signature-256', ''))) {
            return response()->json(['message' => 'Invalid signature.', 'code' => 'invalid_signature'], 403);
        }

        return null;
    }
}
