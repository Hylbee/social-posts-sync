<?php
/**
 * Cron-triggered sync runner.
 *
 * Handles the WP-Cron sync callback: acquires the lock, delegates execution
 * to SyncRunner, and releases the lock.
 *
 * @package SocialPostsSync\Sync
 */

declare(strict_types=1);

namespace SocialPostsSync\Sync;

defined('ABSPATH') || exit;

use SocialPostsSync\Auth\MetaOAuth;
use SocialPostsSync\Api\MetaApiClient;

class CronSync {

    /**
     * Run the full cron sync (entry point for the WP-Cron action).
     */
    public function run(): void {
        if (!scps_acquire_sync_lock()) {
            return;
        }

        try {
            $this->doSync();
        } finally {
            scps_release_sync_lock();
        }
    }

    /**
     * Build dependencies and delegate execution to SyncRunner.
     */
    private function doSync(): void {
        $oauth = new MetaOAuth();
        $token = $oauth->getAccessToken();

        if (!$token) {
            scps_log_sync(['error' => 'No access token available for cron sync.']);
            return;
        }

        $client = new MetaApiClient($token);
        (new SyncRunner())->run($client);
    }
}
