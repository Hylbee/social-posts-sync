<?php
/**
 * Metabox: Galerie d'images
 *
 * Displays a thumbnail grid of sideloaded media attached to a social_post.
 *
 * @package SocialPostsSync\Admin\Metaboxes
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin\Metaboxes;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Registers and renders the "Galerie d'images" metabox.
 */
class GalleryMetabox {

    public function register(): void {
        add_meta_box(
            'scps_gallery',
            __('Galerie d\'images', 'social-posts-sync'),
            [$this, 'render'],
            SocialPostCPT::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render(\WP_Post $post): void {
        $gallery_ids_raw = get_post_meta($post->ID, SocialPostCPT::META_GALLERY_IDS, true);
        $gallery_ids     = array_filter(array_map('intval', explode(',', (string) $gallery_ids_raw)));

        if (empty($gallery_ids)) {
            echo '<p class="scps-muted">' . esc_html__('Aucune image dans la galerie pour cette publication.', 'social-posts-sync') . '</p>';
            return;
        }

        echo '<div class="scps-gallery-grid">';
        foreach ($gallery_ids as $attachment_id) {
            $thumb = wp_get_attachment_image($attachment_id, 'thumbnail', false, [
                'class' => 'scps-gallery-thumb',
            ]);

            if (!$thumb) {
                continue;
            }

            $full_url = wp_get_attachment_url($attachment_id);
            /* translators: %d: attachment ID number */
            $attachment_title = sprintf(__('Pièce jointe #%d', 'social-posts-sync'), $attachment_id);
            echo '<a href="' . esc_url((string) $full_url) . '" target="_blank" rel="noopener"'
                . ' title="' . esc_attr($attachment_title) . '">';
            echo wp_kses_post($thumb);
            echo '</a>';
        }
        echo '</div>';

        echo '<p class="scps-gallery-count">';
        printf(
            /* translators: %d: number of images */
            esc_html(_n('%d image', '%d images', count($gallery_ids), 'social-posts-sync')),
            count($gallery_ids)
        );
        echo '</p>';
    }
}
