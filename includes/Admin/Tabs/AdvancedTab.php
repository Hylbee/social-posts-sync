<?php
/**
 * Admin Settings Tab — Advanced
 *
 * @package SocialPostsSync\Admin\Tabs
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin\Tabs;

defined('ABSPATH') || exit;

class AdvancedTab {

    public function render(): void {
        ?>
        <div class="scps-card">
            <h2><?php esc_html_e('Paramètres avancés', 'social-posts-sync'); ?></h2>
            <p class="description"><?php esc_html_e('Ces actions sont irréversibles. Utilisez-les avec précaution.', 'social-posts-sync'); ?></p>
        </div>

        <div class="scps-card scps-card--spaced">
            <h2><?php esc_html_e('Permaliens', 'social-posts-sync'); ?></h2>

            <div class="notice notice-warning inline scps-warning-notice">
                <p>
                    <strong><?php esc_html_e('Attention :', 'social-posts-sync'); ?></strong>
                    <?php esc_html_e('Modifier le slug de base change l\'URL de toutes vos publications sociales. Les anciennes URLs cesseront de fonctionner et vos liens Elementor pourraient se briser. Videz le cache de votre site après la modification.', 'social-posts-sync'); ?>
                </p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="scps_save_advanced">
                <?php wp_nonce_field('scps_save_advanced'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="scps_cpt_slug"><?php esc_html_e('Slug de base des publications', 'social-posts-sync'); ?></label>
                        </th>
                        <td>
                            <code><?php echo esc_html(trailingslashit(home_url())); ?></code><input
                                type="text"
                                id="scps_cpt_slug"
                                name="scps_cpt_slug"
                                value="<?php echo esc_attr((string) get_option('scps_cpt_slug', 'social-posts')); ?>"
                                class="regular-text scps-slug-input"
                                placeholder="social-posts">
                            <p class="description">
                                <?php esc_html_e('Par défaut : social-posts. N\'utilisez que des lettres minuscules, chiffres et tirets.', 'social-posts-sync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="scps_sideload_timeout"><?php esc_html_e('Timeout import médias (secondes)', 'social-posts-sync'); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="scps_sideload_timeout"
                                name="scps_sideload_timeout"
                                value="<?php echo esc_attr((string) get_option('scps_sideload_timeout', 30)); ?>"
                                min="10"
                                max="120"
                                step="5"
                                class="small-text">
                            <p class="description">
                                <?php esc_html_e('Durée maximale (en secondes) pour télécharger un média lors de la synchronisation. Entre 10 et 120 s. Par défaut : 30 s.', 'social-posts-sync'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Enregistrer le slug', 'social-posts-sync')); ?>
            </form>
        </div>

        <div class="scps-danger-zone scps-card--spaced">
            <h2>
                <span class="dashicons dashicons-warning scps-danger-title-icon"></span>
                <?php esc_html_e('Zone de danger', 'social-posts-sync'); ?>
            </h2>

            <div class="scps-danger-action">
                <div class="scps-danger-action-info">
                    <strong><?php esc_html_e('Re-synchronisation complète', 'social-posts-sync'); ?></strong>
                    <p><?php esc_html_e('Supprime les dates de dernière synchro. La prochaine synchronisation récupèrera tous les posts depuis le début, sans toucher aux publications existantes.', 'social-posts-sync'); ?></p>
                </div>
                <div class="scps-danger-action-btn">
                    <button type="button" id="scps-reset-sync" class="button button--danger">
                        <span class="dashicons dashicons-image-rotate scps-btn-icon"></span>
                        <?php esc_html_e('Réinitialiser les timestamps', 'social-posts-sync'); ?>
                    </button>
                </div>
            </div>

            <div class="scps-danger-action">
                <div class="scps-danger-action-info">
                    <strong><?php esc_html_e('Supprimer toutes les publications synchronisées', 'social-posts-sync'); ?></strong>
                    <p><?php esc_html_e('Supprime définitivement tous les posts de type "social_post" et leurs médias associés. Les réglages et la connexion Meta sont conservés.', 'social-posts-sync'); ?></p>
                </div>
                <div class="scps-danger-action-btn">
                    <button type="button" id="scps-purge-posts" class="button button--danger">
                        <span class="dashicons dashicons-trash scps-btn-icon"></span>
                        <?php esc_html_e('Supprimer les publications', 'social-posts-sync'); ?>
                    </button>
                </div>
            </div>

            <div class="scps-danger-action">
                <div class="scps-danger-action-info">
                    <strong><?php esc_html_e('Réinitialisation complète du plugin', 'social-posts-sync'); ?></strong>
                    <p><?php esc_html_e('Supprime toutes les publications, tous les médias importés, la connexion Meta, les réglages et les timestamps. Remet le plugin à zéro.', 'social-posts-sync'); ?></p>
                </div>
                <div class="scps-danger-action-btn">
                    <button type="button" id="scps-purge-all" class="button button-link-delete">
                        <span class="dashicons dashicons-dismiss scps-btn-icon"></span>
                        <?php esc_html_e('Tout réinitialiser', 'social-posts-sync'); ?>
                    </button>
                </div>
            </div>

            <div id="scps-advanced-status"></div>
        </div>
        <?php
    }
}
