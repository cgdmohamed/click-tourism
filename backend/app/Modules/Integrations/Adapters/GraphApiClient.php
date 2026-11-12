<?php

namespace App\Modules\Integrations\Adapters;

use App\Modules\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin Graph API HTTP wrapper shared by the Meta adapters. The access token is
 * sent in the Authorization header (never in the URL) so it cannot leak into
 * access logs or exception messages.
 *
 * UNVERIFIED: bearer-header authentication, error envelope shape
 * (`{"error": {"code", "type", "message"}}`) and the transient error-code list
 * below must be confirmed against the current Meta Graph API documentation
 * (developers.facebook.com/docs/graph-api) before go-live.
 */
class GraphApiClient
{
    /** UNVERIFIED: Meta error codes treated as temporary / rate-limit conditions. */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 32, 613, 80007, 130429, 131056];

    /** @return array<string, mixed> */
    public function get(string $url, string $token, string $context): array
    {
        return $this->send('get', $url, $token, [], $context);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $url, string $token, array $payload, string $context): array
    {
        return $this->send('post', $url, $token, $payload, $context);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $url, string $token, array $payload, string $context): array
    {
        if (trim($token) === '') {
            throw IntegrationException::notConfigured('access token');
        }

        try {
            $request = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(15);
            $response = $method === 'get' ? $request->get($url) : $request->asJson()->post($url, $payload);
        } catch (ConnectionException) {
            throw new IntegrationException("{$context}: connection failed.", true, 'connection_error');
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw $this->failure($response, $context);
    }

    private function failure(Response $response, string $context): IntegrationException
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $transient = $status === 429 || $status >= 500 || (is_numeric($code) && in_array((int) $code, self::TRANSIENT_CODES, true));

        return new IntegrationException(
            sprintf('%s failed (HTTP %d%s).', $context, $status, $code !== null ? ', code '.$code : ''),
            $transient,
            $code !== null ? (string) $code : null,
            $status,
        );
    }
}
