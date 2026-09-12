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
                $email = $this->customerEmail($customer, $conversation);
                if ($email === '') {
                    return;
                }

                $lines = $this->cachedLines($email);
                if (empty($lines)) {
                    return;
                }

                echo view('splmwaitlist::sidebar', ['lines' => $lines])->render();
            } catch (\Throwable $e) {
                \Log::error('[SplmWaitlist] sidebar render failed: ' . $e->getMessage());
            }
        }, 20, 3);
    }

    /**
     * @param mixed $customer
     * @param mixed $conversation
     */
    private function customerEmail($customer, $conversation): string
    {
        $email = '';
        if (is_object($customer) && method_exists($customer, 'getMainEmail')) {
            $email = (string) $customer->getMainEmail();
        }
        if ($email === '' && is_object($conversation) && isset($conversation->customer_email)) {
            $email = (string) $conversation->customer_email;
        }
        return trim($email);
    }

    /**
     * 60-second (1-minute) cache in front of the WP call — Laravel 5.5's
     * Cache::remember() TTL argument is in minutes, not seconds; 1 is the
     * floor, keyed by email, so opening a conversation doesn't hit WP on
     * every page render.
     *
     * @return string[]
     */
    private function cachedLines(string $email): array
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
