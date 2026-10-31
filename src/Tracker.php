<?php

namespace Referenced;

/**
 * Decides whether a request came from an AI crawler.
 *
 * Pure: no WordPress functions here.
 */
final class Tracker
{
    /**
     * Bundled copy of the server's detectable vocabulary (slug => user-agent token).
     * The Referenced server owns this list; the plugin prefers the copy stored from
     * the last server response and falls back to this one.
     */
    const DEFAULT_BOTS = [
        'gptbot' => 'GPTBot',
        'oai-searchbot' => 'OAI-SearchBot',
        'chatgpt-user' => 'ChatGPT-User',
        'oai-adsbot' => 'OAI-AdsBot',
        'claudebot' => 'ClaudeBot',
        'claude-searchbot' => 'Claude-SearchBot',
        'claude-user' => 'Claude-User',
        'perplexitybot' => 'PerplexityBot',
        'perplexity-user' => 'Perplexity-User',
        'googlebot' => 'Googlebot',
        'applebot' => 'Applebot',
        'bingbot' => 'Bingbot',
        'bravebot' => 'Bravebot',
        'amazonbot' => 'Amazonbot',
        'meta-externalagent' => 'Meta-ExternalAgent',
        'meta-webindexer' => 'Meta-WebIndexer',
        'meta-externalfetcher' => 'Meta-ExternalFetcher',
        'bytespider' => 'Bytespider',
        'cohere-ai' => 'Cohere-AI',
        'diffbot' => 'Diffbot',
        'ccbot' => 'CCBot',
    ];

    /**
     * Return the slug of the first vocabulary token found (case-insensitively) in the
     * user agent, or null when none match or the agent is empty.
     *
     * Order matters: the vocabulary is walked in order and the first match wins, so
     * the server must list a more specific token before any token it contains.
     *
     * @param array<string,string> $vocabulary slug => user-agent token
     */
    public static function matchBot(string $userAgent, array $vocabulary): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach ($vocabulary as $slug => $token) {
            if ($token !== '' && stripos($userAgent, (string) $token) !== false) {
                return (string) $slug;
            }
        }

        return null;
    }
}
