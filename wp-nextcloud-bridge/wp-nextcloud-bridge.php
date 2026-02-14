<?php
/**
 * Plugin Name: WP Nextcloud Bridge
 * Plugin URI:  https://github.com/your-repo/wp-nextcloud-bridge
 * Description: Browse Nextcloud files from WordPress and sync local directories to Nextcloud via WebDAV.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: wp-nc-bridge
 * Domain Path: /languages
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

/* ── Constants ─────────────────────────────────────────────── */
define( 'WPNC_VERSION',    '1.0.0' );
define( 'WPNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPNC_BASENAME',   plugin_basename( __FILE__ ) );

/* ── Autoloader (simple, no Composer needed) ───────────────── */
spl_autoload_register( function ( $class ) {

    // Map: class prefix → directory
    $map = array(
        'WPNC\\' => WPNC_PLUGIN_DIR . 'inc/',
    );

    foreach ( $map as $prefix => $base_dir ) {
        $len = strlen( $prefix );
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            continue;
        }
        // e.g. WPNC\Nextcloud_Client → inc/class-nextcloud-client.php
        $relative = substr( $class, $len );
        $file     = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
} );

/* ── Bootstrap ─────────────────────────────────────────────── */
add_action( 'plugins_loaded', 'wpnc_bootstrap' );

function wpnc_bootstrap() {
    // Settings page + diagnostics (admin only)
    if ( is_admin() ) {
        new WPNC\NC_Settings();
        new WPNC\NC_Diagnostics();
    }

    // Frontend shortcode (registers [nextcloud]).
    new WPNC\NC_Shortcode();

    // AJAX handlers (both admin & front for nopriv proxy)
    new WPNC\NC_Ajax();
}

/* ── Activation / Deactivation ─────────────────────────────── */
register_activation_hook( __FILE__, function () {
    // Future: create custom tables or schedule cron events
    if ( ! get_option( 'wpnc_settings' ) ) {
        add_option( 'wpnc_settings', array(
            'nc_url'        => '',
            'nc_username'   => '',
            'nc_password'   => '',
            'nc_root_path'  => '/WordPress',
            'max_file_size' => 2048,
            'sync_dirs'     => array(),
        ) );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wpnc_scheduled_sync' );
} );
