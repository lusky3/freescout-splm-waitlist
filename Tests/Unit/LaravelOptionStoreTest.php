<?php

namespace Tests\Unit;

use Modules\SplmWaitlist\Services\Support\LaravelOptionStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeOption;

class LaravelOptionStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeOption::reset();
    }

    public function test_get_delegates_to_the_option_facade_and_returns_its_value(): void
    {
        FakeOption::$values['splmwaitlist.wp_base_url'] = 'https://example.com';

        $store = new LaravelOptionStore();

        $this->assertSame('https://example.com', $store->get('splmwaitlist.wp_base_url'));
    }

    public function test_get_returns_the_given_default_when_the_option_facade_has_nothing_stored(): void
    {
        $store = new LaravelOptionStore();

        $this->assertSame('fallback', $store->get('splmwaitlist.missing', 'fallback'));
        $this->assertNull($store->get('splmwaitlist.missing'));
    }

    public function test_set_delegates_to_the_option_facade_and_the_value_is_then_readable_via_get(): void
    {
        $store = new LaravelOptionStore();

        $store->set('splmwaitlist.shared_secret', 'topsecret12345678901234567890123');

        // Prove real delegation (round-trip through the fake facade),
        // not just that set() was called with the right arguments.
        $this->assertSame('topsecret12345678901234567890123', FakeOption::$values['splmwaitlist.shared_secret']);
        $this->assertSame('topsecret12345678901234567890123', $store->get('splmwaitlist.shared_secret'));
    }
}
