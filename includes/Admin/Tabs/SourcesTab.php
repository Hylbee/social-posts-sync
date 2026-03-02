<?php
/**
 * Admin Settings Tab — Sources
 *
 * @package SocialPostsSync\Admin\Tabs
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin\Tabs;

defined('ABSPATH') || exit;

use SocialPostsSync\Auth\MetaOAuth;

class SourcesTab {

    private MetaOAuth $oauth;

    public function __construct(MetaOAuth $oauth) {
        $this->oauth = $oauth;
    }

    public function render(): void {
        $is_connected    = $this->oauth->isConnected();
        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        if (!is_array($enabled_sources)) {
            $enabled_sources = ['facebook' => [], 'instagram' => []];
        }
        ?>
        <div class="scps-card">
            <h2><?php esc_html_e('Sources à synchroniser', 'social-posts-sync'); ?></h2>

            <?php if (!$is_connected) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e('Vous devez être connecté à Meta pour gérer les sources.', 'social-posts-sync'); ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'social-posts-sync', 'tab' => 'api'], admin_url('options-general.php'))); ?>">
                            <?php esc_html_e('Configurer la connexion', 'social-posts-sync'); ?>
                        </a>
                    </p>
                </div>
            <?php else : ?>

                <?php /* Bloc ajout d'une nouvelle source */ ?>
                <div id="scps-add-source">
                    <h3><?php esc_html_e('Ajouter une source', 'social-posts-sync'); ?></h3>

                    <?php /* Option 1 : importer depuis le compte connecté */ ?>
                    <p>
                        <button type="button" id="scps-load-sources" class="button">
                            <?php esc_html_e('Importer depuis mon compte', 'social-posts-sync'); ?>
                        </button>
                        <span id="scps-sources-loading" style="display:none;">
                            <span class="spinner is-active scps-spinner-inline"></span>
                            <?php esc_html_e('Chargement…', 'social-posts-sync'); ?>
                        </span>
                    </p>
                    <div id="scps-import-result"></div>

                    <p class="description" style="margin-top:1em;">
                        <?php esc_html_e('Ou entrez manuellement un ID de Page Facebook publique (ex : 123456789) ou un @username Instagram Business (ex : @moncompte) :', 'social-posts-sync'); ?>
                    </p>
                    <div class="scps-add-source-row">
                        <input type="text"
                               id="scps-source-identifier"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('ID ou @username', 'social-posts-sync'); ?>"
                               autocomplete="off">
                        <button type="button" id="scps-validate-source" class="button">
                            <?php esc_html_e('Valider', 'social-posts-sync'); ?>
                        </button>
                        <span id="scps-validate-loading" style="display:none;">
                            <span class="spinner is-active scps-spinner-inline"></span>
                        </span>
                    </div>
                    <div id="scps-validate-result"></div>
                </div>

                <hr>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="scps-sources-form">
                    <input type="hidden" name="action" value="scps_save_sources">
                    <?php wp_nonce_field('scps_save_sources'); ?>

                    <h3><?php esc_html_e('Sources configurées', 'social-posts-sync'); ?></h3>

                    <div id="scps-sources-list">
                        <?php $this->renderSavedSources($enabled_sources); ?>
                    </div>

                    <?php submit_button(__('Enregistrer les sources', 'social-posts-sync')); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the currently saved sources as a list with remove buttons.
     *
     * @param array $enabled_sources Currently enabled sources.
     */
    public function renderSavedSources(array $enabled_sources): void {
        $fb_sources = $enabled_sources['facebook']  ?? [];
        $ig_sources = $enabled_sources['instagram'] ?? [];

        if (empty($fb_sources) && empty($ig_sources)) {
            echo '<p class="description">';
            esc_html_e('Aucune source configurée. Utilisez le formulaire ci-dessus pour en ajouter.', 'social-posts-sync');
            echo '</p>';
            return;
        }

        if (!empty($fb_sources)) {
            echo '<h4>' . esc_html__('Pages Facebook', 'social-posts-sync') . '</h4>';
            echo '<ul class="scps-source-list">';
            foreach ($fb_sources as $source) {
                $page_id = is_array($source) ? (string) ($source['id']   ?? '') : (string) $source;
                $name    = is_array($source) ? (string) ($source['name'] ?? '') : '';
                if ($page_id === '') continue;
                echo '<li'
                    . ' data-platform="facebook"'
                    . ' data-id="'   . esc_attr($page_id) . '"'
                    . ' data-name="' . esc_attr($name)    . '">';
                if ($name) {
                    echo '<strong>' . esc_html($name) . '</strong> <small class="scps-muted">(' . esc_html($page_id) . ')</small>';
                } else {
                    echo '<strong>' . esc_html($page_id) . '</strong>';
                }
                echo ' <button type="button" class="button button-small scps-remove-source">'
                    . esc_html__('Supprimer', 'social-posts-sync')
                    . '</button>';
                echo '</li>';
            }
            echo '</ul>';
        }

        if (!empty($ig_sources)) {
            echo '<h4>' . esc_html__('Comptes Instagram', 'social-posts-sync') . '</h4>';
            echo '<ul class="scps-source-list">';
            foreach ($ig_sources as $source) {
                $ig_id = is_array($source) ? (string) ($source['id']   ?? '') : (string) $source;
                $name  = is_array($source) ? (string) ($source['name'] ?? '') : '';
                if ($ig_id === '') continue;
                echo '<li'
                    . ' data-platform="instagram"'
                    . ' data-id="'   . esc_attr($ig_id) . '"'
                    . ' data-name="' . esc_attr($name)  . '">';
                echo '<span class="dashicons dashicons-instagram scps-inline-icon"></span>';
                if ($name) {
                    echo '<strong>' . esc_html($name) . '</strong> <small class="scps-muted">@' . esc_html($ig_id) . '</small>';
                } else {
                    echo '<strong>@' . esc_html($ig_id) . '</strong>';
                }
                echo ' <button type="button" class="button button-small scps-remove-source">'
                    . esc_html__('Supprimer', 'social-posts-sync')
                    . '</button>';
                echo '</li>';
            }
            echo '</ul>';
        }
    }
}
