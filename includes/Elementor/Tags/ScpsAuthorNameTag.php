<?php
/**
 * Elementor Dynamic Tag — Author Name
 *
 * @package SocialPostsSync\Elementor\Tags
 */

declare(strict_types=1);

namespace SocialPostsSync\Elementor\Tags;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Dynamic tag returning the social account / page name.
 */
class ScpsAuthorNameTag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'scps-author-name';
    }

    public function get_title(): string {
        return __('Nom de la page / compte', 'social-posts-sync');
    }

    public function get_group(): string {
        return \SocialPostsSync\Elementor\DynamicTags::GROUP;
    }

    public function get_categories(): array {
        return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];
    }

    public function render(): void {
        $value = (string) get_post_meta(get_the_ID(), SocialPostCPT::META_AUTHOR_NAME, true);
        echo esc_html($value);
    }
}
