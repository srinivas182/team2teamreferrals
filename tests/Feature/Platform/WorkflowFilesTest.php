<?php

use Symfony\Component\Yaml\Yaml;

/*
 * A workflow file GitHub can't read fails every check with no jobs at all (a colon in a step name did this once).
 * Parse them here so it shows up locally first.
 */
it('has CI workflow files that parse', function () {
    $files = glob(base_path('.github/workflows/*.yml')) ?: [];
    expect($files)->not->toBeEmpty();
    foreach ($files as $f) {
        $doc = Yaml::parseFile($f);
        expect($doc)->toBeArray()->toHaveKey('jobs');
    }
});
