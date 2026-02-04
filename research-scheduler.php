<?php
/**
 * Plugin Name: Research Scheduler (Doodle-like)
 * Description: Simple Doodle-like meeting poll plugin: create a meeting poll with time slots and collect votes via shareable link.
 * Version: 0.1.10
 * Author: Félix González Peñaloza; José María de la Rosa; José Antonio González Pérez; Paloma Campos; Antonio Cascajosa Lira; Águeda M. Sánchez Martín; Sara M. Pérez Dalí; Desiré Monis Carrere; Claudia Rodríguez-López; Alba María Carmona Navarro; Jorge Márquez Moreno; Daniel Cuella; Olaya García Ruiz (CSIC); Javier Bravo García (EVENOR)
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('RS_DOODLELIKE_VERSION', '0.1.10');
define('RS_DOODLELIKE_PATH', plugin_dir_path(__FILE__));
define('RS_DOODLELIKE_URL', plugin_dir_url(__FILE__));

require_once RS_DOODLELIKE_PATH . 'includes/helpers.php';
require_once RS_DOODLELIKE_PATH . 'includes/shortcodes.php';
require_once RS_DOODLELIKE_PATH . 'includes/admin.php';

function rsdl_add_rewrites(){
    // Pretty URL: /rs-meeting/{token}/
    add_rewrite_rule('^rs-meeting/([^/]+)/?$', 'index.php?rsdl_poll=1&rs_token=$matches[1]', 'top');
}
add_action('init', 'rsdl_add_rewrites');


register_activation_hook(__FILE__, 'rsdl_activate');
register_deactivation_hook(__FILE__, 'rsdl_deactivate');

function rsdl_activate() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();

    $meetings = $wpdb->prefix . 'rsdl_meetings';
    $slots    = $wpdb->prefix . 'rsdl_slots';
    $votes    = $wpdb->prefix . 'rsdl_votes';

    $sql1 = "CREATE TABLE $meetings (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        token VARCHAR(64) NOT NULL,
        title TEXT NOT NULL,
        description LONGTEXT NULL,
        creator_user_id BIGINT(20) UNSIGNED NULL,
        creator_email VARCHAR(255) NOT NULL,
        timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Madrid',
        status VARCHAR(16) NOT NULL DEFAULT 'open',
        deadline DATETIME NULL,
        winning_slot_id BIGINT(20) UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY token (token),
        KEY status (status),
        KEY creator_user_id (creator_user_id)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE $slots (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        meeting_id BIGINT(20) UNSIGNED NOT NULL,
        start_dt DATETIME NOT NULL,
        end_dt DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY meeting_id (meeting_id),
        KEY start_dt (start_dt)
    ) $charset_collate;";

    $sql3 = "CREATE TABLE $votes (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        meeting_id BIGINT(20) UNSIGNED NOT NULL,
        slot_id BIGINT(20) UNSIGNED NOT NULL,
        voter_email VARCHAR(255) NOT NULL,
        vote_value VARCHAR(8) NOT NULL DEFAULT 'yes',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY meeting_id (meeting_id),
        KEY slot_id (slot_id),
        KEY voter_email (voter_email)
    ) $charset_collate;";

    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);

    add_option('rsdl_version', RS_DOODLELIKE_VERSION);
    add_option('rsdl_notify_creator', 1);

    // Ensure pretty URL works immediately
    rsdl_add_rewrites();
    flush_rewrite_rules();
}

function rsdl_deactivate() {
    // Intentionally does not delete data.
    flush_rewrite_rules();
}


add_action('plugins_loaded', function(){
    rsdl_maybe_upgrade();
});

function rsdl_maybe_upgrade() {
    $current = get_option('rsdl_version', '0.0.0');
    if (version_compare($current, RS_DOODLELIKE_VERSION, '>=')) {
        return;
    }

    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();
    $votes = $wpdb->prefix . 'rsdl_votes';

    $sql = "CREATE TABLE $votes (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        meeting_id BIGINT(20) UNSIGNED NOT NULL,
        slot_id BIGINT(20) UNSIGNED NOT NULL,
        voter_email VARCHAR(255) NOT NULL,
        vote_value VARCHAR(8) NOT NULL DEFAULT 'yes',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY meeting_id (meeting_id),
        KEY slot_id (slot_id),
        KEY voter_email (voter_email)
    ) $charset_collate;";

    dbDelta($sql);
    update_option('rsdl_version', RS_DOODLELIKE_VERSION);
}




add_filter('query_vars', function($vars){
    $vars[] = 'rs_token';
    $vars[] = 'rsdl_poll';
    return $vars;
});

add_action('init', function(){
    // Pretty URL: /rs-meeting/{token}/
    add_rewrite_rule('^rs-meeting/([^/]+)/?$', 'index.php?rsdl_poll=1&rs_token=$matches[1]', 'top');
});

add_action('template_redirect', function(){
    $is_poll = intval(get_query_var('rsdl_poll', 0));
    $token = get_query_var('rs_token', '');
    if ($is_poll === 1 && !empty($token)) {
        status_header(200);
        nocache_headers();
        // Minimal page rendering
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <?php wp_head(); ?>
        </head>
        <body <?php body_class(); ?>>
            <main class="rsdl-standalone">
                <?php echo do_shortcode('[rs_meeting_poll]'); ?>
            </main>
            <?php wp_footer(); ?>
        </body>
        </html><?php
        exit;
    }
});

add_action('wp_enqueue_scripts', function() {
    wp_register_script('rsdl_script', RS_DOODLELIKE_URL . 'assets/rsdl.js', array(), RS_DOODLELIKE_VERSION, true);
    wp_enqueue_script('rsdl_script');
    wp_register_style('rsdl_styles', RS_DOODLELIKE_URL . 'assets/rsdl.css', array(), RS_DOODLELIKE_VERSION);
    wp_enqueue_style('rsdl_styles');
});

