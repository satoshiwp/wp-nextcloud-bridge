<?php
/**
 * Admin Settings Page
 *
 * Single-responsibility: register menu, render settings form,
 * sanitise + persist options, enqueue admin assets.
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class NC_Settings {

    /** Option key in wp_options. */
    const OPTION_KEY = 'wpnc_settings';

    /** Settings group used by the Settings API. */
    const GROUP = 'wpnc_settings_group';

    /** Slug for the admin page. */
    const PAGE_SLUG = 'wp-nextcloud-bridge';

    /** Capability required to access this page. */
    const CAPABILITY = 'manage_options';

    /* ================================================================
     *  BOOTSTRAP
     * ============================================================= */

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wpnc_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    /* ================================================================
     *  MENU
     * ============================================================= */

    public function add_menu() {
        add_options_page(
            __( 'Nextcloud Bridge', 'wp-nc-bridge' ),
            __( 'Nextcloud Bridge', 'wp-nc-bridge' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /* ================================================================
     *  SETTINGS API REGISTRATION
     * ============================================================= */

    public function register_settings() {
        register_setting( self::GROUP, self::OPTION_KEY, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize' ),
            'default'           => self::defaults(),
        ) );

        /* ── Section: Connection ─────────────────────────── */
        add_settings_section(
            'wpnc_section_connection',
            __( 'Nextcloud Connection', 'wp-nc-bridge' ),
            function () {
                echo '<p>' . esc_html__( 'Enter your Nextcloud server URL and credentials. An App-Password is recommended over your main password.', 'wp-nc-bridge' ) . '</p>';
            },
            self::PAGE_SLUG
        );

        $this->add_field( 'nc_url',      __( 'Nextcloud URL', 'wp-nc-bridge' ),     'render_field_url',      'wpnc_section_connection' );
        $this->add_field( 'nc_username',  __( 'Username', 'wp-nc-bridge' ),          'render_field_username', 'wpnc_section_connection' );
        $this->add_field( 'nc_password',  __( 'Password / App-Password', 'wp-nc-bridge' ), 'render_field_password', 'wpnc_section_connection' );

        /* ── Section: Paths ──────────────────────────────── */
        add_settings_section(
            'wpnc_section_paths',
            __( 'Paths & Sync', 'wp-nc-bridge' ),
            function () {
                echo '<p>' . esc_html__( 'Configure the root folder on Nextcloud and which local directories to sync.', 'wp-nc-bridge' ) . '</p>';
            },
            self::PAGE_SLUG
        );

        $this->add_field( 'nc_root_path',    __( 'Nextcloud Root Folder', 'wp-nc-bridge' ),   'render_field_root',          'wpnc_section_paths' );
        $this->add_field( 'max_file_size',   __( 'Max Sync File Size (MB)', 'wp-nc-bridge' ), 'render_field_max_file_size', 'wpnc_section_paths' );
        $this->add_field( 'sync_dirs',       __( 'Directories to Sync', 'wp-nc-bridge' ),     'render_field_sync_dirs',     'wpnc_section_paths' );
    }

    /* ================================================================
     *  FIELD RENDERERS
     * ============================================================= */

    public function render_field_url() {
        $val = $this->get_val( 'nc_url' );
        printf(
            '<input type="url" name="%s[nc_url]" value="%s" class="regular-text" placeholder="https://cloud.example.com" />
             <p class="description">%s</p>',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $val ),
            esc_html__( 'Full URL without trailing slash.', 'wp-nc-bridge' )
        );
    }

    public function render_field_username() {
        $val = $this->get_val( 'nc_username' );
        printf(
            '<input type="text" name="%s[nc_username]" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $val )
        );
    }

    public function render_field_password() {
        $val = $this->get_val( 'nc_password' );
        printf(
            '<input type="password" name="%s[nc_password]" value="%s" class="regular-text" autocomplete="new-password" />
             <p class="description">%s</p>',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $val ),
            esc_html__( 'Recommended: use an App-Password (Settings → Security → Devices & sessions in Nextcloud).', 'wp-nc-bridge' )
        );
    }

    public function render_field_root() {
        $val = $this->get_val( 'nc_root_path' );
        printf(
            '<input type="text" name="%s[nc_root_path]" value="%s" class="regular-text" placeholder="/WordPress" />
             <p class="description">%s</p>',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $val ),
            esc_html__( 'Folder on Nextcloud to use as root. Will be created if it does not exist.', 'wp-nc-bridge' )
        );
    }

    public function render_field_max_file_size() {
        $val = (int) $this->get_val( 'max_file_size' );
        if ( $val <= 0 ) {
            $val = 2048; // 2 GB default
        }
        printf(
            '<input type="number" name="%s[max_file_size]" value="%d" class="small-text" min="1" step="1" /> MB
             <p class="description">%s</p>',
            esc_attr( self::OPTION_KEY ),
            $val,
            esc_html__( 'Files larger than this will be skipped during sync. Default: 2048 MB (2 GB). Set to 0 for no limit.', 'wp-nc-bridge' )
        );
    }

    public function render_field_sync_dirs() {
        $dirs = $this->get_val( 'sync_dirs' );
        if ( ! is_array( $dirs ) ) {
            $dirs = array();
        }

        $abspath = untrailingslashit( ABSPATH );
        ?>
        <div id="wpnc-sync-dirs-wrap">
            <table class="widefat wpnc-sync-table" style="max-width:700px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Local Path (relative to WP root)', 'wp-nc-bridge' ); ?></th>
                        <th><?php esc_html_e( 'Remote Path (on Nextcloud)', 'wp-nc-bridge' ); ?></th>
                        <th style="width:50px"></th>
                    </tr>
                </thead>
                <tbody id="wpnc-sync-rows">
                    <?php if ( ! empty( $dirs ) ) : ?>
                        <?php foreach ( $dirs as $i => $pair ) : ?>
                            <tr>
                                <td><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_dirs][<?php echo (int) $i; ?>][local]" value="<?php echo esc_attr( $pair['local'] ?? '' ); ?>" class="regular-text" placeholder="wp-content/uploads" /></td>
                                <td><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_dirs][<?php echo (int) $i; ?>][remote]" value="<?php echo esc_attr( $pair['remote'] ?? '' ); ?>" class="regular-text" placeholder="uploads" /></td>
                                <td><button type="button" class="button wpnc-remove-row">&times;</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button wpnc-add-row">+ <?php esc_html_e( 'Add directory pair', 'wp-nc-bridge' ); ?></button>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: WordPress root path */
                    esc_html__( 'Local paths are relative to %s. Remote paths are relative to the Nextcloud Root Folder above.', 'wp-nc-bridge' ),
                    '<code>' . esc_html( $abspath ) . '</code>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE RENDERER
     * ============================================================= */

    public function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Nextcloud Bridge', 'wp-nc-bridge' ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::GROUP );
                do_settings_sections( self::PAGE_SLUG );
                ?>

                <h2 class="title"><?php esc_html_e( 'Connection Test', 'wp-nc-bridge' ); ?></h2>
                <p>
                    <button type="button" id="wpnc-test-btn" class="button button-secondary">
                        <?php esc_html_e( 'Test Connection', 'wp-nc-bridge' ); ?>
                    </button>
                    <span id="wpnc-test-result" style="margin-left:12px;"></span>
                </p>

                <?php submit_button( __( 'Save Settings', 'wp-nc-bridge' ) ); ?>
            </form>

            <hr />

            <h2 class="title"><?php esc_html_e( 'Nextcloud File Browser', 'wp-nc-bridge' ); ?></h2>
            <div id="wpnc-browser-root"></div>

            <hr />

            <h2 class="title"><?php esc_html_e( 'Manual Sync', 'wp-nc-bridge' ); ?></h2>
            <p>
                <button type="button" id="wpnc-sync-btn" class="button button-primary">
                    <?php esc_html_e( 'Sync Now', 'wp-nc-bridge' ); ?>
                </button>
                <span id="wpnc-sync-result" style="margin-left:12px;"></span>
            </p>
            <div id="wpnc-sync-log" style="max-height:300px;overflow:auto;background:#f6f7f7;padding:8px 12px;margin-top:8px;display:none;font-family:monospace;font-size:13px;"></div>
        </div>
        <?php
    }

    /* ================================================================
     *  SANITISATION
     * ============================================================= */

    /**
     * Sanitise the full option array before saving.
     *
     * @param array $input  Raw POST data.
     * @return array  Clean data.
     */
    public function sanitize( $input ) {
        $clean = self::defaults();

        $clean['nc_url']      = esc_url_raw( rtrim( $input['nc_url'] ?? '', '/' ) );
        $clean['nc_username'] = sanitize_text_field( $input['nc_username'] ?? '' );

        // Preserve existing password if the field comes back masked / empty.
        $new_pass = $input['nc_password'] ?? '';
        if ( $new_pass !== '' ) {
            $clean['nc_password'] = $new_pass; // Stored as-is (use App-Passwords!).
        } else {
            $old = get_option( self::OPTION_KEY, array() );
            $clean['nc_password'] = $old['nc_password'] ?? '';
        }

        $clean['nc_root_path'] = '/' . ltrim( sanitize_text_field( $input['nc_root_path'] ?? 'WordPress' ), '/' );

        // Max file size in MB (0 = no limit).
        $max_mb = (int) ( $input['max_file_size'] ?? 2048 );
        $clean['max_file_size'] = max( 0, $max_mb );

        // Sync dirs — filter out empty rows.
        $raw_dirs = $input['sync_dirs'] ?? array();
        $clean['sync_dirs'] = array();
        if ( is_array( $raw_dirs ) ) {
            foreach ( $raw_dirs as $pair ) {
                $local  = sanitize_text_field( trim( $pair['local'] ?? '', '/' ) );
                $remote = sanitize_text_field( trim( $pair['remote'] ?? '', '/' ) );
                if ( $local !== '' && $remote !== '' ) {
                    $clean['sync_dirs'][] = array(
                        'local'  => $local,
                        'remote' => $remote,
                    );
                }
            }
        }

        return $clean;
    }

    /* ================================================================
     *  AJAX: TEST CONNECTION
     * ============================================================= */

    public function ajax_test_connection() {
        check_ajax_referer( 'wpnc_nonce', '_nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nc-bridge' ) );
        }

        $client = Nextcloud_Client::from_settings();
        if ( is_wp_error( $client ) ) {
            wp_send_json_error( $client->get_error_message() );
        }

        $result = $client->test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( __( 'Connected successfully!', 'wp-nc-bridge' ) );
    }

    /* ================================================================
     *  ASSET ENQUEUE
     * ============================================================= */

    /**
     * Enqueue JS & CSS only on our own settings page.
     *
     * @param string $hook_suffix  Current admin page hook.
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( $hook_suffix !== 'settings_page_' . self::PAGE_SLUG ) {
            return;
        }

        wp_enqueue_style(
            'wpnc-admin',
            WPNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WPNC_VERSION
        );

        wp_enqueue_script(
            'wpnc-admin',
            WPNC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WPNC_VERSION,
            true
        );

        wp_localize_script( 'wpnc-admin', 'wpncAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpnc_nonce' ),
            'i18n'    => array(
                'testing'      => __( 'Testing…', 'wp-nc-bridge' ),
                'syncing'      => __( 'Syncing…', 'wp-nc-bridge' ),
                'success'      => __( 'Success!', 'wp-nc-bridge' ),
                'error'        => __( 'Error', 'wp-nc-bridge' ),
                'loading'      => __( 'Loading…', 'wp-nc-bridge' ),
                'empty_folder' => __( '(empty folder)', 'wp-nc-bridge' ),
                'confirm_sync' => __( 'Start syncing configured directories to Nextcloud?', 'wp-nc-bridge' ),
            ),
        ) );
    }

    /* ================================================================
     *  HELPERS
     * ============================================================= */

    /**
     * Default option values.
     */
    public static function defaults(): array {
        return array(
            'nc_url'         => '',
            'nc_username'    => '',
            'nc_password'    => '',
            'nc_root_path'   => '/WordPress',
            'max_file_size'  => 2048, // MB
            'sync_dirs'      => array(),
        );
    }

    /**
     * Convenience: read a single key from the persisted settings.
     */
    private function get_val( string $key ) {
        $opts = get_option( self::OPTION_KEY, self::defaults() );
        return $opts[ $key ] ?? self::defaults()[ $key ] ?? '';
    }

    /**
     * Shortcut to register a settings field.
     */
    private function add_field( string $id, string $title, string $callback, string $section ) {
        add_settings_field( 'wpnc_field_' . $id, $title, array( $this, $callback ), self::PAGE_SLUG, $section );
    }
}
