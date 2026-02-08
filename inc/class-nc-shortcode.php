<?php
/**
 * Frontend Shortcode: [nextcloud]
 *
 * Renders a file browser + uploader on any page/post.
 * Only accessible to users with the configured capability.
 *
 * Usage:
 *   [nextcloud]                          — browse from NC root
 *   [nextcloud path="Documents"]         — browse from a specific subfolder
 *   [nextcloud upload="true"]            — enable uploads (default: true)
 *   [nextcloud create_folder="true"]     — enable folder creation (default: true)
 *   [nextcloud delete="true"]            — enable deletion (default: false)
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class NC_Shortcode {

    const SHORTCODE  = 'nextcloud';
    const CAPABILITY = 'manage_options';  // Only admins for now.

    /** @var bool Whether assets have already been enqueued this page load. */
    private static $enqueued = false;

    /* ================================================================
     *  BOOTSTRAP
     * ============================================================= */

    public function __construct() {
        add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
    }

    /* ================================================================
     *  SHORTCODE RENDER
     * ============================================================= */

    /**
     * Render the [nextcloud] shortcode.
     *
     * @param array|string $atts  Shortcode attributes.
     * @return string  HTML output.
     */
    public function render( $atts ): string {
        // Permission gate — show nothing to unauthorised users.
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return '<p class="wpnc-noaccess">' . esc_html__( 'You do not have permission to access this file browser.', 'wp-nc-bridge' ) . '</p>';
        }

        $atts = shortcode_atts( array(
            'path'          => '',
            'upload'        => 'true',
            'create_folder' => 'true',
            'delete'        => 'false',
        ), $atts, self::SHORTCODE );

        // Normalise booleans.
        $allow_upload = filter_var( $atts['upload'], FILTER_VALIDATE_BOOLEAN );
        $allow_mkdir  = filter_var( $atts['create_folder'], FILTER_VALIDATE_BOOLEAN );
        $allow_delete = filter_var( $atts['delete'], FILTER_VALIDATE_BOOLEAN );
        $initial_path = sanitize_text_field( $atts['path'] );

        // Enqueue assets once per page.
        $this->enqueue_assets();

        // Unique ID so multiple shortcodes can coexist.
        $uid = 'wpnc-' . wp_unique_id();

        // Build the container. The JS picks it up by [data-wpnc-browser].
        ob_start();
        ?>
        <div id="<?php echo esc_attr( $uid ); ?>"
             class="wpnc-browser"
             data-wpnc-browser
             data-initial-path="<?php echo esc_attr( $initial_path ); ?>"
             data-allow-upload="<?php echo $allow_upload ? '1' : '0'; ?>"
             data-allow-mkdir="<?php echo $allow_mkdir ? '1' : '0'; ?>"
             data-allow-delete="<?php echo $allow_delete ? '1' : '0'; ?>">

            <!-- Toolbar -->
            <div class="wpnc-toolbar">
                <nav class="wpnc-breadcrumb" aria-label="<?php esc_attr_e( 'File path', 'wp-nc-bridge' ); ?>"></nav>
                <div class="wpnc-toolbar-actions">
                    <?php if ( $allow_mkdir ) : ?>
                        <button type="button" class="wpnc-btn wpnc-btn-mkdir" title="<?php esc_attr_e( 'New folder', 'wp-nc-bridge' ); ?>">
                            <span class="wpnc-icon">📁+</span>
                        </button>
                    <?php endif; ?>
                    <?php if ( $allow_upload ) : ?>
                        <button type="button" class="wpnc-btn wpnc-btn-upload" title="<?php esc_attr_e( 'Upload files', 'wp-nc-bridge' ); ?>">
                            <span class="wpnc-icon">⬆️</span> <?php esc_html_e( 'Upload', 'wp-nc-bridge' ); ?>
                        </button>
                        <input type="file" class="wpnc-file-input" multiple style="display:none" />
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upload progress area (hidden by default) -->
            <div class="wpnc-upload-progress" style="display:none"></div>

            <!-- Drop zone overlay -->
            <?php if ( $allow_upload ) : ?>
                <div class="wpnc-dropzone-overlay">
                    <div class="wpnc-dropzone-text"><?php esc_html_e( 'Drop files here to upload', 'wp-nc-bridge' ); ?></div>
                </div>
            <?php endif; ?>

            <!-- File list -->
            <div class="wpnc-file-list">
                <div class="wpnc-loading"><?php esc_html_e( 'Loading…', 'wp-nc-bridge' ); ?></div>
            </div>

            <!-- Empty state -->
            <div class="wpnc-empty" style="display:none">
                <p><?php esc_html_e( 'This folder is empty.', 'wp-nc-bridge' ); ?></p>
            </div>

            <!-- Error display -->
            <div class="wpnc-error" style="display:none"></div>

            <!-- Custom modal container -->
            <div class="wpnc-modal-backdrop" style="display:none">
                <div class="wpnc-modal"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
     *  ASSET ENQUEUE
     * ============================================================= */

    private function enqueue_assets() {
        if ( self::$enqueued ) {
            return;
        }
        self::$enqueued = true;

        wp_enqueue_style(
            'wpnc-front',
            WPNC_PLUGIN_URL . 'assets/css/front.css',
            array(),
            WPNC_VERSION
        );

        wp_enqueue_script(
            'wpnc-front',
            WPNC_PLUGIN_URL . 'assets/js/front.js',
            array(),  // No jQuery dependency — vanilla JS.
            WPNC_VERSION,
            true      // Load in footer.
        );

        wp_localize_script( 'wpnc-front', 'wpncFront', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( NC_Ajax::NONCE_ACTION ),
            'chunkSize' => $this->get_upload_chunk_size(),
            'i18n'      => array(
                'root'           => __( 'Root', 'wp-nc-bridge' ),
                'loading'        => __( 'Loading…', 'wp-nc-bridge' ),
                'empty'          => __( 'This folder is empty.', 'wp-nc-bridge' ),
                'upload'         => __( 'Upload', 'wp-nc-bridge' ),
                'uploading'      => __( 'Uploading…', 'wp-nc-bridge' ),
                'upload_done'    => __( 'Upload complete', 'wp-nc-bridge' ),
                'upload_fail'    => __( 'Upload failed', 'wp-nc-bridge' ),
                'new_folder'     => __( 'New folder', 'wp-nc-bridge' ),
                'folder_name'    => __( 'Folder name', 'wp-nc-bridge' ),
                'create'         => __( 'Create', 'wp-nc-bridge' ),
                'cancel'         => __( 'Cancel', 'wp-nc-bridge' ),
                'confirm_delete' => __( 'Delete "%s"? This cannot be undone.', 'wp-nc-bridge' ),
                'delete'         => __( 'Delete', 'wp-nc-bridge' ),
                'share'          => __( 'Share link', 'wp-nc-bridge' ),
                'copied'         => __( 'Link copied!', 'wp-nc-bridge' ),
                'download'       => __( 'Download', 'wp-nc-bridge' ),
                'error'          => __( 'Error', 'wp-nc-bridge' ),
                'drop_here'      => __( 'Drop files here to upload', 'wp-nc-bridge' ),
                'preparing'      => __( 'Preparing…', 'wp-nc-bridge' ),
                'chunk_progress' => __( 'Uploading %1$s: %2$d%%', 'wp-nc-bridge' ),
            ),
        ) );
    }

    /**
     * Determine the chunk size for browser → WP uploads.
     *
     * Respects PHP's upload_max_filesize and post_max_size, capped at 10 MB.
     *
     * @return int  Chunk size in bytes.
     */
    private function get_upload_chunk_size(): int {
        $max_upload = wp_max_upload_size(); // Considers PHP ini limits.
        // Use at most 10 MB chunks (matches Nextcloud client chunk size).
        return min( $max_upload, 10 * 1024 * 1024 );
    }
}
