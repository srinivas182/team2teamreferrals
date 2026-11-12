<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Trust;

use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Trust score: the weights of the five parts, with a preview of the effect before saving. */
final class TrustController
{
    public function index(): Response
    {
        return Inertia::render('admin/Trust', [
            'parts' => collect(TrustScore::PARTS)->map(fn ($p, $k) => ['key' => $k, 'label' => $p['label'], 'default' => $p['weight']])->values(),
            'weights' => TrustScore::weights(),
            'preview' => session('trust_preview'),
            'status' => session('status'),
            'seo' => SeoData::private('Trust score')->toArray(),
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        return back()->with('trust_preview', ['weights' => $w = $this->weights($request), 'result' => TrustScore::preview($w)])->withInput();
    }

    public function save(Request $request, Settings $settings): RedirectResponse
    {
        $w = $this->weights($request);
        $before = TrustScore::weights();
        $settings->set('trust.weights', (string) json_encode($w));
        activity('admin')->causedBy($request->user())->event('trust-weights')->withProperties(['before' => $before, 'after' => $w])->log('Trust score weights changed (applies at the next nightly run)');

        return back()->with('status', 'trust-weights-saved');
    }

    /** @return array<string, int> */
    private function weights(Request $request): array
    {
        $rules = collect(TrustScore::PARTS)->mapWithKeys(fn ($p, $k) => ["weights.{$k}" => ['required', 'integer', 'between:0,100']])->all();
        $d = $request->validate(['weights' => ['required', 'array'], ...$rules]);
        $w = array_map('intval', array_intersect_key((array) $d['weights'], TrustScore::PARTS));
        if (array_sum($w) === 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['weights' => 'At least one part needs a weight above zero.']);
        }

        return $w;
    }
}
