<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries;

use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Models\IndustryLicensing;
use App\Domains\Network\Locations\Models\State;

/** Licensing rules as a spreadsheet, so counsel's state-by-state review can be done in Excel and loaded back. */
final class LicensingCsv
{
    public const COLUMNS = ['industry', 'state', 'required', 'issuing_body', 'number_pattern', 'lookup_url', 'notes'];

    public static function export(?Industry $only = null): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, self::COLUMNS, escape: '');
        $industries = $only ? collect([$only]) : Industry::query()->orderBy('name')->get();
        $states = State::query()->where('is_enabled', true)->orderBy('code')->get();
        foreach ($industries as $industry) {
            $rules = IndustryLicensing::query()->where('industry_id', $industry->id)->get()->keyBy('state_id');
            foreach ($states as $state) {
                $r = $rules->get($state->id);
                fputcsv($out, [$industry->slug, $state->code, $r === null ? '' : ($r->required ? 'yes' : 'no'), $r?->issuing_body, $r?->number_pattern, $r?->lookup_url, $r?->notes], escape: '');
            }
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** @return array{updated: int, skipped: list<string>} Blank "required" cells are left untouched. */
    public static function import(string $csv): array
    {
        $lines = preg_split('/\r?\n/', trim($csv)) ?: [];
        $head = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv((string) array_shift($lines), escape: ''));
        if (array_diff(['industry', 'state', 'required'], $head) !== []) {
            return ['updated' => 0, 'skipped' => ['The first row must include industry, state and required columns.']];
        }
        $industries = Industry::query()->pluck('id', 'slug');
        $states = State::query()->pluck('id', 'code');
        $updated = 0;
        $skipped = [];
        foreach ($lines as $n => $line) {
            $v = str_getcsv($line, escape: '');
            if (count($v) !== count($head)) {
                continue;
            }
            $row = array_combine($head, array_map(fn ($x) => trim((string) $x), $v));
            $required = strtolower($row['required']);
            if ($required === '') {
                continue;
            }
            $iid = $industries[$row['industry']] ?? null;
            $sid = $states[strtoupper($row['state'])] ?? null;
            $pattern = $row['number_pattern'] ?? '';
            if ($iid === null || $sid === null || ! in_array($required, ['yes', 'no'], true) || ($pattern !== '' && @preg_match('/^(?:'.$pattern.')$/', '') === false)) {
                $skipped[] = 'Row '.($n + 2).': check the industry, state, required (yes/no) and pattern.';

                continue;
            }
            IndustryLicensing::query()->updateOrCreate(['industry_id' => $iid, 'state_id' => $sid], [
                'required' => $required === 'yes',
                'issuing_body' => ($row['issuing_body'] ?? '') ?: null,
                'number_pattern' => $pattern ?: null,
                'lookup_url' => ($row['lookup_url'] ?? '') ?: null,
                'notes' => ($row['notes'] ?? '') ?: null,
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }
}
