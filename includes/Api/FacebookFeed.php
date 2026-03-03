<?php
/**
 * Facebook Feed Fetcher
 *
 * Retrieves posts from Facebook Pages via the Meta Graph API.
 * Post normalization is delegated to Helpers\FacebookPostNormalizer.
 *
 * @package SocialPostsSync\Api
 */

declare(strict_types=1);

namespace SocialPostsSync\Api;

defined('ABSPATH') || exit;

use SocialPostsSync\Auth\MetaOAuth;
use SocialPostsSync\Auth\TokenStorage;
use SocialPostsSync\Helpers\FacebookPostNormalizer;

/**
 * Fetches and normalizes Facebook Page posts.
 */
class FacebookFeed implements FeedInterface {

    /**
     * Fields requested for each Facebook post.
     */
    private const POST_FIELDS = 'id,message,full_picture,created_time,permalink_url,reactions.summary(total_count),attachments{media_type,media{image{src},source},subattachments{media_type,media{image{src},source}}}';

    /**
     * Fields requested for page info (avatar).
     */
    private const PAGE_FIELDS = 'id,name,picture{url}';

    /**
     * Option key used to persist encrypted page tokens.
     */
    private const PAGE_TOKENS_OPTION = 'scps_page_tokens';

    private MetaApiClient         $client;
    private TokenStorage          $tokenStorage;
    private FacebookPostNormalizer $normalizer;

    /**
     * In-memory cache of page access tokens keyed by page ID.
     *
     * @var array<string, string>
     */
    private array $page_tokens = [];

    /**
     * @param MetaApiClient             $client       Authenticated Graph API client.
     * @param TokenStorage|null         $tokenStorage Token storage abstraction.
     * @param FacebookPostNormalizer|null $normalizer  Post normalizer.
     */
    public function __construct(
        MetaApiClient $client,
        ?TokenStorage $tokenStorage = null,
        ?FacebookPostNormalizer $normalizer = null
    ) {
        $this->client     = $client;
        $this->tokenStorage = $tokenStorage ?? new TokenStorage(new MetaOAuth());
        $this->normalizer   = $normalizer   ?? new FacebookPostNormalizer();
    }

    /**
     * Retrieve all Facebook Pages managed by the authenticated user.
     * Also caches their Page Access Tokens for subsequent requests.
     *
     * @return array Array of page objects with 'id', 'name', 'access_token'.
     *
     * @throws MetaApiException On API error.
     */
    public function getPages(): array {
        $data  = $this->client->get('/me/accounts', ['fields' => 'id,name,access_token,picture{url}']);
        $pages = $data['data'] ?? [];

        $new_tokens = [];
        foreach ($pages as $page) {
            if (!empty($page['id']) && !empty($page['access_token'])) {
                $this->page_tokens[$page['id']] = $page['access_token'];
                $new_tokens[$page['id']]        = $page['access_token'];
            }
        }
        if ($new_tokens) {
            $this->tokenStorage->setAll(self::PAGE_TOKENS_OPTION, $new_tokens);
        }

        return $pages;
    }

