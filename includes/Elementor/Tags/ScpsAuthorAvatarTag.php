<?php
/**
 * Elementor Dynamic Tag — Author Avatar
 *
 * @package SocialPostsSync\Elementor\Tags
 */

declare(strict_types=1);

namespace SocialPostsSync\Elementor\Tags;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Dynamic tag returning the social account avatar as an image URL.
 * Usable in any Image control in Elementor.
 */
class ScpsAuthorAvatarTag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'scps-author-avatar';
    }

    public function get_title(): string {
        return __('Avatar de la page / compte', 'social-posts-sync');
    }

    public function get_group(): string {
        return \SocialPostsSync\Elementor\DynamicTags::GROUP;
    }

    public function get_categories(): array {
        return [
            \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY,
            \Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
        ];
    }

    public function render(): void {
        $url = (string) get_post_meta(get_the_ID(), SocialPostCPT::META_AUTHOR_AVATAR, true);
        if (!$url) {
            return;
        }
        // For IMAGE_CATEGORY Elementor expects an array with 'id' and 'url'
        $this->print_panel_template();
        echo wp_json_encode(['id' => '', 'url' => esc_url_raw($url)]);
    }

    /**
     * Elementor image tags must return an array via get_value().
     */
    public function get_value(array $options = []): array {
        $url = (string) get_post_meta(get_the_ID(), SocialPostCPT::META_AUTHOR_AVATAR, true);
        return [
            'id'  => '',
            'url' => esc_url_raw($url),
        ];
    }
}
