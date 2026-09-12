<?php

namespace Modules\SplmWaitlist\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\SplmWaitlist\Services\Support\OptionStoreInterface;

class SplmWaitlistSettingsController extends Controller
{
    /** @var OptionStoreInterface */
    private $options;

    public function __construct(OptionStoreInterface $options)
    {
        $this->options = $options;
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'wp_base_url' => 'required|url',
            'shared_secret' => 'nullable|string|min:32',
        ]);

        $this->options->set('splmwaitlist.wp_base_url', rtrim($validated['wp_base_url'], '/'));

        // Same masked-placeholder convention as the WP side's admin field:
        // an empty or all-bullet submission means "leave the secret alone."
        $submittedSecret = (string) ($validated['shared_secret'] ?? '');
        if ($submittedSecret !== '' && !preg_match('/^•+$/u', $submittedSecret)) {
            $this->options->set('splmwaitlist.shared_secret', $submittedSecret);
        }

        return redirect()->back()->with('success', __('Settings saved.'));
    }
}
