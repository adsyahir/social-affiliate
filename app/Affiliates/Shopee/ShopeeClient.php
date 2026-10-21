<?php

namespace App\Affiliates\Shopee;

use App\Affiliates\Exceptions\AffiliateApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Signed GraphQL calls to the Shopee Affiliate Open API.
 */
class ShopeeClient
{
    /**
     * Shopee error codes worth retrying later: system error and traffic limiting.
     */
    private const RETRYABLE_CODES = [10000, 10030];

    public function __construct(
        private readonly string $endpoint,
        private readonly string $appId,
        #[SensitiveParameter] private readonly string $secret,
    ) {}

    /**
     * Run a GraphQL operation and return its `data` object.
     *
     * @return array<string, mixed>
     *
     * @throws AffiliateApiException
     */
    public function query(string $query): array
    {
        // The signature covers the exact bytes sent, so the body is encoded once and sent as-is.
        $body = json_encode(['query' => $query], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash('sha256', $this->appId.$timestamp.$body.$this->secret);

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->retry(2, 250, fn (\Throwable $e): bool => $e instanceof ConnectionException, throw: false)
                ->withHeaders(['Authorization' => "SHA256 Credential={$this->appId}, Timestamp={$timestamp}, Signature={$signature}"])
                ->withBody($body, 'application/json')
                ->post($this->endpoint);
        } catch (ConnectionException $e) {
            Log::warning('Shopee Affiliate API unreachable', ['error' => $e->getMessage()]);

            throw new AffiliateApiException('Could not reach the Shopee Affiliate API.', retryable: true, previous: $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            Log::warning('Shopee Affiliate API HTTP error', ['status' => $response->status()]);

            throw new AffiliateApiException("Shopee Affiliate API returned HTTP {$response->status()}.", retryable: true);
        }

        $json = $response->json();

        if (is_array($json) && ! empty($json['errors'])) {
            $error = $json['errors'][0];
            $code = isset($error['extensions']['code']) ? (int) $error['extensions']['code'] : null;
            $message = $error['extensions']['message'] ?? $error['message'] ?? 'Unknown error';

            Log::warning('Shopee Affiliate API error', ['code' => $code, 'message' => $message]);

            throw new AffiliateApiException(
                "Shopee Affiliate API error {$code}: {$message}",
                retryable: in_array($code, self::RETRYABLE_CODES, true),
                apiCode: $code,
            );
        }

        if ($response->failed() || ! is_array($json['data'] ?? null)) {
            throw new AffiliateApiException("Unexpected response from the Shopee Affiliate API (HTTP {$response->status()}).");
        }

        return $json['data'];
    }
}
