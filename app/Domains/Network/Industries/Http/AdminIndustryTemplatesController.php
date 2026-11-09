<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Http;

use App\Domains\Network\Groups\Models\Group;
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
        return $this->render($industry, $kind, null);
    }

    public function save(Request $request, Industry $industry, string $kind): RedirectResponse
    {
        return $this->store($request, $industry, $kind, null);
    }

    public function reset(Request $request, Industry $industry, string $kind): RedirectResponse
    {
        return $this->resetScope($request, $industry, $kind, null);
    }

    /** A group's own form or pipeline, overriding its industry's. */
    public function groupShow(Group $group, string $kind): Response
    {
        return $this->render($group->industry, $kind, $group);
    }

    public function groupSave(Request $request, Group $group, string $kind): RedirectResponse
    {
        return $this->store($request, $group->industry, $kind, $group);
    }

    public function groupReset(Request $request, Group $group, string $kind): RedirectResponse
    {
        return $this->resetScope($request, $group->industry, $kind, $group);
    }

    /** @return array{basePath: string, backHref: string, backLabel: string, inheritLabel: string, resetLabel: string, ownSource: string} */
    public static function scopeProps(Industry $industry, string $kind, ?Group $group): array
    {
        return $group === null
            ? ['basePath' => "/admin/industries/{$industry->slug}/{$kind}", 'backHref' => "/admin/industries/{$industry->slug}", 'backLabel' => 'Back to industry', 'inheritLabel' => 'Using the family default', 'resetLabel' => 'Use family default', 'ownSource' => 'industry']
            : ['basePath' => "/admin/groups/{$group->slug}/{$kind}", 'backHref' => "/admin/groups/{$group->slug}", 'backLabel' => 'Back to group', 'inheritLabel' => 'Using the industry’s version', 'resetLabel' => 'Use the industry’s version', 'ownSource' => 'group'];
    }

    private function render(Industry $industry, string $kind, ?Group $group): Response
    {
        $resolved = $this->templates->resolve($kind, $industry, $group?->id);

        return Inertia::render($kind === 'form' ? 'admin/industries/FormBuilder' : 'admin/industries/Stages', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'family' => Industry::FAMILIES[$industry->family] ?? $industry->family, 'terminology' => $industry->terminology],
            'body' => $resolved['body'],
            'source' => $resolved['source'],
            'version' => $resolved['version'],
            'history' => $this->templates->history($kind, $industry, $group?->id),
            'scope' => self::scopeProps($industry, $kind, $group),
            'fieldTypes' => Schemas::FIELD_TYPES,
            'roles' => Schemas::ROLES,
            'status' => session('status'),
            'seo' => SeoData::private(($kind === 'form' ? 'Referral form' : 'Pipeline').' · '.($group->name ?? $industry->name))->toArray(),
        ]);
    }

    private function store(Request $request, Industry $industry, string $kind, ?Group $group): RedirectResponse
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
        $version = $this->templates->save($kind, $industry, $body, $request->user()?->getKey(), $group?->id);
        activity('admin')->causedBy($request->user())->performedOn($group ?? $industry)->event($group ? 'group-template-saved' : 'industry-template-saved')
            ->withProperties(['kind' => $kind, 'version' => $version, 'warnings' => $check['warnings']])->log("Saved {$kind} v{$version} for ".($group->name ?? $industry->name));

        return back()->with('status', 'template-saved');
    }

    private function resetScope(Request $request, Industry $industry, string $kind, ?Group $group): RedirectResponse
    {
        $this->templates->reset($kind, $industry, $group?->id);
        activity('admin')->causedBy($request->user())->performedOn($group ?? $industry)->event($group ? 'group-template-reset' : 'industry-template-reset')->withProperties(['kind' => $kind])->log("Reset {$kind} for ".($group->name ?? $industry->name));

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
