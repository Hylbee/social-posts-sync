<?php
/**
 * Elementor Dynamic Tag — Published At
 *
 * @package SocialPostsSync\Elementor\Tags
 */

declare(strict_types=1);

namespace SocialPostsSync\Elementor\Tags;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Dynamic tag returning the original publication date, formatted for display.
 * An optional format parameter lets the user override the date format.
 */
class ScpsPublishedAtTag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'scps-published-at';
    }

    public function get_title(): string {
        return __('Date de publication originale', 'social-posts-sync');
    }

    public function get_group(): string {
        return \SocialPostsSync\Elementor\DynamicTags::GROUP;
    }

    public function get_categories(): array {
        return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];
    }

    protected function register_controls(): void {
        $this->add_control('date_format', [
            'label'       => __('Format de date', 'social-posts-sync'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'd/m/Y',
            'description' => __('Format PHP (ex: d/m/Y, Y-m-d, j F Y…)', 'social-posts-sync'),
        ]);
    }

    public function render(): void {
        $iso    = (string) get_post_meta(get_the_ID(), SocialPostCPT::META_PUBLISHED_AT, true);
        $format = (string) $this->get_settings('date_format') ?: 'd/m/Y';

        if (!$iso) {
            return;
        }

        try {
            $dt = new \DateTimeImmutable($iso);
            echo esc_html($dt->format($format));
        } catch (\Throwable) {
            echo esc_html($iso);
        }
    }
}
