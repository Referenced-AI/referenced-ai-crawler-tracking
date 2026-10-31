<?php

use Referenced\Tracker;

it('normalises paths exactly as the server does', function () {
    $cases = json_decode(file_get_contents(__DIR__.'/../fixtures/path-normalisation.json'), true);
    expect($cases)->not->toBeEmpty();
    foreach ($cases as $case) {
        expect(Tracker::normalisePath($case['in']))->toBe($case['out'], $case['in']);
    }
});

it('excludes WordPress-owned paths', function (string $path, bool $excluded) {
    expect(Tracker::isExcludedPath($path))->toBe($excluded);
})->with([
    ['/wp-login.php', true],
    ['/wp-json/wp/v2/posts', true],
    ['/wp-admin/admin-ajax.php', true],
    ['/wp-json', true],
    ['/wp-admin', true],
    ['/xmlrpc.php', true],
    ['/wp-cron.php', true],
    ['/blog/wp-json-is-my-slug', false],
    ['/', false],
]);
