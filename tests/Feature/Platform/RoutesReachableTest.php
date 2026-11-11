<?php

use Illuminate\Support\Facades\Route;

/*
 * Every member and admin route must be reachable from a page (or from a link the server builds, such as in an
 * email). A route tested only by direct requests can pass every test and still be impossible for people to use:
 * this happened with the annual billing switch, the event edit form and the signed-agreement download.
 */
it('has a page or server-built link for every member and admin route', function () {
    // Route definition files are excluded: a route must not count as "linked" because it is defined.
    $read = fn (string $pattern) => collect(Symfony\Component\Finder\Finder::create()->files()->in(base_path($pattern))->name(['*.ts', '*.tsx', '*.php'])->notName('routes.php'))
        ->map(fn ($f) => (string) file_get_contents($f->getRealPath()))->implode("\n");
    $pages = $read('resources/js');
    $server = $read('app')."\n".$read('resources/views');
    $unreachable = [];
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'app/') && ! str_starts_with($uri, 'admin/')) {
            continue;
        }
        // Pages build links in pieces (a base path, then "/approve"), so check the part after the last parameter as it is
        // actually written after a variable: `${id}/approve`, .'/approve' or "/approve" right after a parameter.
        // A named route used by the server (signed download links, redirects) is reachable through that link.
        if ($route->getName() !== null && preg_match("/['\"]".preg_quote($route->getName(), '/')."['\"]/", $server) === 1) {
            continue;
        }
        if (! str_contains($uri, '{')) {
            if (str_contains($pages, '/'.$uri) || str_contains($server, '/'.$uri)) {
                continue;
            }
        } else {
            $after = (string) preg_replace('/^.*\}/', '', $uri);                  // e.g. "/update", ".ics" or "" (ends with a parameter)
            // Pages write either the full tail after a variable (`${id}/thanks/public`) or only the last segment after a base
            // path (`${basePath}/approve`): both count.
            $tails = $after !== '' && substr_count($after, '/') > 1 ? [$after, '/'.basename($after)] : [$after];
            // Routes ending in parameters are reachable when their fixed prefix is linked: "/app/feed/report/" + "post/${id}".
            if ($after === '' && str_contains($pages, '/'.explode('{', $uri)[0])) {
                continue;
            }
            $before = (string) preg_replace('/\{[^}]+\}$/', '', $uri);           // for routes ending with a parameter: "app/referrals/{referral}/files/"
            foreach ($tails as $tail) {
                $lastFixed = $tail !== '' ? $tail : '/'.basename(rtrim((string) preg_replace('/\{[^}]+\}/', '', $before), '/')).'/';
                $q = preg_quote($lastFixed, '/');
                $pattern = $tail !== ''
                    ? '/(\}|\$\w+(->\w+)*\)?\s*\.\s*\'|\]\s*\.\s*\')'.$q.'(?![\w-])/'             // `${x}/update`, $x.'/update', $a['b'].'/update'
                    : '/'.$q.'(\$\{|\'\s*\.|\{)/';                                                         // ".../files/${id}" or ".../files/'.$id"
                if (preg_match($pattern, $pages) === 1 || preg_match($pattern, $server) === 1) {
                    continue 2;
                }
            }
        }
        $unreachable[] = implode('|', $route->methods()).' '.$uri;
    }
    expect($unreachable)->toBe([]);
});
