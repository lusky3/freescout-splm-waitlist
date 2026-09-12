<?php

Route::group([
    // Same convention FreeScout core and ModuleManager both use for their
    // own admin-only settings routes.
    'middleware' => ['web', 'auth', 'roles'],
    'roles' => ['admin'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\SplmWaitlist\\Http\\Controllers',
], function () {
    Route::post('/app-settings/splmwaitlist', [
        'uses' => 'SplmWaitlistSettingsController@save',
    ])->name('splmwaitlist_save_settings');
});
