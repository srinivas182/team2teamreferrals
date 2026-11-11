<?php

/*
 * A column note written after a foreign key constraint works on SQLite (tests) but not on MySQL (production).
 * It slipped in twice, so every migration is checked here.
 */
it('never puts a column note after a foreign key constraint', function () {
    $bad = [];
    foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
        if (preg_match('/constrained\([^)]*\)[^;]*->comment\(/', (string) file_get_contents($file))) {
            $bad[] = basename($file);
        }
    }
    expect($bad)->toBe([]);
});
