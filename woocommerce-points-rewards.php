<?php
/**
 * Plugin Name: WooCommerce Points Rewards
 * Description: Plugin qui ajoute un système de points de fidélité pour WooCommerce.
 * Version: 1.0
 * Author: Elisée Desmarest
*/

if (!defined('ABSPATH')) {
	exit;
}

class WooCommercePointsRewards {

    public function __construct() {
        // Attribuer des points lorsque la commande a le statut "Terminée".
        add_action('woocommerce_order_status_completed', array($this, 'awardPoints'));

        /**
         * Afficher les points
         * Utiliser les points
         * Afficher l'historique des points
         * Expirer les points
         */
        add_action('woocommerce_account_dashboard', array($this, 'displayPoints'));

        // Interface d'administration : Réglages du plugin
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'wcp_rewards_register_settings'));
    }

    // Ajouter une page de réglage au menu d'administration
    public function menu() {
        add_menu_page(
            'Points Rewards',                    // Titre de la page
            'Points Rewards',                    // Titre du menu
            'manage_options',                    // Capacité requise pour voir le menu
            'points-rewards',                    // Slug de la page
            array($this, 'menuPage'),            // Fonction de callback pour afficher le contenu
            'dashicons-tickets',                 // Icône du menu (dashicons)
            50                                   // Position dans le menu
        );
    }

    // Enregistrer les options
    public function wcp_rewards_register_settings() {
         // Enregistre les paramètres
        register_setting('wcp_rewards_options_group', 'points_conversion_rate');
        register_setting('wcp_rewards_options_group', 'points_expiration_duration');
    }

    // Affiche la page du menu du plugin dans l'interface d'administration
    public function menuPage() {
        ?>
        <div class="wrap">
            <h1>Bienvenue dans WooCommerce Points Rewards</h1>
            <p>Ceci est une page de contenu personnalisée pour votre plugin qui ajoute un système de points de fidélité pour WooCommerce.</p>
            <form method="post" action="options.php">
                <?php
                // Génère les champs de paramètres pour la validation
                settings_fields('wcp_rewards_options_group');
                // Affiche les sections de paramètres associées à la page
                do_settings_sections('points-rewards');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Taux de conversion des points', 'woocommerce'); ?></th>
                        <td>
                            <input type="number" name="points_conversion_rate" value="<?php echo esc_attr(get_option('points_conversion_rate', 1)); ?>" step="0.01" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Durée d\'expiration des points (mois)', 'woocommerce'); ?></th>
                        <td>
                            <input type="number" name="points_expiration_duration" value="<?php echo esc_attr(get_option('points_expiration_duration', 12)); ?>" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function sendNotification(int $userId, string $title, string $content) {
        $userInfo = get_userdata($userId);
        $to = $userInfo->user_email;
        $subject = __($title, 'woocommerce');
        $message = __($content, 'woocommerce');
        wp_mail($to, $subject, $message);
    }

    private function expirePoints(int $userId): void 
    {
        $currentPoints = (int) get_user_meta($userId, 'reward_points', true);
        
        if ($currentPoints === 0) {
            return;
        }

        $lastUpdate = (int) get_user_meta($userId, 'last_points_update', true);
        $currentTime = current_time('timestamp');

        // Récupérer la durée d'expiration en mois depuis les options (par défaut à 12 mois)
        $pointsExpirationDuration = (int) get_option('points_expiration_duration', 12);
        $halfPointsExpirationDuration = (int) ($pointsExpirationDuration / 2);

        $expirationDurationMonths = $pointsExpirationDuration * 30 * 24 * 60 * 60; // Environ 30 jours par mois
        // $expirationDurationMonths = 5 * 60; // 5 min for testing
        $halfExpirationDurationMonths = $expirationDurationMonths / 2;

        // Vérification de la validité de lastUpdate
        if (!$lastUpdate) {
            return;
        }

        // Vérifier si plus de 6 mois se sont écoulés depuis la dernière utilisation des points
        $notified = get_user_meta($userId, 'points_half_expiry_notified', true);
        if (($currentTime - $lastUpdate) > $halfExpirationDurationMonths && ($currentTime - $lastUpdate) <= $expirationDurationMonths && !$notified) {
            // Envoyer une notification par e-mail pour avertir de l'expiration imminente
            $this->sendNotification(
                $userId,
                'Vos points de fidélité approchent de l’expiration',
                "Il vous reste {$halfPointsExpirationDuration} mois pour utiliser vos {$currentPoints} points de fidélité avant qu'ils n'expirent. Profitez-en pour bénéficier de remises sur vos prochains achats."
            );
            // Marquer comme notifié
            update_user_meta($userId, 'points_half_expiry_notified', true);
        }

        // Si plus de 12 mois se sont écoulés depuis la dernière utilisation des points
        if (($currentTime - $lastUpdate) > $expirationDurationMonths) {
            // Réinitialiser les points à zéro
            update_user_meta($userId, 'reward_points', 0);

            // Supprimer le champ last_points_update et le statut de notification
            delete_user_meta($userId, 'last_points_update');
            delete_user_meta($userId, 'points_half_expiry_notified');

            // Ajouter l'entrée dans l'historique pour indiquer l'expiration
            $this->addPointsHistory($userId, -$currentPoints, "Points expirés après {$pointsExpirationDuration} mois d'inactivité");

            // Envoyer une notification par e-mail
            $this->sendNotification(
                $userId, 
                'Expiration de vos points de fidélité', 
                "Vos {$currentPoints} points de fidélité ont expirés après {$pointsExpirationDuration} mois d'inactivité. N'hésitez pas à continuer vos achats pour accumuler de nouveaux points et pouvoir bénéficier de coupons."
            );
        }
    }

    private function createCoupon(int $discountPercentage, int $pointsRequired): void 
    {
        $userId = get_current_user_id();
        $currentPoints = (int) get_user_meta($userId, 'reward_points', true);

        // Vérifier si l'utilisateur a suffisamment de points
        if ($currentPoints < $pointsRequired) {
            wc_add_notice('Vous n\'avez pas assez de points pour ce coupon.', 'error');
            return;
        }

        // Créer le coupon WooCommerce
        $couponCode = 'DISCOUNT_' . strtoupper(wp_generate_password(8, false));
        $coupon = [
            'post_title'   => $couponCode,
            'post_content' => '',
            'post_status'  => 'publish',
            'post_author'  => $userId,
            'post_type'    => 'shop_coupon',
        ];

        $newCouponId = wp_insert_post($coupon);

        // Vérifier si le coupon a bien été créé
        if (is_wp_error($newCouponId)) {
            wc_add_notice('Erreur lors de la création du coupon : ' . $newCouponId->get_error_message(), 'error');
            return;
        }

        // Ajouter les données au coupon
        update_post_meta($newCouponId, 'discount_type', 'percent');
        update_post_meta($newCouponId, 'coupon_amount', $discountPercentage);
        update_post_meta($newCouponId, 'individual_use', 'yes');
        update_post_meta($newCouponId, 'usage_limit', '1');
        update_post_meta($newCouponId, 'expiry_date', date('Y-m-d', strtotime('+30 days')));

        // Déduire les points de l'utilisateur
        $newPoints = $currentPoints - $pointsRequired;
        update_user_meta($userId, 'reward_points', $newPoints);
        update_user_meta($userId, 'last_points_update', current_time('timestamp'));

        // Ajouter l'entrée dans l'historique
        $this->addPointsHistory($userId, -$pointsRequired, "Points dépensés pour le coupon de {$discountPercentage}% ({$couponCode})");

        // Informer l'utilisateur de la création du coupon
        wc_add_notice("Votre coupon de {$discountPercentage}% a été créé avec succès : {$couponCode}.", 'success');

        // Envoyer une notification par e-mail
        $this->sendNotification(
            $userId, 
            'Félicitations! Vous avez crée un coupon de remise!', 
            "Vous venez de dépenser -{$pointsRequired} points de fidélité pour le coupon de {$discountPercentage} (Code : {$couponCode}). Continuez à acheter pour bénéficier de remises."
        );
    }

    private function addPointsHistory(int $userId, int $points, string $reason): void 
    {
        // Récupérer l'historique existant de l'utilisateur
        $history = get_user_meta($userId, 'points_history', true) ?: [];

        // Ajouter la nouvelle entrée d'historique
        $history[] = [
            'date' => current_time('mysql'),
            'points' => $points,
            'reason' => $reason
        ];

        // Mettre à jour l'historique de l'utilisateur
        update_user_meta($userId, 'points_history', $history);
    }

    public function awardPoints(int $orderId): void 
    {
        $order = wc_get_order($orderId);
        $userId = $order->get_user_id();

        // Calculer le nombre de points à attribuer (par exemple 1 point par euro dépensé)
        $pointsConversionRate = get_option('points_conversion_rate', 1);
        $pointsToAward = intval($order->get_total() * $pointsConversionRate);

        // Récupérer les points actuels de l'utilisateur et ajouter les nouveaux points
        $currentPoints = get_user_meta($userId, 'reward_points', true) ?: 0;
        $newPoints = $currentPoints + $pointsToAward;

        // Mettre à jour les points de l'utilisateur
        update_user_meta($userId, 'reward_points', $newPoints);

        // Ajouter l'entrée dans l'historique
        $this->addPointsHistory($userId, $pointsToAward, "Points gagnés pour la commande N°{$order->get_id()}");

        // Envoyer une notification par e-mail
        $this->sendNotification(
            $userId, 
            'Félicitations! Vous avez gagné des points!', 
            "Vous avez gagné {$pointsToAward} points de fidélité! Continuez à acheter pour en gagner plus."
        );
    }

    public function displayPoints(): void 
    {
        $userId = get_current_user_id();

        // Vérifier si les points doivent expirer
        $this->expirePoints($userId);

        $pointsConversionRate = get_option('points_conversion_rate', 1);
        // Calculez combien d'euros sont nécessaires pour obtenir 1 point
        $eurosPerPoint = 1 / $pointsConversionRate; // 1 point = X euros

        $points = (int) get_user_meta($userId, 'reward_points', true);
        
        $history = get_user_meta($userId, 'points_history', true) ?: [];
        $lastFiveEntries = array_slice($history, -10);
        ?>

        <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
            <h3>Vos Points de Fidélité</h3>
            <p>Vous avez actuellement <strong><?= esc_attr($points) ?> points</strong>.</p>
        </div>

        <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
            <h3 style="color: #333;">Cumulez des points en commandant sur le site</h3>
            <ul style="list-style: none; padding-left: 0;">
                <li style="margin-bottom: 10px;"><strong>1 point</strong> = <strong><?= esc_attr($eurosPerPoint) ?>€ dépensé(s)</strong></li>
                <li style="margin-bottom: 10px;"><strong>100 points</strong> = <strong>10% de remise</strong></li>
                <li style="margin-bottom: 10px;"><strong>200 points</strong> = <strong>20% de remise</strong></li>
                <li style="margin-bottom: 10px;"><strong>400 points</strong> = <strong>40% de remise</strong></li>
            </ul>
        </div>

        <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
            <h3>Convertir vos points en coupon</h3>
            <p>Vos coupons seront valides pendant une durée de 30 jours à compter de la date de création. Assurez-vous de les utiliser avant leur expiration pour profiter de vos réductions. Après cette période, les coupons ne seront plus utilisables.</p>
            <form method="post">
                <label for="discount_percentage">Choisissez le pourcentage de remise :</label>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <select name="discount_percentage" id="discount_percentage" style="padding: 5px; flex: 1;" onchange="toggleButton()">
                        <option value="">Choisissez une option</option>
                        <option value="10" <?= $points < 100 ? 'disabled' : '' ?>>10% (100 points)</option>
                        <option value="20" <?= $points < 200 ? 'disabled' : '' ?>>20% (200 points)</option>
                        <option value="40" <?= $points < 400 ? 'disabled' : '' ?>>40% (400 points)</option>
                    </select>
                    <button type="submit" id="create_coupon" name="create_coupon" style="padding: 13.5px 20px; background-color: #0071a1; color: #fff; border: none; cursor: pointer;" disabled>
                        Créer un coupon
                    </button>
                </div>

                <script>
                    function toggleButton() {
                        const select = document.getElementById('discount_percentage');
                        const button = document.getElementById('create_coupon');
                        button.disabled = select.value === "";
                    }

                    // Appel initial pour désactiver le bouton si aucune option n'est sélectionnée au chargement de la page
                    toggleButton();
                </script>
            </form>
        </div>
        
        <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
            <h3>Historique de vos points</h3>
            <?php if (!empty($history)): ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #EEE">
                        <tr>
                            <th style="text-align: left; padding: 8px; border-bottom: 1px solid #EEE; font-weight: bold;">
                                Date
                            </th>
                            <th style="text-align: left; padding: 8px; border-bottom: 1px solid #EEE; font-weight: bold;">
                                Points
                            </th>
                            <th style="text-align: left; padding: 8px; border-bottom: 1px solid #EEE; font-weight: bold;">
                                Raison 
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($lastFiveEntries) as $entry): ?>
                            <?php
                                $date = new DateTime($entry['date']);
                                $formattedDate = $date->format('d/m/Y');
                            ?>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #EEE;"><?= $formattedDate ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #EEE;">
                                    <strong><?= $entry['points'] ?></strong></td>
                                <td style="padding: 8px; border-bottom: 1px solid #EEE;"><?= $entry['reason'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Aucun historique disponible.</p>
            <?php endif; ?>
        </div>

        <?php

        // Traitement du formulaire pour créer un coupon
        if (isset($_POST['create_coupon'])) {
            $discountPercentage = (int) sanitize_text_field($_POST['discount_percentage']);
            $pointsRequired = 0;

            switch ($discountPercentage) {
                case 10:
                    $pointsRequired = 100;
                    break;
                case 20:
                    $pointsRequired = 200;
                    break;
                case 30:
                    $pointsRequired = 300;
                    break;
                default:
                    wc_add_notice('Pourcentage de remise invalide.', 'error');
            }

            if ($pointsRequired != 0) {
                $this->createCoupon($discountPercentage, $pointsRequired);
            }

            // Redirection JavaScript pour recharger la page après
            echo '<script type="text/javascript">
                    window.location.replace("http://wp-2.local/my-account/");
                </script>';
        }
    }
}

add_action('plugins_loaded', function(){
    new WooCommercePointsRewards();
});
