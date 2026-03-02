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
        $licence_key  = $this->oauth->getLicenceKey();
        $is_connected = $this->oauth->isConnected();
        $account_name = $this->oauth->getAccountName();
        $expires_date = $this->oauth->getTokenExpiryDate();
        $auth_url     = $this->oauth->getAuthorizationUrl();
        ?>
        <div class="scps-card">
            <h2><?php esc_html_e('Licence Social Posts Sync', 'social-posts-sync'); ?></h2>
            <p class="description">
                <?php esc_html_e('Renseignez votre clé de licence pour activer la connexion Facebook via notre serveur OAuth.', 'social-posts-sync'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="scps_save_api_settings">
                <?php wp_nonce_field('scps_save_api_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="scps_licence_key"><?php esc_html_e('Clé de licence', 'social-posts-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="scps_licence_key" name="scps_licence_key"
                                   value="<?php echo $licence_key ? '********************' : ''; ?>"
                                   placeholder="<?php esc_attr_e('Laisser vide pour conserver l\'actuelle', 'social-posts-sync'); ?>"
                                   class="regular-text" autocomplete="new-password"
                                   onfocus="if(this.value==='********************')this.value='';"
                                   onblur="if(this.value==='')this.value=<?php echo $licence_key ? '\'********************\'' : '\'\''; ?>;">
                            <?php if ($licence_key) : ?>
                                <p class="description" style="color:#46b450;">
                                    <span class="dashicons dashicons-yes" style="vertical-align:middle;"></span>
                                    <?php esc_html_e('Clé de licence enregistrée et valide.', 'social-posts-sync'); ?>
                                </p>
                            <?php else : ?>
                                <p class="description" style="color:#dc3232;">
                                    <span class="dashicons dashicons-warning" style="vertical-align:middle;"></span>
                                    <?php esc_html_e('Aucune clé de licence enregistrée.', 'social-posts-sync'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Enregistrer la licence', 'social-posts-sync')); ?>
            </form>
        </div>

        <div class="scps-card scps-card--spaced">
            <h2><?php esc_html_e('Connexion Facebook', 'social-posts-sync'); ?></h2>

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

                <?php if ($licence_key) : ?>
                    <p class="scps-connect-action">
                        <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">
                            <span class="dashicons dashicons-facebook scps-btn-icon"></span>
                            <?php esc_html_e('Se connecter avec Facebook', 'social-posts-sync'); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p class="description">
                        <?php esc_html_e('Veuillez d\'abord enregistrer votre clé de licence.', 'social-posts-sync'); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
