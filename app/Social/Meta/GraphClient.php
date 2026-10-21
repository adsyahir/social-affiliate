<?php

namespace App\Social\Meta;

use App\Social\Exceptions\AccessTokenException;
use App\Social\Exceptions\SocialApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Calls to a Meta Graph API (Threads, Facebook) with the error handling every endpoint shares.
 */
class GraphClient
{
    /**
     * Meta error codes for throttling (4, 17, 32, 613) and temporary failures (1, 2).
     */
    private const RETRYABLE_CODES = [1, 2, 4, 17, 32, 613];

    /**
     * Invalid, expired or revoked token.
     */
    private const TOKEN_ERROR_CODE = 190;

    public function __construct(
        private readonly string $baseUrl,
        #[SensitiveParameter] private readonly string $token,
        private readonly string $platform,
    ) {}

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     *
     * @throws SocialApiException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->url($path), [...$query, 'access_token' => $this->token]));
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     *
     * @throws SocialApiException
     */
    public function post(string $path, array $params = []): array
    {
        return $this->send(fn (PendingRequest $http) => $http->asForm()->post($this->url($path), [...$params, 'access_token' => $this->token]));
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request): array
    {
        try {
            $response = $request(Http::acceptJson()->timeout(20)->connectTimeout(5));
        } catch (ConnectionException $e) {
            // GET requests carry the token in the URL, and the HTTP error message quotes the URL. The
            // original exception is not chained, so the token cannot reach job failure logs either.
            Log::warning("{$this->platform} API unreachable", ['error' => str_replace($this->token, '[token]', $e->getMessage())]);

            throw new SocialApiException("Could not reach the {$this->platform} API.", retryable: true);
        }

        $json = $response->json();
        $error = is_array($json) ? ($json['error'] ?? null) : null;

        if (is_array($error)) {
            $this->throwApiError($error, $response->status());
        }

        if ($response->failed() || ! is_array($json)) {
            Log::warning("{$this->platform} API HTTP error", ['status' => $response->status()]);

            throw new SocialApiException(
                "{$this->platform} API returned HTTP {$response->status()}.",
                retryable: $response->serverError() || $response->status() === 429,
            );
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function throwApiError(array $error, int $status): never
    {
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? 'Unknown error');

        Log::warning("{$this->platform} API error", [
            'status' => $status,
            'code' => $code,
            'subcode' => $error['error_subcode'] ?? null,
            'message' => $message,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
        ]);

        if ($code === self::TOKEN_ERROR_CODE) {
            throw new AccessTokenException("{$this->platform} rejected the access token: {$message}", apiCode: $code);
        }

        throw new SocialApiException(
            "{$this->platform} API error {$code}: {$message}",
            retryable: ($error['is_transient'] ?? false) === true
                || in_array($code, self::RETRYABLE_CODES, true)
                || $status >= 500,
            apiCode: $code,
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
