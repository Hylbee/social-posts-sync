<?php
/**
 * Global helper functions for Social Posts Sync.
 *
 * These are thin wrappers around ScpsHelpers static methods. They remain in
 * the global namespace so existing callers throughout the plugin need no changes.
 *
 * All logic lives in ScpsHelpers, making it unit-testable independently.
 *
 * @package SocialPostsSync
 */

defined('ABSPATH') || exit;

use SocialPostsSync\ScpsHelpers;

/** @param array $enabled_sources @param string $platform @return array<array{id:string,name:string}> */
function scps_extract_sources(array $enabled_sources, string $platform): array {
    return ScpsHelpers::extractSources($enabled_sources, $platform);
}

/** @param array $enabled_sources @param string $platform @return string[] */
function scps_extract_source_ids(array $enabled_sources, string $platform): array {
    return ScpsHelpers::extractSourceIds($enabled_sources, $platform);
}

/** @param array $entry */
function scps_log_sync(array $entry): void {
    ScpsHelpers::logSync($entry);
}

/** @return bool */
function scps_acquire_sync_lock(): bool {
    return ScpsHelpers::acquireSyncLock();
}

function scps_release_sync_lock(): void {
    ScpsHelpers::releaseSyncLock();
}

/** @return bool */
function scps_is_sync_running(): bool {
    return ScpsHelpers::isSyncRunning();
}
