<?php
/**
 * Elementor Dynamic Tag — Video URL
 *
 * @package SocialPostsSync\Elementor\Tags
 */

declare(strict_types=1);

namespace SocialPostsSync\Elementor\Tags;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Dynamic tag returning the video URL of a social post.
 * Usable in any URL / link control in Elementor (e.g. Video widget source).
 */
class ScpsVideoUrlTag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'scps-video-url';
    }

    public function get_title(): string {
        return __('URL de la vidéo', 'social-posts-sync');
    }

    public function get_group(): string {
        return \SocialPostsSync\Elementor\DynamicTags::GROUP;
    }

    public function get_categories(): array {
        return [
            \Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
            \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
        ];
    }

    public function render(): void {
        $value = (string) get_post_meta(get_the_ID(), SocialPostCPT::META_VIDEO_URL, true);
        echo esc_url($value);
    }
}
