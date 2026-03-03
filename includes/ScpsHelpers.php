<?php
/**
 * ScpsHelpers — Static utility class for Social Posts Sync.
 *
 * Contains all helper logic previously defined as global functions in helpers.php.
 * Using a static class makes the logic unit-testable (via mock subclassing or
 * direct instantiation) and eliminates reliance on the global function namespace.
 *
 * The global wrapper functions in helpers.php delegate to these methods so that
 * existing callers throughout the plugin require no changes.
 *
 * @package SocialPostsSync
 */

declare(strict_types=1);

namespace SocialPostsSync;

defined('ABSPATH') || exit;

class ScpsHelpers {

    /**
     * Extract a list of {id, name} objects for a given platform from scps_enabled_sources.
     * Supports both the structured format [['id'=>..., 'name'=>...]] and the legacy flat format ['id1', 'id2'].
     *
     * @param array  $enabled_sources Value of the scps_enabled_sources option.
     * @param string $platform        'facebook' or 'instagram'.
     *
     * @return array<array{id: string, name: string}>
     */
    public static function extractSources(array $enabled_sources, string $platform): array {
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
    public static function extractSourceIds(array $enabled_sources, string $platform): array {
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
    public static function logSync(array $entry): void {
        $log = get_option('scps_sync_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, $entry);
        update_option('scps_sync_log', array_slice($log, 0, 10));
    }

    /**
     * Acquire the sync lock atomically.
     *
     * Uses add_option() which fails (returns false) if the option already exists,
     * making the check-and-set operation atomic at the database level and avoiding
     * the TOCTOU race condition of a separate get + update pair.
     *
     * Automatically breaks stale locks older than 10 minutes.
     *
     * @return bool True if the lock was acquired, false if already locked.
     */
    public static function acquireSyncLock(): bool {
        $acquired = add_option('scps_sync_lock', time(), '', false);
        if ($acquired) {
            return true;
        }

        // Break stale locks older than 10 minutes to prevent permanent deadlocks
        $lock_time = (int) get_option('scps_sync_lock', 0);
        if ($lock_time && (time() - $lock_time) > 600) {
            delete_option('scps_sync_lock');
            return add_option('scps_sync_lock', time(), '', false);
        }

        return false;
    }

    /**
     * Release the sync lock.
     */
    public static function releaseSyncLock(): void {
        delete_option('scps_sync_lock');
    }

    /**
     * Check whether a sync is currently in progress.
     *
     * @return bool True if running.
     */
    public static function isSyncRunning(): bool {
        return (bool) get_option('scps_sync_lock', false);
    }
}
