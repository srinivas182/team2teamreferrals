<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Http;

use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Industries\Templates\Schemas;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Industries → Referral form / Pipeline: edit with live preview, inherit from the family, version every save. */
final class AdminIndustryTemplatesController
{
    public function __construct(private readonly IndustryTemplates $templates) {}

    public function show(Industry $industry, string $kind): Response
    {
        $resolved = $this->templates->resolve($kind, $industry);

        return Inertia::render($kind === 'form' ? 'admin/industries/FormBuilder' : 'admin/industries/Stages', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'family' => Industry::FAMILIES[$industry->family] ?? $industry->family, 'terminology' => $industry->terminology],
            'body' => $resolved['body'],
            'source' => $resolved['source'],
            'version' => $resolved['version'],
            'history' => $this->templates->history($kind, $industry),
            'fieldTypes' => Schemas::FIELD_TYPES,
            'roles' => Schemas::ROLES,
            'status' => session('status'),
            'seo' => SeoData::private(($kind === 'form' ? 'Referral form' : 'Pipeline').' · '.$industry->name)->toArray(),
        ]);
    }

    public function save(Request $request, Industry $industry, string $kind): RedirectResponse
    {
        $request->validate(['body' => ['required', 'array', 'min:1', 'max:40'], 'acknowledge' => ['boolean']]);
        /** @var list<array<string, mixed>> $body */
        $body = array_values(array_map(fn ($item) => is_array($item) ? $this->clean($item, $kind) : [], (array) $request->input('body')));
        $check = $kind === 'form' ? Schemas::checkForm($body) : Schemas::checkStages($body);
        if ($check['errors'] !== []) {
            throw ValidationException::withMessages(['body' => $check['errors']]);
        }
        if ($check['warnings'] !== [] && ! $request->boolean('acknowledge')) {
            // Not an error: the screen shows the warnings and asks the admin to confirm.
            return back()->with('warnings', $check['warnings']);
        }
        $version = $this->templates->save($kind, $industry, $body, $request->user()?->getKey());
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('industry-template-saved')
            ->withProperties(['kind' => $kind, 'version' => $version, 'warnings' => $check['warnings']])->log("Saved {$kind} v{$version} for {$industry->name}");

        return back()->with('status', 'template-saved');
    }

    public function reset(Request $request, Industry $industry, string $kind): RedirectResponse
    {
        $this->templates->reset($kind, $industry);
        activity('admin')->causedBy($request->user())->performedOn($industry)->event('industry-template-reset')->withProperties(['kind' => $kind])->log("Reset {$kind} for {$industry->name} to the family default");

        return back()->with('status', 'template-reset');
    }

    /**
     * Keeps only known keys, so nothing unexpected is stored.
     *
     * @param  array<mixed>  $item
     * @return array<string, mixed>
     */
    private function clean(array $item, string $kind): array
    {
        if ($kind === 'stages') {
            return ['key' => (string) ($item['key'] ?? ''), 'label' => trim((string) ($item['label'] ?? '')), 'kind' => (string) ($item['kind'] ?? '')];
        }
        $out = [
            'key' => (string) ($item['key'] ?? ''), 'label' => trim((string) ($item['label'] ?? '')), 'type' => (string) ($item['type'] ?? ''),
            'required' => (bool) ($item['required'] ?? false), 'sensitive' => (bool) ($item['sensitive'] ?? false),
        ];
        if (isset($item['help']) && trim((string) $item['help']) !== '') {
            $out['help'] = mb_substr(trim((string) $item['help']), 0, 200);
        }
        if (in_array($out['type'], ['choice', 'multi_choice'], true)) {
            $out['options'] = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 80), (array) ($item['options'] ?? [])), fn ($o) => $o !== ''));
        }
        if (! empty($item['role'])) {
            $out['role'] = (string) $item['role'];
        }

        return $out;
    }
}
