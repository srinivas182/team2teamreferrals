<?php

/*
 * Every provider channel the server accepts must have a tab on Admin → Settings: the save route is shared by all
 * channels, so the reachability guard can't tell when one has no page (the Video tab was nearly missed).
 */
it('has a settings tab for every provider channel', function () {
    $controller = (string) file_get_contents(app_path('Domains/Platform/Http/Controllers/SystemSettingsController.php'));
    preg_match('/in_array\\(\\$channel, \\[([^\\]]+)\\]/', $controller, $m);
    $channels = array_map(fn ($c) => trim($c, " '"), explode(',', $m[1] ?? ''));
    $page = (string) file_get_contents(resource_path('js/pages/admin/system/Index.tsx'));
    expect($channels)->not->toBeEmpty()
        ->and(array_values(array_filter($channels, fn ($c) => ! str_contains($page, 'url="/admin/system/'.$c.'"'))))->toBe([]);
});
