<?php
/**
 * Elementor Dynamic Tag — Likes Count
 *
 * @package SocialPostsSync\Elementor\Tags
 */

declare(strict_types=1);

namespace SocialPostsSync\Elementor\Tags;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Dynamic tag returning the likes count as a number.
 */
class ScpsLikesCountTag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'scps-likes-count';
    }

    public function get_title(): string {
        return __('Nombre de likes', 'social-posts-sync');
    }

    public function get_group(): string {
        return \SocialPostsSync\Elementor\DynamicTags::GROUP;
    }

    public function get_categories(): array {
        return [
            \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
            \Elementor\Modules\DynamicTags\Module::NUMBER_CATEGORY,
        ];
    }

    public function render(): void {
        $value = (int) get_post_meta(get_the_ID(), SocialPostCPT::META_LIKES_COUNT, true);
        echo esc_html((string) $value);
    }
}
