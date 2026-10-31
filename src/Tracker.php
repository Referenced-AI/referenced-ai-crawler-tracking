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

    /**
     * Reduce a request URI to the path the server would store, or null when the
     * server would drop it. Mirrors the server's ingest normalisation exactly.
     */
    public static function normalisePath(string $requestUri): ?string
    {
        if ($requestUri === '') {
            return null;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (strpos($path, '/') !== 0) {
            $path = '/'.$path;
        }

        return mb_substr($path, 0, 500);
    }

    /**
     * Paths WordPress owns, which are never reported. Takes a normalised path.
     */
    public static function isExcludedPath(string $path): bool
    {
        if (in_array($path, ['/wp-login.php', '/xmlrpc.php', '/wp-cron.php'], true)) {
            return true;
        }

        return strpos($path, '/wp-json/') === 0 || strpos($path, '/wp-admin/') === 0;
    }
}
