<?php

use Referenced\Tracker;

it('send dates are UTC', function () {
    expect(Tracker::sendDates(strtotime('2026-11-20 23:30:00 UTC')))
        ->toBe(['today' => '2026-11-20', 'yesterday' => '2026-11-19']);
    expect(Tracker::sendDates(strtotime('2026-12-01 00:10:00 UTC')))
        ->toBe(['today' => '2026-12-01', 'yesterday' => '2026-11-30']);
});

it('builds a heartbeat body when there are no rows', function () {
    expect(Tracker::buildPayloads([], ['version' => '1.0.0']))
        ->toBe([['hits' => [], 'plugin' => ['version' => '1.0.0']]]);
});

it('casts counts to int and keeps row fields', function () {
    $bodies = Tracker::buildPayloads([
        ['date' => '2026-11-20', 'bot' => 'gptbot', 'path' => '/a', 'count' => '7'],
    ], ['version' => '1.0.0']);

    expect($bodies)->toHaveCount(1);
    expect($bodies[0]['hits'])->toBe([
        ['date' => '2026-11-20', 'bot' => 'gptbot', 'path' => '/a', 'count' => 7],
    ]);
});

it('chunks hits into bodies of at most 500', function () {
    $rows = [];
    for ($i = 0; $i < 1001; $i++) {
        $rows[] = ['date' => '2026-11-20', 'bot' => 'gptbot', 'path' => '/p'.$i, 'count' => 1];
    }
    $plugin = ['version' => '1.0.0'];

    $bodies = Tracker::buildPayloads($rows, $plugin);

    expect(array_map(fn ($b) => count($b['hits']), $bodies))->toBe([500, 500, 1]);
    foreach ($bodies as $body) {
        expect($body['plugin'])->toBe($plugin);
    }
});

it('prunes rows older than seven days', function () {
    expect(Tracker::pruneCutoff(strtotime('2026-11-20 UTC')))->toBe('2026-11-13');
    expect(Tracker::pruneCutoff(strtotime('2026-11-20 23:59:00 UTC')))->toBe('2026-11-13');
});

it('accepts a well-formed vocabulary', function () {
    $bots = ['gptbot' => 'GPTBot', 'oai-searchbot' => 'OAI-SearchBot'];
    expect(Tracker::acceptVocabulary($bots))->toBe($bots);
});

it('rejects a malformed vocabulary', function ($bots) {
    expect(Tracker::acceptVocabulary($bots))->toBeNull();
})->with([
    [null],
    [[]],
    [['bad slug' => 'X']],
    [['Upper' => 'X']],
    [[str_repeat('a', 41) => 'X']],
    [['gptbot' => '']],
    [['gptbot' => ['no']]],
    [['gptbot' => 5]],
    [['gptbot' => str_repeat('x', 61)]],
    [['gptbot' => 'GPTBot', 'x' => '']],
    ['string'],
]);

it('builds status lines', function (int $code, string $body, string $expected) {
    expect(Tracker::statusLine($code, $body, strtotime('2026-11-20 14:05:00 UTC')))->toBe($expected);
})->with([
    [200, '{"accepted":12,"dropped":0}', 'Sent 12 rows at 14:05 UTC'],
    [200, 'not json', 'Sent 0 rows at 14:05 UTC'],
    [401, '{"message":"Unauthorized."}', 'Token rejected. Check it in Referenced → Settings → Connections.'],
    [403, '{"message":"Account inactive."}', 'Account inactive.'],
    [403, '', 'Account inactive.'],
    [429, '{"message":"Too many requests."}', 'Throttled; will retry next hour.'],
    [0, '', 'Could not reach Referenced.'],
    [500, 'oops', 'HTTP 500'],
]);
