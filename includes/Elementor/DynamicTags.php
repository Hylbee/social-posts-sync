<?php
/**
 * Elementor Dynamic Tags — Social Posts Sync
 *
 * Registers the plugin's tag group and all individual dynamic tags
 * with the Elementor dynamic tags manager.
 *
 * Tags registered:
 *  - ScpsPermalinkTag   → URL (link controls)
 *  - ScpsPlatformTag    → Text (platform name)
 *  - ScpsPublishedAtTag → Text (formatted publish date)
 *  - ScpsAuthorNameTag  → Text (page / account name)
 *  - ScpsAuthorAvatarTag → Image (avatar URL)
 *  - ScpsLikesCountTag  → Text / Number (likes count)
 *  - ScpsVideoUrlTag    → URL (video URL)
 *  - ScpsGalleryTag    → Gallery (all post images)
 *
 * @package SocialPostsSync\Elementor
 */

declare(strict_types=1);

namespace SocialPostsSync\Elementor;

defined('ABSPATH') || exit;

use SocialPostsSync\Elementor\Tags\ScpsPermalinkTag;
use SocialPostsSync\Elementor\Tags\ScpsPlatformTag;
use SocialPostsSync\Elementor\Tags\ScpsPublishedAtTag;
use SocialPostsSync\Elementor\Tags\ScpsAuthorNameTag;
use SocialPostsSync\Elementor\Tags\ScpsAuthorAvatarTag;
use SocialPostsSync\Elementor\Tags\ScpsLikesCountTag;
use SocialPostsSync\Elementor\Tags\ScpsVideoUrlTag;
use SocialPostsSync\Elementor\Tags\ScpsGalleryTag;

/**
 * Bootstraps all dynamic tag registrations.
 */
class DynamicTags {

    /**
     * The Elementor dynamic-tags group name for all SCPS tags.
     */
    public const GROUP = 'social-posts-sync';

    /**
     * Register the custom group and all tags with the Elementor manager.
     *
     * @param \Elementor\Core\DynamicTags\Manager $manager Elementor dynamic tags manager.
     */
    public function register(\Elementor\Core\DynamicTags\Manager $manager): void {
        $manager->register_group(self::GROUP, [
            'title' => __('Social Posts Sync', 'social-posts-sync'),
        ]);

        $tags = [
            ScpsPermalinkTag::class,
            ScpsPlatformTag::class,
            ScpsPublishedAtTag::class,
            ScpsAuthorNameTag::class,
            ScpsAuthorAvatarTag::class,
            ScpsLikesCountTag::class,
            ScpsVideoUrlTag::class,
            ScpsGalleryTag::class,
        ];

        foreach ($tags as $tag_class) {
            $manager->register(new $tag_class());
        }
    }
}
