<?php

namespace Modules\SplmWaitlist\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Modules\SplmWaitlist\Services\EntryRenderer;
use Modules\SplmWaitlist\Services\Support\LaravelOptionStore;
use Modules\SplmWaitlist\Services\Support\OptionStoreInterface;
use Modules\SplmWaitlist\Services\WaitlistClient;
use Psr\Log\LoggerInterface;

class SplmWaitlistServiceProvider extends ServiceProvider
{
    /** Upper bound on WP lookups per conversation view (each is cached for a minute). */
    private const MAX_EMAILS = 5;

    /** Total seconds a conversation view may spend starting WP lookups (one lookup can take up to ~8s). */
    private const LOOKUP_BUDGET_SECONDS = 10.0;

    // @codeCoverageIgnoreStart
    //
    // register()/boot()/registerSettingsSection()/registerSidebarPanel() wire
    // this module into FreeScout's real DI container, Eventy hook dispatcher,
    // Blade view resolver, and router. Faking those well enough to unit-test
    // meaningfully would mean reimplementing a meaningful slice of Eventy's
    // and Laravel's own dispatch semantics -- not worth building. This wiring
    // is instead integration-tested against a real, running FreeScout
    // instance by the Docker e2e environment documented in this repo's
    // README (see the lusky3/freescout-sportspress-e2e sibling project). The
    // methods with real decision logic this class owns -- customerEmails(),
    // entriesForEmails() and cachedEntries() -- are NOT excluded below; all
    // are fully unit-tested
    // in Tests/Unit/SplmWaitlistServiceProviderTest.php.
    public function register()
    {
        $this->app->bind(OptionStoreInterface::class, LaravelOptionStore::class);

        $this->app->bind(LoggerInterface::class, function ($app) {
            return $app['log'];
        });

        $this->app->bind(\GuzzleHttp\ClientInterface::class, function () {
            return new Client();
        });

        $this->app->singleton(EntryRenderer::class);
    }

