<?php
/**
 * Elementor Dynamic Tag — Post Gallery
 *
 * @package SocialPostsSync\Elementor\Tags
 */

declare(strict_types=1);

namespace SocialPostsSync\Elementor\Tags;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Dynamic tag returning all sideloaded image IDs as a gallery.
 * Usable in the Elementor Gallery widget.
 */
class ScpsGalleryTag extends \Elementor\Core\DynamicTags\Data_Tag {

    public function get_name(): string {
        return 'scps-gallery';
    }

    public function get_title(): string {
        return __('Images de la publication', 'social-posts-sync');
    }

    public function get_group(): string {
        return \SocialPostsSync\Elementor\DynamicTags::GROUP;
    }

    public function get_categories(): array {
        return [
            \Elementor\Modules\DynamicTags\Module::GALLERY_CATEGORY,
        ];
    }

    /**
     * Returns the gallery as an array of ['id' => int, 'url' => string] items,
     * which is the format expected by Elementor's Gallery control.
     *
     * @param array $options
     * @return array
     */
    public function get_value(array $options = []): array {
        $raw = get_post_meta(get_the_ID(), SocialPostCPT::META_GALLERY_IDS, true);
        if (!$raw) {
            return [];
        }

        $ids = array_filter(array_map('intval', explode(',', (string) $raw)));
        if (empty($ids)) {
            return [];
        }

        $gallery = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $url = wp_get_attachment_url($id);
            if (!$url) {
                continue;
            }
            $gallery[] = [
                'id'  => $id,
                'url' => $url,
            ];
        }

        return $gallery;
    }
}
