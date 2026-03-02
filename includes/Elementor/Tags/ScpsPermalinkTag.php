<?php
/**
 * Elementor Dynamic Tag — Permalink
 *
 * @package SocialPostsSync\Elementor\Tags
 */

declare(strict_types=1);

namespace SocialPostsSync\Elementor\Tags;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Dynamic tag returning the original social post URL.
 * Usable in any URL / link control in Elementor.
 */
class ScpsPermalinkTag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'scps-permalink';
    }

    public function get_title(): string {
        return __('Lien du post original', 'social-posts-sync');
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
        $value = (string) get_post_meta(get_the_ID(), SocialPostCPT::META_PERMALINK, true);
        echo esc_url($value);
    }
}