    /**
     * Get a MetaApiClient using the Page Access Token for a given page.
     * Falls back to the user token if no page token is found.
     *
     * @param string $pageId Facebook Page ID.
     *
     * @return MetaApiClient Client authenticated with the Page Access Token.
     */
    private function getPageClient(string $pageId): MetaApiClient {
        $token = $this->page_tokens[$pageId] ?? null;

        if (!$token) {
            $all   = $this->tokenStorage->getAll(self::PAGE_TOKENS_OPTION);
            $token = $all[$pageId] ?? '';
        }

        if ($token) {
            return new MetaApiClient($token);
        }

        try {
            $data = $this->client->get('/me/accounts', ['fields' => 'id,access_token']);
            foreach (($data['data'] ?? []) as $page) {
                if ((string) $page['id'] === $pageId && !empty($page['access_token'])) {
                    $token                      = $page['access_token'];
                    $this->page_tokens[$pageId] = $token;
                    $this->tokenStorage->setAll(self::PAGE_TOKENS_OPTION, [$pageId => $token]);
                    return new MetaApiClient($token);
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        return $this->client;
    }

    /**
     * Fetch posts for multiple Facebook pages in a single batch HTTP request.
     *
     * @param string[] $pageIds Array of Facebook Page IDs.
     * @param int      $limit   Maximum posts per page (0 = use scps_max_posts option).
     *
     * @return array<string, array> Map of pageId → normalized post array.
     */
    public function fetchPostsBatch(array $pageIds, int $limit = 0): array {
        if (empty($pageIds)) {
            return [];
        }

        if ($limit <= 0) {
            $limit = (int) get_option('scps_max_posts', 20);
        }

        $fields   = self::POST_FIELDS;
        $capped   = min($limit, 100);
        $requests = [];
        foreach ($pageIds as $i => $pageId) {
            $requests[$i] = [
                'method'       => 'GET',
                'relative_url' => ltrim($pageId, '/') . '/posts?fields=' . rawurlencode($fields) . '&limit=' . $capped,
            ];
        }

        try {
            $batch_results = $this->client->batch($requests);
        } catch (\Throwable $e) {
            $batch_results = array_fill(0, count($pageIds), null);
        }

        $results = [];
        foreach ($pageIds as $i => $pageId) {
            $sub = $batch_results[$i] ?? null;

            if (!is_array($sub)) {
                try {
                    $results[$pageId] = $this->fetchPosts($pageId, $limit);
                } catch (\Throwable) {
                    $results[$pageId] = [];
                }
                continue;
            }

            $page_info = $this->getPageInfo($pageId, $this->getPageClient($pageId));
            $posts     = [];
            foreach (($sub['data'] ?? []) as $raw) {
                $posts[] = $this->normalizer->normalize($raw, $page_info);
            }
            $results[$pageId] = $posts;
        }

        return $results;
    }

    /**
     * Fetch basic info for any public Facebook Page (without admin rights).
     *
     * @param string $pageId Facebook Page ID.
     *
     * @return array Associative array with 'id', 'name', 'avatar'.
     *
     * @throws MetaApiException If the page is inaccessible or not found.
     */
    public function fetchPublicPageInfo(string $pageId): array {
        $data = $this->client->get("/{$pageId}", ['fields' => 'id,name,picture{url}']);

        return [
            'id'     => (string) ($data['id']   ?? $pageId),
            'name'   => (string) ($data['name'] ?? ''),
            'avatar' => (string) ($data['picture']['data']['url'] ?? ''),
        ];
    }

    /**
     * Fetch recent posts from a Facebook Page.
     *
     * @param string $pageId Facebook Page ID.
     * @param int    $limit  Maximum number of posts to retrieve.
     *
     * @return array Normalized post arrays.
     *
     * @throws MetaApiException On API error.
     */
    public function fetchPosts(string $pageId, int $limit = 0): array {
        $page_client = $this->getPageClient($pageId);
        $page_info   = $this->getPageInfo($pageId, $page_client);

        if ($limit <= 0) {
            $limit = (int) get_option('scps_max_posts', 20);
        }

        $data = $page_client->get("/{$pageId}/posts", [
            'fields' => self::POST_FIELDS,
            'limit'  => min($limit, 100),
        ]);

        $posts = [];
        foreach (($data['data'] ?? []) as $raw) {
            $posts[] = $this->normalizer->normalize($raw, $page_info);
        }

        return $posts;
    }

    /**
     * Fetch only posts published since a given Unix timestamp (incremental sync).
     *
     * @param string $pageId Facebook Page ID.
     * @param string $since  Unix timestamp string.
     * @param int    $limit  Maximum number of posts.
     *
     * @return array Normalized post arrays.
     *
     * @throws MetaApiException On API error.
     */
    public function fetchSince(string $pageId, string $since, int $limit = 0): array {
        $page_client = $this->getPageClient($pageId);
        $page_info   = $this->getPageInfo($pageId, $page_client);

        if ($limit <= 0) {
            $limit = (int) get_option('scps_max_posts', 20);
        }

        $data = $page_client->get("/{$pageId}/posts", [
            'fields' => self::POST_FIELDS,
            'since'  => $since,
            'limit'  => min($limit, 100),
        ]);

        $posts = [];
        foreach (($data['data'] ?? []) as $raw) {
            $posts[] = $this->normalizer->normalize($raw, $page_info);
        }

        return $posts;
    }

    /**
     * Fetch basic info for a page (name and avatar URL).
     *
     * @param string        $pageId      Facebook Page ID.
     * @param MetaApiClient $page_client Client to use.
     *
     * @return array Associative array with 'name' and 'avatar'.
     */
    private function getPageInfo(string $pageId, MetaApiClient $page_client): array {
        try {
            $data = $page_client->get("/{$pageId}", ['fields' => self::PAGE_FIELDS]);
            return [
                'name'   => (string) ($data['name'] ?? ''),
                'avatar' => (string) ($data['picture']['data']['url'] ?? ''),
            ];
        } catch (\Throwable $e) {
            unset($e);
            return ['name' => '', 'avatar' => ''];
        }
    }
}
