<?php

use Referenced\Tracker;

it('maps real crawler user agents to slugs', function (string $ua, string $slug) {
    expect(Tracker::matchBot($ua, Tracker::DEFAULT_BOTS))->toBe($slug);
})->with([
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot', 'gptbot'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot', 'oai-searchbot'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'chatgpt-user'],
    ['Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'claudebot'],
    ['Mozilla/5.0 (compatible; Claude-SearchBot/1.0; +https://www.anthropic.com/claude-searchbot)', 'claude-searchbot'],
    ['Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'perplexitybot'],
    ['Mozilla/5.0 (compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)', 'perplexity-user'],
    ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'googlebot'],
    ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'bingbot'],
    ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'meta-externalagent'],
]);

it('returns null for browsers and empty agents', function (string $ua) {
    expect(Tracker::matchBot($ua, Tracker::DEFAULT_BOTS))->toBeNull();
})->with(['', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'curl/8.4.0']);

it('ships every slug Referenced can detect', function () {
    expect(Tracker::DEFAULT_BOTS)->toHaveCount(21)->toHaveKeys(['gptbot', 'claude-searchbot', 'meta-webindexer', 'bravebot'])->not->toHaveKeys(['google-extended', 'applebot-extended']);
});

it('has no token that is a substring of another token', function () {
    $tokens = array_map('strtolower', array_values(Tracker::DEFAULT_BOTS));

    foreach ($tokens as $i => $a) {
        foreach ($tokens as $j => $b) {
            if ($i === $j) {
                continue;
            }
            expect(strpos($b, $a))->toBeFalse("'{$a}' is a substring of '{$b}'");
        }
    }
});

it('lets the first vocabulary entry win when several tokens match', function () {
    $ua = 'Mozilla/5.0 (compatible; alpha-bot; beta-bot)';

    expect(Tracker::matchBot($ua, ['b' => 'beta-bot', 'a' => 'alpha-bot']))->toBe('b')
        ->and(Tracker::matchBot($ua, ['a' => 'alpha-bot', 'b' => 'beta-bot']))->toBe('a');
});

it('matches case-insensitively and works with a custom vocabulary', function () {
    expect(Tracker::matchBot('SOMETHING GPTBOT/1', ['gptbot' => 'GPTBot']))->toBe('gptbot')
        ->and(Tracker::matchBot('GPTBot', []))->toBeNull();
});
