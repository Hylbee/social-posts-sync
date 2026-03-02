<?php
/**
 * Admin Settings Tab — Synchronisation
 *
 * @package SocialPostsSync\Admin\Tabs
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin\Tabs;

defined('ABSPATH') || exit;

class SyncTab {

    public function render(): void {
        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        $cron_interval   = (string) get_option('scps_cron_interval', 'hourly');
        $next_cron       = wp_next_scheduled('scps_sync_posts');
        $sync_log        = (array) get_option('scps_sync_log', []);
        $max_posts       = (int) get_option('scps_max_posts', 20);

        $cron_options = [
            'hourly'             => __('Toutes les heures', 'social-posts-sync'),
            'scps_every_6_hours'  => __('Toutes les 6 heures', 'social-posts-sync'),
            'scps_every_12_hours' => __('Toutes les 12 heures', 'social-posts-sync'),
            'daily'              => __('Tous les jours', 'social-posts-sync'),
        ];
        ?>
        <div class="scps-card">
            <h2><?php esc_html_e('Synchronisation manuelle', 'social-posts-sync'); ?></h2>

            <p>
                <button type="button" id="scps-sync-now" class="button button-primary">
                    <span class="dashicons dashicons-update scps-btn-icon"></span>
                    <?php esc_html_e('Synchroniser maintenant', 'social-posts-sync'); ?>
                </button>
                <span id="scps-sync-status" class="scps-sync-status-wrap"></span>
            </p>

            <?php if (!empty($enabled_sources)) : ?>
                <h3><?php esc_html_e('Dernière synchronisation par source', 'social-posts-sync'); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Source', 'social-posts-sync'); ?></th>
                            <th><?php esc_html_e('Plateforme', 'social-posts-sync'); ?></th>
                            <th><?php esc_html_e('Dernière synchro', 'social-posts-sync'); ?></th>
                            <th><?php esc_html_e('Publications', 'social-posts-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach (($enabled_sources['facebook'] ?? []) as $source) :
                            $page_id = is_array($source) ? (string) ($source['id']   ?? '') : (string) $source;
                            $name    = is_array($source) ? (string) ($source['name'] ?? '') : '';
                            if ($page_id === '') continue;
                            $last   = get_option("scps_last_sync_{$page_id}", '');
                            $last_f = $last ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last) : __('Jamais', 'social-posts-sync');
                            $count  = $this->countSourcePosts('facebook', $page_id);
                        ?>
                            <tr>
                                <td>
                                    <?php if ($name) : ?>
                                        <strong><?php echo esc_html($name); ?></strong>
                                        <br><small class="scps-muted"><?php echo esc_html($page_id); ?></small>
                                    <?php else : ?>
                                        <?php echo esc_html($page_id); ?>
                                    <?php endif; ?>
                                </td>
                                <td>Facebook</td>
                                <td><?php echo esc_html($last_f); ?></td>
                                <td><?php echo esc_html((string) $count); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php
                        foreach (($enabled_sources['instagram'] ?? []) as $source) :
                            $ig_id = is_array($source) ? (string) ($source['id']   ?? '') : (string) $source;
                            $name  = is_array($source) ? (string) ($source['name'] ?? '') : '';
                            if ($ig_id === '') continue;
                            $last   = get_option("scps_last_sync_{$ig_id}", '');
                            $last_f = $last ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last) : __('Jamais', 'social-posts-sync');
                            $count  = $this->countSourcePosts('instagram', $ig_id);
                        ?>
                            <tr>
                                <td>
                                    <?php if ($name) : ?>
                                        <strong><?php echo esc_html($name); ?></strong>
                                        <br><small class="scps-muted"><?php echo esc_html($ig_id); ?></small>
                                    <?php else : ?>
                                        <?php echo esc_html($ig_id); ?>
                                    <?php endif; ?>
                                </td>
                                <td>Instagram</td>
                                <td><?php echo esc_html($last_f); ?></td>
                                <td><?php echo esc_html((string) $count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="scps-card scps-card--spaced">
            <h2><?php esc_html_e('Paramètres de récupération', 'social-posts-sync'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="scps_save_sync_settings">
                <?php wp_nonce_field('scps_save_sync_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="scps_max_posts"><?php esc_html_e('Posts max par source', 'social-posts-sync'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="scps_max_posts" name="scps_max_posts"
                                   value="<?php echo esc_attr((string) $max_posts); ?>"
                                   min="1" max="100" step="1" class="small-text">
                            <p class="description">
                                <?php esc_html_e('Nombre maximum de publications récupérées par source à chaque synchronisation (1–100). L\'API Meta renvoie au maximum 100 par page.', 'social-posts-sync'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Enregistrer', 'social-posts-sync')); ?>
            </form>
        </div>

        <div class="scps-card scps-card--spaced">
            <h2><?php esc_html_e('Planification automatique (WP-Cron)', 'social-posts-sync'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="scps_save_cron">
                <?php wp_nonce_field('scps_save_cron'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="scps_cron_interval"><?php esc_html_e('Fréquence', 'social-posts-sync'); ?></label>
                        </th>
                        <td>
                            <select id="scps_cron_interval" name="scps_cron_interval">
                                <?php foreach ($cron_options as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($cron_interval, $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Prochaine exécution', 'social-posts-sync'); ?></th>
                        <td>
                            <?php if ($next_cron) : ?>
                                <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_cron)); ?>
                            <?php else : ?>
                                <?php esc_html_e('Non planifiée', 'social-posts-sync'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Enregistrer la planification', 'social-posts-sync')); ?>
            </form>
        </div>

        <?php if (!empty($sync_log)) : ?>
        <div class="scps-card scps-card--spaced">
            <h2><?php esc_html_e('Journal de synchronisation (10 dernières entrées)', 'social-posts-sync'); ?></h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'social-posts-sync'); ?></th>
                        <th><?php esc_html_e('Succès', 'social-posts-sync'); ?></th>
                        <th><?php esc_html_e('Erreurs', 'social-posts-sync'); ?></th>
                        <th><?php esc_html_e('Sources', 'social-posts-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sync_log as $entry) : ?>
                        <?php if (!is_array($entry)) continue; ?>
                        <tr>
                            <td><?php echo esc_html($entry['timestamp'] ?? ''); ?></td>
                            <td><?php echo esc_html((string) ($entry['success'] ?? 0)); ?></td>
                            <td><?php echo esc_html((string) ($entry['errors'] ?? 0)); ?></td>
                            <td>
                                <?php if (!empty($entry['sources'])) : ?>
                                    <?php
                                    $parts = [];
                                    foreach ($entry['sources'] as $src_id => $count) {
                                        $parts[] = esc_html($src_id) . ': ' . esc_html((string) $count);
                                    }
                                    echo implode(', ', $parts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each $parts entry is already esc_html'd above
                                    ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Count social_post entries for a given platform and source ID.
     *
     * @param string $platform  'facebook' or 'instagram'.
     * @param string $source_id Facebook Page ID or Instagram Account ID.
     *
     * @return int Post count.
     */
    private function countSourcePosts(string $platform, string $source_id): int {
        $query = new \WP_Query([
            'post_type'      => \SocialPostsSync\CPT\SocialPostCPT::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional, count per platform is admin-only and infrequent
                'relation' => 'AND',
                [
                    'key'   => \SocialPostsSync\CPT\SocialPostCPT::META_PLATFORM,
                    'value' => $platform,
                ],
            ],
            'fields'         => 'ids',
        ]);

        return (int) $query->found_posts;
    }
}
