<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\SplmWaitlist\Services\WaitlistClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WaitlistClientTest extends TestCase
{
    private function clientWithResponses(array $responses): WaitlistClient
    {
        $mock = new MockHandler($responses);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        return new WaitlistClient($guzzle, new NullLogger());
    }

    public function test_signature_matches_the_wp_sides_formula(): void
    {
        $sig = WaitlistClient::signature('1700000000', '{"email":"a@example.com"}', 'topsecret');
        $expected = hash_hmac('sha256', '1700000000.{"email":"a@example.com"}', 'topsecret');
        $this->assertSame($expected, $sig);
    }

    public function test_returns_entries_on_a_200(): void
    {
        $entries = [['season' => 'S2026', 'status' => 'queued', 'offered_at' => null, 'expires_at' => null]];
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['email' => 'a@example.com', 'entries' => $entries])),
        ]);

        $this->assertSame($entries, $client->lookup('https://example.com', 'secret', 'a@example.com'));
    }

    public function test_returns_empty_array_on_a_non_200(): void
    {
        $client = $this->clientWithResponses([new Response(403, [], '{}')]);
        $this->assertSame([], $client->lookup('https://example.com', 'secret', 'a@example.com'));
    }

    public function test_returns_empty_array_on_a_network_failure(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'https://example.com')),
        ]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new WaitlistClient($guzzle, new NullLogger());

        $this->assertSame([], $client->lookup('https://example.com', 'secret', 'a@example.com'));
    }

    public function test_returns_empty_array_on_malformed_json(): void
    {
        $client = $this->clientWithResponses([new Response(200, [], 'not json')]);
        $this->assertSame([], $client->lookup('https://example.com', 'secret', 'a@example.com'));
    }

    public function test_returns_empty_array_when_entries_key_is_missing(): void
    {
        $client = $this->clientWithResponses([new Response(200, [], json_encode(['email' => 'a@example.com']))]);
        $this->assertSame([], $client->lookup('https://example.com', 'secret', 'a@example.com'));
    }

    public function test_returns_empty_array_for_a_blank_base_url_or_secret(): void
    {
        $client = $this->clientWithResponses([]);
        $this->assertSame([], $client->lookup('', 'secret', 'a@example.com'));
        $this->assertSame([], $client->lookup('https://example.com', '', 'a@example.com'));
    }

    public function test_sends_the_headers_and_url_the_wp_side_expects(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['entries' => []]))]);
        $container = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($container));
        $guzzle = new Client(['handler' => $stack]);
        $client = new WaitlistClient($guzzle, new NullLogger());

        // Trailing slash on the base URL must not produce a double slash.
        $client->lookup('https://example.com/', 'secret', 'a@example.com');

        $sent = $container[0]['request'];
        $this->assertSame('https://example.com/wp-json/splm/v1/waitlist/customer-status', (string) $sent->getUri());
        $this->assertTrue($sent->hasHeader('X-SPLM-Timestamp'));
        $this->assertTrue($sent->hasHeader('X-SPLM-Signature'));
        $this->assertSame('{"email":"a@example.com"}', (string) $sent->getBody());
    }
}
