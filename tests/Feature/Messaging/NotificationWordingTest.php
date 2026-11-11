<?php

use App\Domains\Messaging\NotificationCatalogue;

/*
 * A notification sent with a headline must use a type whose wording shows that headline. Twice a fixed template
 * ("Update on {client}", "News from your network") silently replaced the real message; this keeps it from recurring.
 */
it('shows the headline for every notification sent with one', function () {
    $types = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all("/->send\\([^;]*?'([a-z_]+\\.[a-z_]+)',\\s*\\[[^\\]]*'headline'\\s*=>/s", (string) file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $type) {
            $types[$type][] = basename($file->getPathname());
        }
    }
    expect($types)->not->toBeEmpty();
    $catalogue = NotificationCatalogue::TYPES;
    foreach ($types as $type => $files) {
        expect($catalogue)->toHaveKey($type);
        expect(str_contains((string) $catalogue[$type]['title'], '{headline}'))->toBeTrue("'{$type}' (used in ".implode(', ', array_unique($files)).') would hide the headline');
    }
});
