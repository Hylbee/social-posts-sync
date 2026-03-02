<?php
/**
 * Global helper functions for Social Posts Sync.
 *
 * Provides utility functions used across the plugin: sync locking, logging,
 * and source extraction. These are intentionally global (no namespace) to
 * remain callable from any context without use statements.
 *
 * @package SocialPostsSync
 */

defined('ABSPATH') || exit;

/**
 * Extract a list of {id, name} objects for a given platform from scps_enabled_sources.
 * Supports both the structured format [['id'=>..., 'name'=>...]] and the legacy flat format ['id1', 'id2'].
 *
 * @param array  $enabled_sources Value of the scps_enabled_sources option.
 * @param string $platform        'facebook' or 'instagram'.
 *
 * @return array<array{id: string, name: string}>
 */
function scps_extract_sources(array $enabled_sources, string $platform): array {
    $sources = [];
    foreach (($enabled_sources[$platform] ?? []) as $source) {
        if (is_array($source) && !empty($source['id'])) {
            $sources[] = ['id' => (string) $source['id'], 'name' => (string) ($source['name'] ?? '')];
        } elseif (is_string($source) && $source !== '') {
            $sources[] = ['id' => $source, 'name' => ''];
        }
    }
    return $sources;
}

/**
 * Extract a flat list of source IDs for a given platform from scps_enabled_sources.
 * Supports both the structured format [['id'=>..., 'name'=>...]] and the legacy flat format ['id1', 'id2'].
 *
 * @param array  $enabled_sources Value of the scps_enabled_sources option.
 * @param string $platform        'facebook' or 'instagram'.
 *
 * @return string[]
 */
function scps_extract_source_ids(array $enabled_sources, string $platform): array {
    $ids = [];
    foreach (($enabled_sources[$platform] ?? []) as $source) {
        if (is_array($source) && !empty($source['id'])) {
            $ids[] = (string) $source['id'];
        } elseif (is_string($source) && $source !== '') {
            $ids[] = $source;
        }
    }
    return $ids;
}

/**
 * Append an entry to the sync log option (keeps last 10 entries).
 *
 * @param array $entry Log entry data.
 */
function scps_log_sync(array $entry): void {
    $log = get_option('scps_sync_log', []);
    if (!is_array($log)) {
        $log = [];
    }
    array_unshift($log, $entry);
    update_option('scps_sync_log', array_slice($log, 0, 10));
}

/**
 * Acquire the sync lock.
 *
 * @return bool True if the lock was acquired, false if already locked.
 */
function scps_acquire_sync_lock(): bool {
    if (get_option('scps_sync_running') === '1') {
        return false;
    }
    update_option('scps_sync_running', '1', false);
    return true;
}

/**
 * Release the sync lock.
 */
function scps_release_sync_lock(): void {
    update_option('scps_sync_running', '0', false);
}

/**
 * Check whether a sync is currently in progress.
 *
 * @return bool True if running.
 */
function scps_is_sync_running(): bool {
    return get_option('scps_sync_running') === '1';
}
