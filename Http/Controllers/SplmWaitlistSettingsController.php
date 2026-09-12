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
        // The all-bullets masked placeholder means "leave the secret
        // alone." It must be normalized to empty BEFORE validate() runs:
        // it's a 16-character string, not an empty one, so Laravel's
        // min:32 rule would otherwise reject it outright and this
        // bypass would never be reached.
        $rawSecret = (string) $request->input('shared_secret', '');
        if ($rawSecret !== '' && preg_match('/^•+$/u', $rawSecret)) {
            $request->merge(['shared_secret' => '']);
        }

        $validated = $request->validate([
            'wp_base_url' => 'required|url',
            'shared_secret' => 'nullable|string|min:32',
        ]);

        $this->options->set('splmwaitlist.wp_base_url', rtrim($validated['wp_base_url'], '/'));

        // An empty submission (either genuinely blank, or normalized
        // above from the masked placeholder) means "leave the secret
        // alone."
        $submittedSecret = (string) ($validated['shared_secret'] ?? '');
        if ($submittedSecret !== '') {
            $this->options->set('splmwaitlist.shared_secret', $submittedSecret);
        }

        return redirect()->back()->with('success', __('Settings saved.'));
    }
}
