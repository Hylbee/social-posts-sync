<?php
/**
 * Admin asset loader.
 *
 * Enqueues the plugin's admin JS and CSS on the settings page and on the
 * social_post CPT edit screens.
 *
 * @package SocialPostsSync\Admin
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

class AssetLoader {

    /**
     * Register the admin_enqueue_scripts hook.
     */
    public function init(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Enqueue admin scripts and styles on relevant screens.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue(string $hook): void {
        global $post;

        $is_settings_page = str_contains($hook, 'social-posts-sync');
        $is_cpt_edit      = in_array($hook, ['post.php', 'post-new.php'], true)
            && isset($post)
            && $post->post_type === SocialPostCPT::POST_TYPE;

        if (!$is_settings_page && !$is_cpt_edit) {
            return;
        }

        wp_enqueue_script(
            'scps-admin',
            SCPS_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            SCPS_VERSION,
            true
        );

        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        if (!is_array($enabled_sources)) {
            $enabled_sources = ['facebook' => [], 'instagram' => []];
        }

        wp_localize_script('scps-admin', 'scpsAdmin', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('scps_admin_nonce'),
            'enabledSources' => [
                'facebook'  => scps_extract_sources($enabled_sources, 'facebook'),
                'instagram' => scps_extract_sources($enabled_sources, 'instagram'),
            ],
            'strings' => [
                'syncing'               => __('Synchronisation en cours…', 'social-posts-sync'),
                'syncDone'              => __('Synchronisation terminée.', 'social-posts-sync'),
                'syncError'             => __('Erreur lors de la synchronisation.', 'social-posts-sync'),
                'confirm'               => __('Êtes-vous sûr(e) ?', 'social-posts-sync'),
                'unlock'                => __('Déverrouiller', 'social-posts-sync'),
                'unlockDone'            => __('Verrou libéré. Vous pouvez relancer la synchronisation.', 'social-posts-sync'),
                'confirmReset'          => __('Cela va supprimer les dates de dernière synchro. La prochaine synchronisation récupèrera tous les posts depuis le début. Continuer ?', 'social-posts-sync'),
                'confirmPurgePosts'     => __('Supprimer définitivement toutes les publications synchronisées et leurs médias ? Cette action est irréversible.', 'social-posts-sync'),
                'confirmPurgeAll'       => __('Réinitialisation complète : toutes les publications, médias, réglages et la connexion Meta seront supprimés. Êtes-vous certain ?', 'social-posts-sync'),
                'confirmPurgeAllDouble' => __('Dernière confirmation : cette action est IRRÉVERSIBLE. Continuer ?', 'social-posts-sync'),
                'addSource'             => __('Ajouter à la liste', 'social-posts-sync'),
                'validating'            => __('Validation en cours…', 'social-posts-sync'),
                'validateError'         => __('Impossible de valider cette source.', 'social-posts-sync'),
            ],
        ]);

        wp_enqueue_style(
            'scps-admin-css',
            SCPS_PLUGIN_URL . 'assets/admin.css',
            [],
            SCPS_VERSION
        );
    }
}
