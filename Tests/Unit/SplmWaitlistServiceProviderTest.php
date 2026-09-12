<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Modules\SplmWaitlist\Providers\SplmWaitlistServiceProvider;
use Modules\SplmWaitlist\Services\EntryRenderer;
use Modules\SplmWaitlist\Services\Support\OptionStoreInterface;
use Modules\SplmWaitlist\Services\WaitlistClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Tests\Support\FakeApp;
use Tests\Support\FakeCache;
use Tests\Support\FakeCustomer;
use Tests\Support\FakeOptionStore;

/**
 * Tests SplmWaitlistServiceProvider's two private methods directly via
 * reflection, on a constructed-but-never-booted provider instance.
 * register()/boot()/registerSettingsSection()/registerSidebarPanel() are
 * deliberately NOT exercised here -- they wire up \Eventy, \View, \Config
 * and the real routing/view stack, which genuinely needs a booted
 * FreeScout app (see the coverage report for the reasoning).
 * ServiceProvider's own constructor just assigns `$this->app = $app`
 * (vendor/illuminate/support/ServiceProvider.php), so a bare FakeApp is
 * enough to build one safely.
 */
class SplmWaitlistServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeCache::reset();
    }

    private function invokePrivate(SplmWaitlistServiceProvider $provider, string $method, array $args = [])
    {
        $ref = new ReflectionMethod($provider, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($provider, $args);
    }

    // -- customerEmail() ----------------------------------------------

    public function test_customer_email_prefers_the_customers_get_main_email(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $customer = new FakeCustomer('customer@example.com');
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $email = $this->invokePrivate($provider, 'customerEmail', [$customer, $conversation]);

        $this->assertSame('customer@example.com', $email);
    }

    public function test_customer_email_falls_back_to_the_conversation_when_customer_has_no_method(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $customer = new \stdClass(); // no getMainEmail() method
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $email = $this->invokePrivate($provider, 'customerEmail', [$customer, $conversation]);

        $this->assertSame('conversation@example.com', $email);
    }

    public function test_customer_email_falls_back_to_the_conversation_when_customer_is_null(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $email = $this->invokePrivate($provider, 'customerEmail', [null, $conversation]);

        $this->assertSame('conversation@example.com', $email);
    }

    public function test_customer_email_falls_back_to_the_conversation_when_the_customers_email_is_blank(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $customer = new FakeCustomer('');
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $email = $this->invokePrivate($provider, 'customerEmail', [$customer, $conversation]);

        $this->assertSame('conversation@example.com', $email);
    }

    public function test_customer_email_returns_empty_string_when_neither_source_has_one(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());

        $email = $this->invokePrivate($provider, 'customerEmail', [null, new \stdClass()]);

        $this->assertSame('', $email);
    }

    public function test_customer_email_returns_empty_string_when_the_conversation_is_not_an_object(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());

        $email = $this->invokePrivate($provider, 'customerEmail', [null, 'not-an-object']);

        $this->assertSame('', $email);
    }

    public function test_customer_email_is_trimmed(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $customer = new FakeCustomer('  padded@example.com  ');

        $email = $this->invokePrivate($provider, 'customerEmail', [$customer, new \stdClass()]);

        $this->assertSame('padded@example.com', $email);
    }

    // -- cachedEntries() --------------------------------------------------

    private function waitlistClientWithResponses(array $responses): WaitlistClient
    {
        $mock = new MockHandler($responses);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);

        return new WaitlistClient($guzzle, new NullLogger());
    }

    private function waitlistClientWithNoEntries(): WaitlistClient
    {
        $body = json_encode(['entries' => []]);

        return $this->waitlistClientWithResponses([new Response(200, [], $body)]);
    }

    public function test_cached_lines_resolves_through_the_container_and_renders_real_entries(): void
    {
        $store = new FakeOptionStore();
        $store->set('splmwaitlist.wp_base_url', 'https://example.com');
        $store->set('splmwaitlist.shared_secret', str_repeat('s', 40));

        $client = $this->waitlistClientWithResponses([
            new Response(200, [], json_encode([
                'entries' => [
                    ['season' => 'S2026', 'status' => 'queued', 'offered_at' => null, 'expires_at' => null],
                ],
            ])),
        ]);

        $app = new FakeApp();
        $app->bind(OptionStoreInterface::class, $store);
        $app->bind(WaitlistClient::class, $client);
        $app->bind(EntryRenderer::class, new EntryRenderer());

        $provider = new SplmWaitlistServiceProvider($app);

        $entries = $this->invokePrivate($provider, 'cachedEntries', ['a@example.com']);

        $this->assertSame([
            ['season' => 'S2026', 'statusLabel' => 'On waitlist', 'statusClass' => 'text-muted', 'detail' => null],
        ], $entries);
    }

    public function test_cached_lines_caches_with_a_ttl_of_one_minute(): void
    {
        // Regression test: Laravel 5.5's Cache::remember() TTL argument is
        // in MINUTES, and a prior review found this had been set 60x too
        // long (i.e. passed as if it were seconds).
        $store = new FakeOptionStore();
        $app = new FakeApp();
        $app->bind(OptionStoreInterface::class, $store);
        $app->bind(WaitlistClient::class, $this->waitlistClientWithNoEntries());
        $app->bind(EntryRenderer::class, new EntryRenderer());

        $provider = new SplmWaitlistServiceProvider($app);
        $this->invokePrivate($provider, 'cachedEntries', ['a@example.com']);

        $this->assertCount(1, FakeCache::$calls);
        $this->assertSame(1, FakeCache::$calls[0][1]);
    }

    public function test_cached_lines_keys_the_cache_by_a_case_insensitive_hash_of_the_email(): void
    {
        $store = new FakeOptionStore();
        $app = new FakeApp();
        $app->bind(OptionStoreInterface::class, $store);
        $app->bind(WaitlistClient::class, $this->waitlistClientWithNoEntries());
        $app->bind(EntryRenderer::class, new EntryRenderer());

        $provider = new SplmWaitlistServiceProvider($app);
        $this->invokePrivate($provider, 'cachedEntries', ['Mixed.Case@Example.com']);

        $this->assertSame('splmwaitlist.status.' . md5('mixed.case@example.com'), FakeCache::$calls[0][0]);
    }

    public function test_cached_lines_only_looks_up_once_per_email_while_the_cache_entry_is_warm(): void
    {
        $store = new FakeOptionStore();
        $store->set('splmwaitlist.wp_base_url', 'https://example.com');
        $store->set('splmwaitlist.shared_secret', str_repeat('t', 40));

        // Only one response is queued: if cachedEntries() invoked the real
        // lookup a second time for the same email, WaitlistClient would
        // hit an empty MockHandler queue and (per its own fail-open
        // try/catch) silently return [] instead of the cached line -- so
        // this doubles as proof the SECOND call never reaches the client.
        $client = $this->waitlistClientWithResponses([
            new Response(200, [], json_encode([
                'entries' => [
                    ['season' => 'S2026', 'status' => 'claimed', 'offered_at' => null, 'expires_at' => null],
                ],
            ])),
        ]);

        $app = new FakeApp();
        $app->bind(OptionStoreInterface::class, $store);
        $app->bind(WaitlistClient::class, $client);
        $app->bind(EntryRenderer::class, new EntryRenderer());

        $provider = new SplmWaitlistServiceProvider($app);

        $first = $this->invokePrivate($provider, 'cachedEntries', ['a@example.com']);
        $second = $this->invokePrivate($provider, 'cachedEntries', ['a@example.com']);

        $this->assertSame([
            ['season' => 'S2026', 'statusLabel' => 'Accepted', 'statusClass' => 'text-success', 'detail' => null],
        ], $first);
        $this->assertSame($first, $second);
        $this->assertCount(2, FakeCache::$calls); // remember() was called twice...
        $this->assertSame(FakeCache::$calls[0][0], FakeCache::$calls[1][0]); // ...for the same key.
    }
}
