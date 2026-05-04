<?php
/*
Plugin Name: FFTT Club Tools
Description: Outils WordPress pour la gestion d'un club FFTT.
Version: 1.1.0
Author: Vincent Rousseau
Update URI: https://github.com/jarash/fftt-club-tools
Requires Plugins: advanced-custom-fields
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'FFTT_CLUB_TOOLS_VERSION', '1.1.0' );
define( 'FFTT_CLUB_TOOLS_PLUGIN_FILE', __FILE__ );
define( 'FFTT_CLUB_TOOLS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'FFTT_CLUB_TOOLS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$ffttClubToolsAutoloadPath = FFTT_CLUB_TOOLS_PLUGIN_PATH . 'vendor/autoload.php';
if ( file_exists( $ffttClubToolsAutoloadPath ) ) {
    require_once $ffttClubToolsAutoloadPath;
}

require_once FFTT_CLUB_TOOLS_PLUGIN_PATH . 'Fftt/Fftt.php';
require_once FFTT_CLUB_TOOLS_PLUGIN_PATH . 'Fftt/Importer.php';

// Auto-update depuis GitHub Releases.
add_action( 'init', static function() {
    if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
        return;
    }

    $repositoryUrl = defined( 'FFTT_CLUB_TOOLS_GITHUB_REPOSITORY' )
        ? (string) FFTT_CLUB_TOOLS_GITHUB_REPOSITORY
        : 'https://github.com/jarash/fftt-club-tools';

    $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        $repositoryUrl,
        FFTT_CLUB_TOOLS_PLUGIN_FILE,
        'fftt-club-tools'
    );
    $updateChecker->getVcsApi()->enableReleaseAssets();
} );

function fftt_club_tools_enqueue_public_assets() {
    if ( is_admin() ) {
        return;
    }

    wp_enqueue_style(
        'fftt_club_tools-public',
        FFTT_CLUB_TOOLS_PLUGIN_URL . 'assets/style.css',
        [],
        FFTT_CLUB_TOOLS_VERSION
    );

    wp_enqueue_script(
        'fftt_club_tools-ranking-filter',
        FFTT_CLUB_TOOLS_PLUGIN_URL . 'assets/ranking-filter.js',
        [],
        FFTT_CLUB_TOOLS_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'fftt_club_tools_enqueue_public_assets' );

#region Plugin ACF requis

// Plugin ACF obligatoire
function fftt_club_tools_activate_plugin() {
    if ( ! is_plugin_active( 'advanced-custom-fields/acf.php' ) && current_user_can( 'activate_plugins' ) ) {
        // Désactive le plugin
        deactivate_plugins( plugin_basename( __FILE__ ) );
        // Affiche un message d'erreur
        wp_die( 'Ce plugin nécessite que le plugin Advanced Custom Fields soit activé. <br><a href="' . admin_url( 'plugins.php' ) . '">Retour à la page des plugins</a>' );
    }

    fftt_club_tools_schedule_cron();
}
register_activation_hook( __FILE__, 'fftt_club_tools_activate_plugin' );

function fftt_club_tools_schedule_cron() {
    $frequency = (string) get_option( 'fftt_club_tools_cron_frequency', 'daily' );
    if ( $frequency === 'disabled' ) {
        return;
    }

    if ( ! wp_next_scheduled( 'fftt_club_tools_cron_import' ) ) {
        $schedules = wp_get_schedules();
        $interval = isset( $schedules[ $frequency ]['interval'] ) ? (int) $schedules[ $frequency ]['interval'] : DAY_IN_SECONDS;
        wp_schedule_event( time() + $interval, $frequency, 'fftt_club_tools_cron_import' );
    }
}
add_action( 'init', 'fftt_club_tools_schedule_cron', 5 );

function fftt_club_tools_unschedule_cron() {
    while ( true ) {
        $timestamp = wp_next_scheduled( 'fftt_club_tools_cron_import' );
        if ( ! $timestamp ) {
            break;
        }

        wp_unschedule_event( $timestamp, 'fftt_club_tools_cron_import' );
    }
}

register_deactivation_hook( __FILE__, 'fftt_club_tools_unschedule_cron' );

add_action( 'fftt_club_tools_cron_import', static function () {
    try {
        $stats = FfttClubToolsImporter::run();
        $stats['timestamp'] = current_time( 'mysql' );
        update_option( 'fftt_club_tools_last_import_stats', $stats );
        error_log( '[FFTT Club Tools cron] Import terminé : total=' . $stats['total'] . ', créés=' . $stats['created'] . ', mis à jour=' . $stats['updated'] . ', erreurs=' . $stats['errors'] );
    } catch ( \Throwable $exception ) {
        error_log( '[FFTT Club Tools cron] Import échoué : ' . $exception->getMessage() );
    }
} );

// Fallback runner when WordPress loopback requests are unavailable (common in Docker/local setups).
function fftt_club_tools_run_due_cron_without_loopback() {
    if ( wp_doing_cron() ) {
        return;
    }

    $frequency = (string) get_option( 'fftt_club_tools_cron_frequency', 'daily' );
    if ( $frequency === 'disabled' ) {
        return;
    }

    $nextTimestamp = wp_next_scheduled( 'fftt_club_tools_cron_import' );
    if ( ! $nextTimestamp || $nextTimestamp > time() ) {
        return;
    }

    $lockKey = 'fftt_club_tools_cron_import_lock';
    if ( get_transient( $lockKey ) ) {
        return;
    }

    set_transient( $lockKey, '1', 10 * MINUTE_IN_SECONDS );

    try {
        fftt_club_tools_unschedule_cron();
        do_action( 'fftt_club_tools_cron_import' );
        fftt_club_tools_schedule_cron();
    } finally {
        delete_transient( $lockKey );
    }
}
add_action( 'init', 'fftt_club_tools_run_due_cron_without_loopback', 20 );

// Message d'erreur dans l'administration si ACF est désactivé
function fftt_club_tools_check_acf_dependency() {
    if ( ! is_plugin_active( 'advanced-custom-fields/acf.php' ) ) {
        add_action( 'admin_notices', 'fftt_club_tools_acf_missing_notice' );
    }
}

function fftt_club_tools_acf_missing_notice() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    echo '<div class="notice notice-error"><p>'
        . esc_html__( 'Le plugin FFTT Club Tools nécessite Advanced Custom Fields (ACF) activé.', 'text_domain' )
        . '</p></div>';
}

add_action( 'admin_init', 'fftt_club_tools_check_acf_dependency' );

#endregion

#region Menu d'administration

// Initialisation du menu d'administration
function fftt_club_tools_plugin_menu() {
    add_menu_page(
        'FFTT Club Tools',
        'FFTT Club Tools',
        'manage_options',
        'fftt_club_tools_settings',
        'fftt_club_tools_settings_page'
    );

    add_submenu_page(
        'fftt_club_tools_settings',
        'Import FFTT',
        'Import FFTT',
        'manage_options',
        'fftt_club_tools_import',
        'fftt_club_tools_import_page'
    );
}
add_action('admin_menu', 'fftt_club_tools_plugin_menu');

// Contenu de la page des paramètres
function fftt_club_tools_settings_page() {
    $hasLoginConstant = defined('FFTT_CLUB_TOOLS_API_LOGIN');
    $hasPasswordConstant = defined('FFTT_CLUB_TOOLS_API_PASSWORD');
    $hasTeamConstant = defined('FFTT_CLUB_TOOLS_API_TEAM_ID');

    // Vérifie si le formulaire de paramètres a été soumis
    if (isset($_POST['fftt_club_tools_save_api_settings'])) {
        // Vérifie le nonce pour la sécurité
        check_admin_referer('fftt_club_tools_api_settings_nonce');

        $postedApiLogin = isset($_POST['api_login']) ? sanitize_text_field(wp_unslash($_POST['api_login'])) : '';
        $postedApiPassword = isset($_POST['api_password']) ? trim((string) wp_unslash($_POST['api_password'])) : '';
        $postedApiTeamId = isset($_POST['api_team_id']) ? sanitize_text_field(wp_unslash($_POST['api_team_id'])) : '';

        // Enregistre les valeurs dans la base de données
        if (!$hasLoginConstant) {
            update_option('fftt_club_tools_api_login', $postedApiLogin);
        }

        // Ne met à jour le mot de passe que si un nouveau est saisi.
        if (!$hasPasswordConstant && $postedApiPassword !== '') {
            update_option('fftt_club_tools_api_password', sanitize_text_field($postedApiPassword));
        }

        if (!$hasTeamConstant) {
            update_option('fftt_club_tools_api_team_id', $postedApiTeamId);
        }

        $allowedFrequencies = [ 'disabled', 'hourly', 'twicedaily', 'daily' ];
        $postedFrequency = isset( $_POST['cron_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['cron_frequency'] ) ) : 'daily';
        if ( ! in_array( $postedFrequency, $allowedFrequencies, true ) ) {
            $postedFrequency = 'daily';
        }

        $previousFrequency = (string) get_option( 'fftt_club_tools_cron_frequency', 'daily' );
        update_option( 'fftt_club_tools_cron_frequency', $postedFrequency );

        if ( $postedFrequency !== $previousFrequency ) {
            fftt_club_tools_unschedule_cron();
            fftt_club_tools_schedule_cron();
        }

        echo '<div class="updated"><p>Paramètres enregistrés avec succès.</p></div>';
    }

    // Récupère les valeurs actuelles
    $apiLogin = $hasLoginConstant
        ? (string) FFTT_CLUB_TOOLS_API_LOGIN
        : (string) get_option( 'fftt_club_tools_api_login', '' );
    $apiPassword = $hasPasswordConstant
        ? (string) FFTT_CLUB_TOOLS_API_PASSWORD
        : (string) get_option( 'fftt_club_tools_api_password', '' );
    $apiTeamId = $hasTeamConstant
        ? (string) FFTT_CLUB_TOOLS_API_TEAM_ID
        : (string) get_option( 'fftt_club_tools_api_team_id', '' );

    // Formulaire HTML pour saisir les clés API
    ?>
    <div class="wrap">
        <h1>Paramètres du plugin FFTT Club Tools</h1>
        <?php if ($hasLoginConstant || $hasPasswordConstant || $hasTeamConstant) : ?>
            <div class="notice notice-info">
                <p>
                    Certaines valeurs sont pilotées par <code>wp-config.php</code> et ne sont pas modifiables ici.
                </p>
            </div>
        <?php endif; ?>
        <form method="post" action="">
            <?php wp_nonce_field('fftt_club_tools_api_settings_nonce'); ?>

            <h2>Paramètres de l'API FFTT Club Tools</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="api_login">API Login</label></th>
                    <td>
                        <input type="text" name="api_login" id="api_login" value="<?php echo esc_attr($apiLogin); ?>" class="regular-text" <?php disabled($hasLoginConstant); ?> />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="api_password">API Password</label></th>
                    <td>
                        <input type="password" name="api_password" id="api_password" value="" class="regular-text" placeholder="<?php echo esc_attr($apiPassword !== '' ? 'Déjà configuré - laisser vide pour conserver' : 'Saisir un mot de passe API'); ?>" <?php disabled($hasPasswordConstant); ?> autocomplete="new-password" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="api_team_id">ID du club</label></th>
                    <td>
                        <input type="text" name="api_team_id" id="api_team_id" value="<?php echo esc_attr($apiTeamId); ?>" class="regular-text" <?php disabled($hasTeamConstant); ?> />
                    </td>
                </tr>
            </table>
            <input type="hidden" name="fftt_club_tools_save_api_settings" value="1" />
            <?php
            $cronFrequency = (string) get_option( 'fftt_club_tools_cron_frequency', 'daily' );
            $nextCron = wp_next_scheduled( 'fftt_club_tools_cron_import' );
            $isOverdueCron = $nextCron && $nextCron <= time();
            ?>
            <h2>Import automatique (Cron)</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="cron_frequency">Fréquence</label></th>
                    <td>
                        <select name="cron_frequency" id="cron_frequency">
                            <option value="disabled" <?php selected( $cronFrequency, 'disabled' ); ?>>Désactivé</option>
                            <option value="hourly" <?php selected( $cronFrequency, 'hourly' ); ?>>Toutes les heures</option>
                            <option value="twicedaily" <?php selected( $cronFrequency, 'twicedaily' ); ?>>Deux fois par jour</option>
                            <option value="daily" <?php selected( $cronFrequency, 'daily' ); ?>>Une fois par jour</option>
                        </select>
                        <?php if ( $nextCron ) : ?>
                            <p class="description">Prochain import : <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $nextCron ), 'd/m/Y H:i' ) ); ?></p>
                            <?php if ( $isOverdueCron ) : ?>
                                <p class="description">Import en retard: il sera lance a la prochaine requete WordPress.</p>
                            <?php endif; ?>
                        <?php elseif ( $cronFrequency !== 'disabled' ) : ?>
                            <p class="description">Aucun import planifié — réactivez le plugin pour planifier.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button('Enregistrer les paramètres'); ?>
        </form>

    </div>
    <?php
}

function fftt_club_tools_import_page() {
    if ( isset( $_POST['fftt_club_tools_run_import'] ) ) {
        check_admin_referer( 'fftt_club_tools_run_import_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas les droits pour lancer cet import.', 'text_domain' ) );
        }

        try {
            $stats = FfttClubToolsImporter::run();
            $stats['timestamp'] = current_time( 'mysql' );
            update_option( 'fftt_club_tools_last_import_stats', $stats );

            echo '<div class="updated"><p>Import terminé : '
                . 'total=' . esc_html( (string) $stats['total'] )
                . ', créés=' . esc_html( (string) $stats['created'] )
                . ', mis à jour=' . esc_html( (string) $stats['updated'] )
                . ', ignorés=' . esc_html( (string) $stats['skipped'] )
                . ', erreurs=' . esc_html( (string) $stats['errors'] )
                . '.</p></div>';
        } catch ( \Throwable $exception ) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Import impossible : ', 'text_domain' )
                . esc_html( $exception->getMessage() )
                . '</p></div>';
        }
    }

    $lastImportStats = get_option( 'fftt_club_tools_last_import_stats', [] );
    ?>
    <div class="wrap">
        <h1>Import FFTT</h1>
        <p>Lance un import immédiat des joueurs vers le post type <code>joueur</code>.</p>

        <form method="post" action="">
            <?php wp_nonce_field( 'fftt_club_tools_run_import_nonce' ); ?>
            <input type="hidden" name="fftt_club_tools_run_import" value="1" />
            <?php submit_button( 'Lancer l\'import maintenant', 'primary' ); ?>
        </form>

        <?php if ( is_array( $lastImportStats ) && ! empty( $lastImportStats ) ) : ?>
            <h2>Dernier import</h2>
            <p>
                <?php
                $lastImportDate = isset( $lastImportStats['timestamp'] ) ? (string) $lastImportStats['timestamp'] : '';
                $dateLabel = $lastImportDate !== '' ? $lastImportDate : 'date inconnue';
                echo esc_html( sprintf( 'Exécuté le %s', $dateLabel ) );
                ?>
            </p>
            <ul>
                <li><?php echo esc_html( 'Total: ' . (string) ( $lastImportStats['total'] ?? 0 ) ); ?></li>
                <li><?php echo esc_html( 'Créés: ' . (string) ( $lastImportStats['created'] ?? 0 ) ); ?></li>
                <li><?php echo esc_html( 'Mis à jour: ' . (string) ( $lastImportStats['updated'] ?? 0 ) ); ?></li>
                <li><?php echo esc_html( 'Ignorés: ' . (string) ( $lastImportStats['skipped'] ?? 0 ) ); ?></li>
                <li><?php echo esc_html( 'Erreurs: ' . (string) ( $lastImportStats['errors'] ?? 0 ) ); ?></li>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}

#endregion

#region Post-type Joueur

function fftt_club_tools_register_post_type() {
    $labels = array(
        'name'                  => _x( 'Joueurs', 'Post Type General Name', 'text_domain' ),
        'singular_name'         => _x( 'Joueur', 'Post Type Singular Name', 'text_domain' ),
        'menu_name'             => __( 'Joueurs', 'text_domain' ),
        'name_admin_bar'        => __( 'Joueur', 'text_domain' ),
    );

    $args = array(
        'label'                 => __( 'Joueur', 'text_domain' ),
        'labels'                => $labels,
        'supports'              => array( 'title', 'editor', 'thumbnail' ),
        'public'                => true,
        'has_archive'           => true,
        'show_in_rest'          => true,
    );

    register_post_type( 'joueur', $args );
}
add_action( 'init', 'fftt_club_tools_register_post_type' );

// Champs liés au joueur
add_action( 'acf/init', function() {
    if( function_exists('acf_add_local_field_group') ) {
        // Post-type Joueur
        acf_add_local_field_group(array(
            'key' => 'group_joueur',
            'title' => 'Informations joueur',
            'fields' => array(
                array(
                    'key' => 'nom',
                    'label' => 'Nom',
                    'name' => 'nom',
                    'type' => 'text',
                ),
                array(
                    'key' => 'prenom',
                    'label' => 'Prénom',
                    'name' => 'prenom',
                    'type' => 'text',
                ),
                array(
                    'key' => 'surnom',
                    'label' => 'Surnom',
                    'name' => 'surnom',
                    'type' => 'text',
                ),
                array(
                    'key' => 'sexe',
                    'label' => 'Sexe',
                    'name' => 'sexe',
                    'type' => 'select',
                    'choices' => array(
                        'homme' => 'Homme',
                        'femme' => 'Femme',
                    ),
                ),
                array(
                    'key' => 'points_fftt',
                    'label' => 'Points FFTT',
                    'name' => 'points_fftt',
                    'type' => 'number',
                    'default_value' => 500,
                ),
                array(
                    'key' => 'points_fftt_debut_saison',
                    'label' => 'Points FFTT début de saison',
                    'name' => 'points_fftt_debut_saison',
                    'type' => 'number',
                ),
                array(
                    'key' => 'points_fftt_mensuel',
                    'label' => 'Points FFTT mensuel',
                    'name' => 'points_fftt_mensuel',
                    'type' => 'number',
                ),
                array(
                    'key' => 'points_fftt_virtuel',
                    'label' => 'Points FFTT Virtuel',
                    'name' => 'points_fftt_virtuel',
                    'type' => 'number',
                ),
                array(
                    'key' => 'progression_points_fftt',
                    'label' => 'Progression Points FFTT',
                    'name' => 'progression_points_fftt',
                    'type' => 'number',
                ),
                array(
                    'key' => 'categorie',
                    'label' => 'Catégorie',
                    'name' => 'categorie',
                    'type' => 'text',
                ),
                array(
                    'key' => 'main',
                    'label' => 'Main',
                    'name' => 'main',
                    'type' => 'select',
                    'choices' => array(
                        'gauche' => 'Gaucher',
                        'droite' => 'Droitier',
                    ),
                ),
                array(
                    'key' => 'numero_licence',
                    'label' => 'Numéro de Licence',
                    'name' => 'numero_licence',
                    'type' => 'text',
                ),
                array(
                    'key' => 'field_equipe',
                    'label' => 'Équipe',
                    'name' => 'equipe',
                    'type' => 'taxonomy',
                    'taxonomy' => 'equipe',
                    'field_type' => 'select',
                    'return_format' => 'id',
                    'add_term' => 1,
                    'save_terms' => 1,
                    'load_terms' => 1,
                    'allow_null' => 1,
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'joueur',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
        ));

        // Post-type Calendrier
        acf_add_local_field_group(array(
            'key' => 'group_calendar',
            'title' => 'Informations calendrier',
            'fields' => array(
                array(
                    'key' => 'color',
                    'label' => 'Couleur',
                    'name' => 'couleur',
                    'type' => 'color_picker',
                    'default_value' => '#000000',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'calendar',
                    ),
                ),
            ),
        ));
    }
});

#endregion

#region Taxonomy Equipe

function fftt_club_tools_register_taxonomy_equipe() {
    $labels = array(
        'name'              => _x( 'Équipes', 'Taxonomy General Name', 'text_domain' ),
        'singular_name'     => _x( 'Équipe', 'Taxonomy Singular Name', 'text_domain' ),
        'search_items'      => __( 'Rechercher des équipes', 'text_domain' ),
        'all_items'         => __( 'Toutes les équipes', 'text_domain' ),
        'edit_item'         => __( 'Modifier l’équipe', 'text_domain' ),
        'update_item'       => __( 'Mettre à jour l’équipe', 'text_domain' ),
        'add_new_item'      => __( 'Ajouter une équipe', 'text_domain' ),
        'new_item_name'     => __( 'Nouvelle équipe', 'text_domain' ),
        'menu_name'         => __( 'Équipes', 'text_domain' ),
    );

    $args = array(
        'hierarchical'      => true,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array( 'slug' => 'equipe' ),
        'show_in_rest'      => true,
    );

    register_taxonomy( 'equipe', array( 'joueur' ), $args );
}
add_action( 'init', 'fftt_club_tools_register_taxonomy_equipe' );

#endregion

#region ShortCode [ranking]

// Shortcode pour afficher la progression des joueurs
// [ranking]
function shortcode_show_ranking( $atts = [] ) {
    $atts = shortcode_atts(
        [
            'limit' => -1,
            'order' => 'DESC',
            'show_emoji' => '1',
            'class' => '',
            'empty_message' => 'Aucun joueur a afficher.',
            'children_only' => '0',
            'age_category' => '',
            'children_categories' => 'poussin,benjamin,minime,cadet,junior',
        ],
        $atts,
        'ranking'
    );

    $limit = (int) $atts['limit'];
    if ( $limit === 0 || $limit < -1 ) {
        $limit = -1;
    }

    $order = strtoupper( (string) $atts['order'] ) === 'ASC' ? 'ASC' : 'DESC';
    $showEmoji = (string) $atts['show_emoji'] !== '0';
    $tableClass = trim( (string) $atts['class'] );
    $childrenOnly = in_array( strtolower( trim( (string) $atts['children_only'] ) ), [ '1', 'true', 'yes', 'oui' ], true );
    $ageCategoryFilter = trim( (string) $atts['age_category'] );
    $childrenCategories = trim( (string) $atts['children_categories'] );
    $emptyMessage = trim( (string) $atts['empty_message'] );
    if ( $emptyMessage === '' ) {
        $emptyMessage = 'Aucun joueur a afficher.';
    }

    if ( $childrenOnly && $ageCategoryFilter === '' ) {
        $ageCategoryFilter = $childrenCategories;
    }

    $ageCategories = array_values(
        array_filter(
            array_map(
                static function( $category ): string {
                    return trim( sanitize_text_field( (string) $category ) );
                },
                explode( ',', $ageCategoryFilter )
            )
        )
    );

    $queryArgs = [
        'post_type' => 'joueur',
        'post_status' => 'publish',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'points_fftt',
                'value' => 500,
                'compare' => '!=',
            ],
            [
                'key' => 'progression_points_fftt',
                'value' => 0,
                'compare' => '!=',
            ],
        ],
        'orderby' => 'meta_value_num',
        'meta_key' => 'progression_points_fftt',
        'order' => $order,
        'posts_per_page' => $limit,
        'no_found_rows' => true,
    ];

    if ( ! empty( $ageCategories ) ) {
        $ageQuery = [ 'relation' => 'OR' ];
        foreach ( $ageCategories as $ageCategory ) {
            $ageQuery[] = [
                'key' => 'categorie',
                'value' => $ageCategory,
                'compare' => 'LIKE',
            ];
        }

        $queryArgs['meta_query'][] = $ageQuery;
    }

    $query = new WP_Query( $queryArgs );
    if ( ! $query->have_posts() ) {
        wp_reset_postdata();
        return '<p class="fftt_club_tools-ranking-empty">' . esc_html( $emptyMessage ) . '</p>';
    }

    $classes = [ 'wp-block-table', 'fftt_club_tools-ranking-table-wrapper' ];
    if ( $tableClass !== '' ) {
        $customClasses = preg_split( '/\s+/', $tableClass ) ?: [];
        foreach ( $customClasses as $customClass ) {
            $sanitizedClass = sanitize_html_class( $customClass );
            if ( $sanitizedClass !== '' ) {
                $classes[] = $sanitizedClass;
            }
        }
    }

    $wrapperId = 'fftt_club_tools-ranking-' . wp_unique_id();
    $childrenCategoriesAttr = esc_attr( implode( ',', $ageCategories ) !== '' ? implode( ',', $ageCategories ) : $childrenCategories );

    $wrapperClassAttr = esc_attr( implode( ' ', array_filter( $classes ) ) );
    $output = '<div>';
    $output .= '<label for="fftt-filter-' . esc_attr( $wrapperId ) . '" class="fftt_club_tools-ranking-filter-label">Filtre : </label>';
    $output .= '<select id="fftt-filter-' . esc_attr( $wrapperId ) . '" class="fftt_club_tools-ranking-filter" data-target="' . esc_attr( $wrapperId ) . '">';
    $output .= '<option value="all">Tous</option>';
    $output .= '<option value="children">Jeunes</option>';
    $output .= '</select>';
    $output .= '</div>';
    $output .= '<figure id="' . esc_attr( $wrapperId ) . '" class="' . $wrapperClassAttr . '" data-children-categories="' . $childrenCategoriesAttr . '">';
    $output .= '<table class="fftt_club_tools-ranking-table">';
    $output .= '<thead><tr>';
    $output .= '<th scope="col">#</th>';
    $output .= '<th scope="col">Joueur</th>';
    $output .= '<th scope="col">Points FFTT</th>';
    $output .= '<th scope="col">Progression</th>';
    $output .= '</tr></thead><tbody>';

    $rank = 1;
    while ( $query->have_posts() ) {
        $query->the_post();

        $points = (int) get_post_meta( get_the_ID(), 'points_fftt_debut_saison', true );
        $pointsVirtuels = (int) get_post_meta( get_the_ID(), 'points_fftt_virtuel', true );
        $progression = (int) get_post_meta( get_the_ID(), 'progression_points_fftt', true );

        $progressionLabel = ($progression > 0 ? '+' : '') . (string) $progression;
        $emoji = $showEmoji ? ' <span class="fftt_club_tools-ranking-emoji" aria-hidden="true">' . esc_html( get_progression_emoji( $progression ) ) . '</span>' : '';

        $categorie = (string) get_post_meta( get_the_ID(), 'categorie', true );

        $output .= '<tr data-age-category="' . esc_attr( $categorie ) . '">';
        $output .= '<td>' . esc_html( (string) $rank ) . '</td>';
        $output .= '<td>' . esc_html( get_the_title() ) . '</td>';
        $output .= '<td>' . esc_html( number_format_i18n( $points, 0 ) ) . ' / ' . esc_html( number_format_i18n( $pointsVirtuels, 0 ) ) . '</td>';
        $output .= '<td>' . esc_html( $progressionLabel ) . $emoji . '</td>';
        $output .= '</tr>';

        $rank++;
    }

    $output .= '</tbody></table></figure></div>';

    wp_reset_postdata();

    return $output;
}
add_shortcode('ranking', 'shortcode_show_ranking');

function get_progression_emoji($progression) {
    return match (true) {
        $progression <= -100 => '🤡',
        $progression <= -50 => '😭',
        $progression <= -40 => '🤐',
        $progression <= -30 => '😒',
        $progression <= -20 => '🌧️',
        $progression <= -10 => '🥺',
        $progression < 0 => '😥',
        $progression >= 200 => '👑',
        $progression >= 100 => '🚀',
        $progression >= 70 => '🏆',
        $progression >= 60 => '😎',
        $progression >= 50 => '🏅',
        $progression >= 40 => '🔥',
        $progression >= 30 => '💪',
        $progression >= 20 => '😇',
        $progression >= 10 => '😏',
        $progression >= 0 => '🏓',
        default => '🏓',
    };
}

#endregion