    public function boot()
    {
        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/splmwaitlist';
        }, \Config::get('view.paths')), [__DIR__ . '/../Resources/views']), 'splmwaitlist');

        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');

        $this->registerSettingsSection();
        $this->registerSidebarPanel();

        // Feeds Resources/views/settings/index.blade.php (Step 4 below):
        // it reads $wpBaseUrl and $hasSecret rather than calling the
        // option store itself, keeping the view a pure template.
        \View::composer('splmwaitlist::settings.index', function ($view) {
            $store = $this->app->make(OptionStoreInterface::class);
            $view->with('wpBaseUrl', (string) $store->get('splmwaitlist.wp_base_url', ''));
            $view->with('hasSecret', (string) $store->get('splmwaitlist.shared_secret', '') !== '');
        });
    }

    /**
     * Same three-filter shape as ModuleManagerServiceProvider (settings.
     * sections / settings.section_settings / settings.view) — FreeScout's
     * SettingsController@view 404s if settings.section_settings returns an
     * empty array for the section, which is why the second filter exists
     * even though our own view never reads its return value.
     */
    protected function registerSettingsSection(): void
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['splmwaitlist'] = [
                'title' => __('SportsPress Waitlist'),
                'icon' => 'list',
                'view' => 'splmwaitlist::settings',
                'order' => 210,
            ];

            return $sections;
        });

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== 'splmwaitlist') {
                return $settings;
            }

            $store = $this->app->make(OptionStoreInterface::class);
            $settings['splmwaitlist.wp_base_url'] = $store->get('splmwaitlist.wp_base_url', '');

            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            if ($section !== 'splmwaitlist') {
                return $view;
            }

            return 'splmwaitlist::settings.index';
        }, 20, 2);
    }

    /**
     * conversation.after_prev_convs fires as ($customer, $conversation,
     * $mailbox) -- confirmed against the vendored FreeScout core's
     * resources/views/conversations/partials/customer_sidebar.blade.php.
     * The callback must echo its own output: @action(...) compiles to a
     * bare Eventy::action(...) call with no echo around it, unlike
     * @filter(...) (vendor/tormjens/eventy/src/EventBladeServiceProvider.php).
     *
     * Wrapped in try/catch, matching the fail-open convention used
     * elsewhere in this codebase's FreeScout modules (see
     * freescout-quiet-autoclosed's QuietAutoClosedServiceProvider): a
     * bug here must never break the conversation page, only silently
     * show nothing.
     */
    protected function registerSidebarPanel(): void
    {
        \Eventy::addAction('conversation.after_prev_convs', function ($customer, $conversation, $mailbox) {
            try {
                $emails = $this->customerEmails($customer, $conversation);
                if ($emails === []) {
                    return;
                }

                $entries = $this->entriesForEmails($emails);
                if (empty($entries)) {
                    return;
                }

                echo view('splmwaitlist::sidebar', ['entries' => $entries])->render();
            } catch (\Throwable $e) {
                \Log::error('[SplmWaitlist] sidebar render failed: ' . $e->getMessage());
            }
        }, 20, 3);
    }
    // @codeCoverageIgnoreEnd

    /**
     * Every address worth looking up for this conversation: the customer's
     * main email, any other emails on the customer record, and the address
     * this conversation arrived from. A player can register on the WP side
     * with a different address than the one FreeScout lists first, so
     * looking up only the main email would show nothing for them.
     *
     * @param mixed $customer
     * @param mixed $conversation
     * @return string[] Trimmed, lower-cased, de-duplicated, at most MAX_EMAILS.
     */
    private function customerEmails($customer, $conversation): array
    {
        $candidates = [];
        if (is_object($customer) && method_exists($customer, 'getMainEmail')) {
            $candidates[] = (string) $customer->getMainEmail();
        }
        if (is_object($customer) && method_exists($customer, 'getEmailsAsArray')) {
            foreach ((array) $customer->getEmailsAsArray() as $other) {
                $candidates[] = (string) $other;
            }
        }
        if (is_object($conversation) && isset($conversation->customer_email)) {
            $candidates[] = (string) $conversation->customer_email;
        }

        $emails = [];
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '') {
                $emails[$candidate] = $candidate;
            }
        }

        return array_slice(array_values($emails), 0, self::MAX_EMAILS);
    }

    /**
     * Lookups run one after another, each able to block for its full HTTP
     * timeout, so a slow WP site would otherwise stall the conversation page
     * for MAX_EMAILS x that. Once the budget is spent no further lookup is
     * started; addresses are ordered most-likely first, and rows already
     * collected are kept.
     *
     * @param string[] $emails
     * @param float    $budgetSeconds Total time allowed before starting another lookup.
     * @return array<int, array{
     *     season: string,
     *     position: ?string,
     *     statusLabel: string,
     *     statusClass: string,
     *     detail: ?string,
     * }>
     */
    private function entriesForEmails(array $emails, float $budgetSeconds = self::LOOKUP_BUDGET_SECONDS): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $rows = [];
        foreach ($emails as $email) {
            if (microtime(true) >= $deadline) {
                break;
            }
            foreach ($this->cachedEntries($email) as $row) {
                // Keyed by content so an identical row reached through two
                // addresses shows once.
                $rows[serialize($row)] = $row;
            }
        }

        return array_values($rows);
    }

    /**
     * 60-second (1-minute) cache in front of the WP call — Laravel 5.5's
     * Cache::remember() TTL argument is in minutes, not seconds; 1 is the
     * floor, keyed by email, so opening a conversation doesn't hit WP on
     * every page render.
     *
     * @return array<int, array{season: string, statusLabel: string, statusClass: string, detail: ?string}>
     */
    private function cachedEntries(string $email): array
    {
        $cacheKey = 'splmwaitlist.status.' . md5(strtolower($email));

        return \Cache::remember($cacheKey, 1, function () use ($email) {
            $store = $this->app->make(OptionStoreInterface::class);
            $baseUrl = (string) $store->get('splmwaitlist.wp_base_url', '');
            $secret = (string) $store->get('splmwaitlist.shared_secret', '');

            $entries = $this->app->make(WaitlistClient::class)->lookup($baseUrl, $secret, $email);

            return $this->app->make(EntryRenderer::class)->render($entries);
        });
    }
}
