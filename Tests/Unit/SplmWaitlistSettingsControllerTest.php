<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use Modules\SplmWaitlist\Http\Controllers\SplmWaitlistSettingsController;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeOptionStore;

/**
 * SplmWaitlistSettingsController::save() calls Illuminate\Http\Request::
 * validate(), which in a fully-booted FreeScout/Laravel app is a macro
 * registered by illuminate/foundation's FoundationServiceProvider (see
 * https://github.com/laravel/framework/blob/v5.5.44/src/Illuminate/Foundation/Providers/FoundationServiceProvider.php)
 * and backed by an Illuminate\Validation\Factory bound into the app
 * container as 'validator'. Rather than pull in illuminate/foundation (a
 * much larger commitment -- it bootstraps routing, sessions, config, the
 * whole application -- see the coverage report for why that line was not
 * crossed), this test registers the exact same macro body against a
 * minimal, real Illuminate\Container\Container with only 'validator'
 * bound, giving Request::validate() genuine Laravel validation behavior
 * (real rule parsing, real ValidationException on failure) without a
 * booted app.
 */
class SplmWaitlistSettingsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->singleton('validator', function () {
            return new ValidationFactory(new Translator(new ArrayLoader(), 'en'));
        });
        Container::setInstance($container);

        if (!Request::hasMacro('validate')) {
            // Verbatim port of FoundationServiceProvider::registerRequestValidate(),
            // with the validator() global helper (illuminate/foundation-only)
            // replaced by a direct container resolution of the same binding.
            Request::macro('validate', function (array $rules, ...$params) {
                Container::getInstance()->make('validator')->validate($this->all(), $rules, ...$params);

                return $this->only(collect($rules)->keys()->map(function ($rule) {
                    return str_contains($rule, '.') ? explode('.', $rule)[0] : $rule;
                })->unique()->toArray());
            });
        }
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function request(array $params): Request
    {
        return Request::create('/settings/splmwaitlist', 'POST', $params);
    }

    private function storeWithSecret(string $secret): FakeOptionStore
    {
        $store = new FakeOptionStore();
        $store->set('splmwaitlist.shared_secret', $secret);
        $store->set('splmwaitlist.wp_base_url', 'https://old.example.com');

        return $store;
    }

    public function test_masked_bullet_secret_does_not_overwrite_the_stored_secret_and_passes_validation(): void
    {
        $original = str_repeat('a', 40);
        $store = $this->storeWithSecret($original);
        $controller = new SplmWaitlistSettingsController($store);

        $controller->save($this->request([
            'wp_base_url' => 'https://example.com',
            'shared_secret' => str_repeat("\u{2022}", 16),
        ]));

        $this->assertSame($original, $store->get('splmwaitlist.shared_secret'));
        $this->assertSame('https://example.com', $store->get('splmwaitlist.wp_base_url'));
    }

    public function test_a_genuinely_blank_secret_does_not_overwrite_the_stored_secret(): void
    {
        $original = str_repeat('b', 40);
        $store = $this->storeWithSecret($original);
        $controller = new SplmWaitlistSettingsController($store);

        $controller->save($this->request([
            'wp_base_url' => 'https://example.com',
            'shared_secret' => '',
        ]));

        $this->assertSame($original, $store->get('splmwaitlist.shared_secret'));
    }

    public function test_a_missing_secret_field_does_not_overwrite_the_stored_secret(): void
    {
        $original = str_repeat('c', 40);
        $store = $this->storeWithSecret($original);
        $controller = new SplmWaitlistSettingsController($store);

        $controller->save($this->request([
            'wp_base_url' => 'https://example.com',
        ]));

        $this->assertSame($original, $store->get('splmwaitlist.shared_secret'));
    }

    public function test_a_real_32_plus_character_secret_is_saved(): void
    {
        $store = $this->storeWithSecret(str_repeat('x', 40));
        $controller = new SplmWaitlistSettingsController($store);
        $newSecret = str_repeat('z', 32);

        $controller->save($this->request([
            'wp_base_url' => 'https://example.com',
            'shared_secret' => $newSecret,
        ]));

        $this->assertSame($newSecret, $store->get('splmwaitlist.shared_secret'));
    }

    public function test_a_too_short_secret_fails_validation_and_writes_nothing(): void
    {
        $original = str_repeat('d', 40);
        $store = $this->storeWithSecret($original);
        $controller = new SplmWaitlistSettingsController($store);

        try {
            $controller->save($this->request([
                'wp_base_url' => 'https://example.com',
                'shared_secret' => 'too-short',
            ]));
            $this->fail('Expected a ValidationException to be thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shared_secret', $e->validator->errors()->toArray());
        }

        // Neither option should have been touched -- validation must fail
        // before either ->set() call in save().
        $this->assertSame($original, $store->get('splmwaitlist.shared_secret'));
        $this->assertSame('https://old.example.com', $store->get('splmwaitlist.wp_base_url'));
    }

    public function test_wp_base_url_is_trimmed_of_a_trailing_slash_before_being_stored(): void
    {
        $store = $this->storeWithSecret(str_repeat('e', 40));
        $controller = new SplmWaitlistSettingsController($store);

        $controller->save($this->request([
            'wp_base_url' => 'https://example.com/',
        ]));

        $this->assertSame('https://example.com', $store->get('splmwaitlist.wp_base_url'));
    }

    public function test_a_missing_wp_base_url_fails_validation_and_writes_nothing(): void
    {
        $store = $this->storeWithSecret(str_repeat('f', 40));
        $controller = new SplmWaitlistSettingsController($store);

        try {
            $controller->save($this->request([]));
            $this->fail('Expected a ValidationException to be thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('wp_base_url', $e->validator->errors()->toArray());
        }

        $this->assertSame('https://old.example.com', $store->get('splmwaitlist.wp_base_url'));
    }

    public function test_an_invalid_wp_base_url_fails_validation_and_writes_nothing(): void
    {
        $store = $this->storeWithSecret(str_repeat('g', 40));
        $controller = new SplmWaitlistSettingsController($store);

        try {
            $controller->save($this->request(['wp_base_url' => 'not-a-url']));
            $this->fail('Expected a ValidationException to be thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('wp_base_url', $e->validator->errors()->toArray());
        }

        $this->assertSame('https://old.example.com', $store->get('splmwaitlist.wp_base_url'));
    }
}
