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

    // -- customerEmails() ---------------------------------------------

    public function test_customer_emails_collects_main_other_and_conversation_addresses(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $customer = new FakeCustomer('main@example.com', ['main@example.com', 'second@example.com']);
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $emails = $this->invokePrivate($provider, 'customerEmails', [$customer, $conversation]);

        $this->assertSame(['main@example.com', 'second@example.com', 'conversation@example.com'], $emails);
    }

    public function test_customer_emails_are_trimmed_lower_cased_and_de_duplicated(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $customer = new FakeCustomer('  Main@Example.com ', ['MAIN@example.com']);
        $conversation = (object) ['customer_email' => 'main@example.com'];

        $emails = $this->invokePrivate($provider, 'customerEmails', [$customer, $conversation]);

        $this->assertSame(['main@example.com'], $emails);
    }

    public function test_customer_emails_uses_the_conversation_address_when_customer_has_no_methods(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $this->assertSame(
            ['conversation@example.com'],
            $this->invokePrivate($provider, 'customerEmails', [new \stdClass(), $conversation])
        );
        $this->assertSame(
            ['conversation@example.com'],
            $this->invokePrivate($provider, 'customerEmails', [null, $conversation])
        );
    }

    public function test_customer_emails_skips_a_blank_main_email(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $customer = new FakeCustomer('', ['']);
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $emails = $this->invokePrivate($provider, 'customerEmails', [$customer, $conversation]);

        $this->assertSame(['conversation@example.com'], $emails);
    }

    public function test_customer_emails_is_empty_when_no_source_has_one(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());

        $this->assertSame([], $this->invokePrivate($provider, 'customerEmails', [null, new \stdClass()]));
        $this->assertSame([], $this->invokePrivate($provider, 'customerEmails', [null, 'not-an-object']));
    }

    public function test_customer_emails_caps_the_number_of_lookups(): void
    {
        $provider = new SplmWaitlistServiceProvider(new FakeApp());
        $all = [];
        for ($i = 1; $i <= 9; $i++) {
            $all[] = "e{$i}@example.com";
        }
        $customer = new FakeCustomer($all[0], $all);

        $emails = $this->invokePrivate($provider, 'customerEmails', [$customer, new \stdClass()]);

        $this->assertSame(array_slice($all, 0, 5), $emails);
    }

    // -- entriesForEmails() -----------------------------------------------

    private function providerWithResponses(array $responses): SplmWaitlistServiceProvider
    {
        $store = new FakeOptionStore();
        $store->set('splmwaitlist.wp_base_url', 'https://example.com');
        $store->set('splmwaitlist.shared_secret', str_repeat('s', 40));

        $app = new FakeApp();
        $app->bind(OptionStoreInterface::class, $store);
        $app->bind(WaitlistClient::class, $this->waitlistClientWithResponses($responses));
        $app->bind(EntryRenderer::class, new EntryRenderer());

        return new SplmWaitlistServiceProvider($app);
    }

    private function entriesResponse(string $season): Response
    {
        return new Response(200, [], json_encode([
            'entries' => [['season' => $season, 'status' => 'queued', 'offered_at' => null, 'expires_at' => null]],
        ]));
    }

    public function test_entries_for_emails_finds_an_entry_registered_under_a_non_main_address(): void
    {
        // The regression this exists for: the main address has nothing on
        // the waitlist, but the customer's second address does.
        $provider = $this->providerWithResponses([
            new Response(200, [], json_encode(['entries' => []])),
            $this->entriesResponse('S2027'),
        ]);

        $rows = $this->invokePrivate($provider, 'entriesForEmails', [['main@example.com', 'second@example.com']]);

        $this->assertCount(1, $rows);
        $this->assertSame('S2027', $rows[0]['season']);
    }

    public function test_entries_for_emails_shows_an_identical_row_once(): void
    {
        $provider = $this->providerWithResponses([
            $this->entriesResponse('S2027'),
            $this->entriesResponse('S2027'),
        ]);

        $rows = $this->invokePrivate($provider, 'entriesForEmails', [['a@example.com', 'b@example.com']]);

        $this->assertCount(1, $rows);
    }

    public function test_entries_for_emails_keeps_distinct_rows_from_different_addresses(): void
    {
        $provider = $this->providerWithResponses([
            $this->entriesResponse('S2026'),
            $this->entriesResponse('S2027'),
        ]);

        $rows = $this->invokePrivate($provider, 'entriesForEmails', [['a@example.com', 'b@example.com']]);

        $this->assertSame(['S2026', 'S2027'], array_column($rows, 'season'));
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
            [
                'season' => 'S2026',
                'position' => null,
                'statusLabel' => 'On waitlist',
                'statusClass' => 'text-muted',
                'detail' => null,
            ],
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
            [
                'season' => 'S2026',
                'position' => null,
                'statusLabel' => 'Accepted',
                'statusClass' => 'text-success',
                'detail' => null,
            ],
        ], $first);
        $this->assertSame($first, $second);
        $this->assertCount(2, FakeCache::$calls); // remember() was called twice...
        $this->assertSame(FakeCache::$calls[0][0], FakeCache::$calls[1][0]); // ...for the same key.
    }
}
