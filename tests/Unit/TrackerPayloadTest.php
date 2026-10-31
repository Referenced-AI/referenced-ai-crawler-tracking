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
    [['gptbot', 'claudebot']],
    [["gptbot\n" => 'GPTBot']],
    [['gptbot' => ' ']],
]);

it('builds status lines', function (int $code, string $body, string $expected) {
    expect(Tracker::statusLine($code, $body, strtotime('2026-11-20 14:05:00 UTC')))->toBe($expected);
})->with([
    [401, '{"message":"Unauthorized."}', 'Token rejected. Check it in Referenced → Settings → Connections.'],
    [403, '{"message":"Account inactive."}', 'Account inactive.'],
    [403, '', 'Account inactive.'],
    [429, '{"message":"Too many requests."}', 'Throttled; will retry next hour.'],
    [0, '', 'Could not reach Referenced.'],
    [422, '{"message":"Too many hits."}', 'HTTP 422'],
    [500, 'oops', 'HTTP 500'],
]);

it('prints the summed accepted total on a 200 status line', function () {
    $at = strtotime('2026-11-20 14:05:00 UTC');

    expect(Tracker::statusLine(200, '', $at, 12))->toBe('Sent 12 rows at 14:05 UTC')
        ->and(Tracker::statusLine(200, '', $at))->toBe('Sent 0 rows at 14:05 UTC');
});

it('appends the transport error to the unreachable status line', function () {
    $at = strtotime('2026-11-20 14:05:00 UTC');

    expect(Tracker::statusLine(0, '', $at, 0, 'cURL error 28: timed out'))->toBe('Could not reach Referenced: cURL error 28: timed out')
        ->and(Tracker::statusLine(0, '', $at, 0, ''))->toBe('Could not reach Referenced.');
});

it('sends exactly 500 rows as one body', function () {
    $rows = [];
    for ($i = 0; $i < 500; $i++) {
        $rows[] = ['date' => '2026-11-20', 'bot' => 'gptbot', 'path' => '/p'.$i, 'count' => 1];
    }

    expect(array_map(fn ($b) => count($b['hits']), Tracker::buildPayloads($rows, [])))->toBe([500]);
});

it('sends exactly 1000 rows as two bodies with no empty trailing body', function () {
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = ['date' => '2026-11-20', 'bot' => 'gptbot', 'path' => '/p'.$i, 'count' => 1];
    }

    expect(array_map(fn ($b) => count($b['hits']), Tracker::buildPayloads($rows, [])))->toBe([500, 500]);
});

it('clamps counts to the server maximum instead of losing the row', function () {
    $bodies = Tracker::buildPayloads([
        ['date' => '2026-11-20', 'bot' => 'gptbot', 'path' => '/a', 'count' => '250000'],
        ['date' => '2026-11-20', 'bot' => 'gptbot', 'path' => '/b', 'count' => 100000],
        ['date' => '2026-11-20', 'bot' => 'gptbot', 'path' => '/c', 'count' => 99999],
    ], []);

    expect(array_column($bodies[0]['hits'], 'count'))->toBe([100000, 100000, 99999]);
});

it('sums accepted across response bodies', function () {
    $bodies = ['{"accepted":3}', '{"accepted":null}', '{"accepted":"5"}', '{"accepted":-3}', 'not json', '', '{"dropped":1}'];

    expect(Tracker::sumAccepted($bodies))->toBe(8)
        ->and(Tracker::sumAccepted([]))->toBe(0);
});

it('chooses the stored vocabulary only when it is a non-empty array', function ($stored, $expected) {
    expect(Tracker::vocabulary($stored))->toBe($expected);
})->with([
    [null, Tracker::DEFAULT_BOTS],
    [false, Tracker::DEFAULT_BOTS],
    [[], Tracker::DEFAULT_BOTS],
    ['gptbot', Tracker::DEFAULT_BOTS],
    [['custom' => 'CustomBot'], ['custom' => 'CustomBot']],
]);

it('rejects tokens shorter than three characters', function () {
    expect(Tracker::acceptVocabulary(['data' => 'ai']))->toBeNull()
        ->and(Tracker::acceptVocabulary(['dataset' => 'a']))->toBeNull()
        ->and(Tracker::acceptVocabulary(['abc' => 'abc']))->toBe(['abc' => 'abc']);
});
