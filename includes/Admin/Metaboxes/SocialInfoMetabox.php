<?php
/**
 * Metabox: Informations sociales
 *
 * Displays social post metadata (platform, date, author, likes, permalink, media URLs)
 * on the social_post edit screen.
 *
 * @package SocialPostsSync\Admin\Metaboxes
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin\Metaboxes;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Registers and renders the "Informations sociales" metabox.
 */
class SocialInfoMetabox {

    public function register(): void {
        add_meta_box(
            'scps_social_info',
            __('Informations sociales', 'social-posts-sync'),
            [$this, 'render'],
            SocialPostCPT::POST_TYPE,
            'side',
            'high'
        );
    }

    public function render(\WP_Post $post): void {
        $permalink      = (string) get_post_meta($post->ID, SocialPostCPT::META_PERMALINK, true);
        $platform       = (string) get_post_meta($post->ID, SocialPostCPT::META_PLATFORM, true);
        $published_at   = (string) get_post_meta($post->ID, SocialPostCPT::META_PUBLISHED_AT, true);
        $author_name    = (string) get_post_meta($post->ID, SocialPostCPT::META_AUTHOR_NAME, true);
        $likes          = (int)    get_post_meta($post->ID, SocialPostCPT::META_LIKES_COUNT, true);
        $video_url      = (string) get_post_meta($post->ID, SocialPostCPT::META_VIDEO_URL, true);
        $media_urls_raw = get_post_meta($post->ID, SocialPostCPT::META_MEDIA_URLS, true);
        $media_urls     = is_string($media_urls_raw) ? (array) json_decode($media_urls_raw, true) : [];

        $platform_label = match (strtolower($platform)) {
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            default     => ucfirst($platform),
        };

        $date_formatted = '';
        if ($published_at) {
            try {
                $dt = new \DateTimeImmutable($published_at);
                $date_formatted = $dt->format('d/m/Y à H:i');
            } catch (\Throwable) {}
        }
        ?>
        <table class="scps-meta-table">
            <?php if ($platform_label) : ?>
            <tr>
                <td class="scps-meta-label"><?php esc_html_e('Plateforme', 'social-posts-sync'); ?></td>
                <td class="scps-meta-value"><?php echo esc_html($platform_label); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($date_formatted) : ?>
            <tr>
                <td class="scps-meta-label"><?php esc_html_e('Publié le', 'social-posts-sync'); ?></td>
                <td><?php echo esc_html($date_formatted); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($author_name) : ?>
            <tr>
                <td class="scps-meta-label"><?php esc_html_e('Auteur', 'social-posts-sync'); ?></td>
                <td><?php echo esc_html($author_name); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="scps-meta-label"><?php esc_html_e('Likes', 'social-posts-sync'); ?></td>
                <td><?php echo esc_html(number_format_i18n($likes)); ?></td>
            </tr>
            <?php if ($permalink) : ?>
            <tr>
                <td class="scps-meta-label"><?php esc_html_e('Lien original', 'social-posts-sync'); ?></td>
                <td>
                    <a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener" class="scps-permalink-link">
                        <?php esc_html_e('Voir le post original', 'social-posts-sync'); ?>
                        <span class="dashicons dashicons-external scps-external-icon"></span>
                    </a>
                </td>
            </tr>
            <?php endif; ?>
        </table>

        <?php if ($permalink) : ?>
        <p class="scps-url-box scps-url-box--mt">
            <span class="scps-url-clamp"><?php echo esc_html($permalink); ?></span>
        </p>
        <?php endif; ?>

        <?php if ($video_url) : ?>
        <div class="scps-meta-section">
            <div class="scps-meta-section-title">
                <span class="dashicons dashicons-video-alt3"></span>
                <?php esc_html_e('Vidéo', 'social-posts-sync'); ?>
            </div>
            <p class="scps-url-box">
                <a href="<?php echo esc_url($video_url); ?>" target="_blank" rel="noopener">
                    <span class="scps-url-clamp"><?php echo esc_html($video_url); ?></span>
                </a>
            </p>
        </div>
        <?php endif; ?>

        <?php if (!empty($media_urls)) : ?>
        <div class="scps-meta-section">
            <div class="scps-meta-section-title">
                <span class="dashicons dashicons-format-gallery"></span>
                <?php
                printf(
                    /* translators: %d: number of media URLs */
                    esc_html(_n('URL média (%d)', 'URLs médias (%d)', count($media_urls), 'social-posts-sync')),
                    count($media_urls)
                );
                ?>
            </div>
            <ol class="scps-url-list">
                <?php foreach ($media_urls as $url) : ?>
                <li>
                    <p class="scps-url-box">
                        <a href="<?php echo esc_url((string) $url); ?>" target="_blank" rel="noopener">
                            <span class="scps-url-clamp"><?php echo esc_html((string) $url); ?></span>
                        </a>
                    </p>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php endif; ?>
        <?php
    }
}
