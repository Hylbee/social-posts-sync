<?php
/**
 * Admin Settings Tab — API Configuration
 *
 * @package SocialPostsSync\Admin\Tabs
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin\Tabs;

defined('ABSPATH') || exit;

use SocialPostsSync\Auth\MetaOAuth;

class ApiTab {

    private MetaOAuth $oauth;

    public function __construct(MetaOAuth $oauth) {
        $this->oauth = $oauth;
    }

    public function render(): void {
        $app_id       = esc_attr((string) get_option('scps_meta_app_id', ''));
        $is_connected = $this->oauth->isConnected();
        $account_name = $this->oauth->getAccountName();
        $expires_date = $this->oauth->getTokenExpiryDate();
        $auth_url     = $this->oauth->getAuthorizationUrl();
        ?>
        <div class="scps-card">
            <h2><?php esc_html_e('Identifiants de l\'application Meta', 'social-posts-sync'); ?></h2>
            <p class="description">
                <?php esc_html_e('Créez une application sur developers.facebook.com et renseignez vos identifiants ci-dessous.', 'social-posts-sync'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="scps_save_api_settings">
                <?php wp_nonce_field('scps_save_api_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="scps_meta_app_id"><?php esc_html_e('App ID', 'social-posts-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="scps_meta_app_id" name="scps_meta_app_id"
                                   value="<?php echo esc_attr($app_id); ?>"
                                   class="regular-text" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="scps_meta_app_secret"><?php esc_html_e('App Secret', 'social-posts-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="scps_meta_app_secret" name="scps_meta_app_secret"
                                   value="" placeholder="<?php esc_attr_e('Laisser vide pour conserver l\'actuel', 'social-posts-sync'); ?>"
                                   class="regular-text" autocomplete="new-password">
                            <p class="description">
                                <?php esc_html_e('Stocké de façon chiffrée (AES-256-CBC).', 'social-posts-sync'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Enregistrer les identifiants', 'social-posts-sync')); ?>
            </form>
        </div>

        <div class="scps-card scps-card--spaced">
            <h2><?php esc_html_e('Connexion Meta', 'social-posts-sync'); ?></h2>

            <?php if ($is_connected) : ?>
                <div class="scps-connection-status scps-connected">
                    <span class="dashicons dashicons-yes-alt scps-status-icon--ok"></span>
                    <strong><?php esc_html_e('Connecté', 'social-posts-sync'); ?></strong>
                    <?php if ($account_name) : ?>
                        &mdash; <?php echo esc_html($account_name); ?>
                    <?php endif; ?>
                    <?php if ($expires_date) : ?>
                        <br><small>
                            <?php
                            printf(
                                /* translators: %s: Token expiry date */
                                esc_html__('Token valide jusqu\'au : %s', 'social-posts-sync'),
                                esc_html($expires_date)
                            );
                            ?>
                        </small>
                    <?php endif; ?>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="scps-connect-action">
                    <input type="hidden" name="action" value="scps_disconnect">
                    <?php wp_nonce_field('scps_disconnect'); ?>
                    <?php submit_button(__('Déconnecter', 'social-posts-sync'), 'secondary', 'submit', false); ?>
                </form>

            <?php else : ?>
                <div class="scps-connection-status scps-disconnected">
                    <span class="dashicons dashicons-warning scps-status-icon--error"></span>
                    <strong><?php esc_html_e('Non connecté', 'social-posts-sync'); ?></strong>
                </div>

                <?php if ($app_id && get_option('scps_meta_app_secret', '')) : ?>
                    <p class="scps-connect-action">
                        <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">
                            <span class="dashicons dashicons-facebook scps-btn-icon"></span>
                            <?php esc_html_e('Se connecter avec Facebook', 'social-posts-sync'); ?>
                        </a>
                    </p>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: Redirect URI */
                            esc_html__('URI de redirection à enregistrer dans votre App Meta : %s', 'social-posts-sync'),
                            '<code>' . esc_html($this->oauth->getRedirectUri()) . '</code>'
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p class="description">
                        <?php esc_html_e('Veuillez d\'abord enregistrer votre App ID et App Secret.', 'social-posts-sync'); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
