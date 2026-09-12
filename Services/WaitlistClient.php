<?php

namespace Modules\SplmWaitlist\Services;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Talks to POST /wp-json/splm/v1/waitlist/customer-status on the
 * SportsPress site. Every failure mode -- network error, non-200,
 * malformed JSON, a missing 'entries' key -- returns an empty array
 * rather than throwing, because a broken lookup must never break the
 * agent's conversation page. That's the same contract WooCommerce's own
 * module has for a customer with no orders: nothing rendered, nothing
 * crashed.
 */
class WaitlistClient
{
    /** @var ClientInterface */
    private $http;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(ClientInterface $http, LoggerInterface $logger)
    {
        $this->http = $http;
        $this->logger = $logger;
    }

    /**
     * @param string $baseUrl WP site base URL, e.g. https://example.com
     * @param string $secret  Shared HMAC secret
     * @param string $email   Customer email to look up
     *
     * @return array<int, array{season: string, status: string, offered_at: ?string, expires_at: ?string}>
     */
    public function lookup(string $baseUrl, string $secret, string $email): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '' || $secret === '' || $email === '') {
            return [];
        }

        $body = json_encode(['email' => $email]);
        $timestamp = (string) time();
        $signature = self::signature($timestamp, $body, $secret);

        try {
            $response = $this->http->request('POST', $baseUrl . '/wp-json/splm/v1/waitlist/customer-status', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-SPLM-Timestamp' => $timestamp,
                    'X-SPLM-Signature' => $signature,
                ],
                'body' => $body,
                'timeout' => 5,
                'connect_timeout' => 3,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[SplmWaitlist] lookup request failed: ' . $e->getMessage());
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->warning('[SplmWaitlist] lookup returned HTTP ' . $response->getStatusCode());
            return [];
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            $this->logger->warning('[SplmWaitlist] lookup returned an unexpected shape');
            return [];
        }

        return $data['entries'];
    }

    /**
     * Identical to SPLM_Waitlist_REST::customer_status_signature() on the
     * WP side: sha256 over "<timestamp>.<raw-body>".
     *
     * @return string Lower-case hex digest.
     */
    public static function signature(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }
}
