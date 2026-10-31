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
        if (in_array($path, ['/wp-login.php', '/xmlrpc.php', '/wp-cron.php', '/wp-json', '/wp-admin'], true)) {
            return true;
        }

        return strpos($path, '/wp-json/') === 0 || strpos($path, '/wp-admin/') === 0;
    }

    /**
     * The two UTC dates the plugin sends: today and yesterday.
     *
     * @return array{today:string,yesterday:string}
     */
    public static function sendDates(int $timestamp): array
    {
        return [
            'today' => gmdate('Y-m-d', $timestamp),
            'yesterday' => gmdate('Y-m-d', $timestamp - 86400),
        ];
    }

    /**
     * Split local rows into request bodies of at most 500 hits. Always returns at
     * least one body, so an empty list still produces the heartbeat.
     *
     * @param array<int,array{date:string,bot:string,path:string,count:mixed}> $rows
     * @param array<string,mixed> $plugin
     * @return array<int,array{hits:array,plugin:array}>
     */
    public static function buildPayloads(array $rows, array $plugin): array
    {
        $hits = [];
        foreach ($rows as $row) {
            $hits[] = [
                'date' => (string) $row['date'],
                'bot' => (string) $row['bot'],
                'path' => (string) $row['path'],
                'count' => (int) $row['count'],
            ];
        }

        if ($hits === []) {
            return [['hits' => [], 'plugin' => $plugin]];
        }

        $bodies = [];
        foreach (array_chunk($hits, 500) as $chunk) {
            $bodies[] = ['hits' => $chunk, 'plugin' => $plugin];
        }

        return $bodies;
    }

    /**
     * The UTC date seven days before the timestamp; local rows dated before it are deleted.
     */
    public static function pruneCutoff(int $timestamp): string
    {
        return gmdate('Y-m-d', $timestamp - 7 * 86400);
    }

    /**
     * Return the server's vocabulary when it is well formed, otherwise null so the
     * caller keeps what it has. Rejects anything that could match every request.
     *
     * @param mixed $bots
     */
    public static function acceptVocabulary($bots): ?array
    {
        if (! is_array($bots) || $bots === []) {
            return null;
        }

        foreach ($bots as $slug => $token) {
            if (! preg_match('/^[a-z0-9-]{1,40}$/', (string) $slug)) {
                return null;
            }
            if (! is_string($token) || $token === '' || strlen($token) > 60) {
                return null;
            }
        }

        return $bots;
    }

    /**
     * One-line outcome of the last send, for the settings screen.
     */
    public static function statusLine(int $httpCode, string $body, int $timestamp): string
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            $data = [];
        }

        if ($httpCode === 200) {
            $accepted = isset($data['accepted']) && is_numeric($data['accepted']) ? (int) $data['accepted'] : 0;

            return 'Sent '.$accepted.' rows at '.gmdate('H:i', $timestamp).' UTC';
        }

        if ($httpCode === 401) {
            return 'Token rejected. Check it in Referenced → Settings → Connections.';
        }

        if ($httpCode === 403) {
            return isset($data['message']) && is_string($data['message']) && $data['message'] !== ''
                ? $data['message']
                : 'Account inactive.';
        }

        if ($httpCode === 429) {
            return 'Throttled; will retry next hour.';
        }

        if ($httpCode === 0) {
            return 'Could not reach Referenced.';
        }

        return 'HTTP '.$httpCode;
    }
}
