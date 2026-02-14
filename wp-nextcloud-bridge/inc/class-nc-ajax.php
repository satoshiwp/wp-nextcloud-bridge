<?php
/**
 * AJAX Request Router
 *
 * Single-responsibility: validate incoming AJAX requests,
 * dispatch to the correct service, and return JSON responses.
 *
 * Every handler follows the same contract:
 *   1. Verify nonce
 *   2. Check capability
 *   3. Read + sanitise input
 *   4. Delegate to Nextcloud_Client / NC_Sync
 *   5. wp_send_json_success() or wp_send_json_error()
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class NC_Ajax {

    const NONCE_ACTION = 'wpnc_nonce';
    const CAPABILITY   = 'manage_options';

    public function __construct() {
        $actions = array(
            'wpnc_browse',           // List a Nextcloud folder
            'wpnc_file_info',        // Get info for one file
            'wpnc_create_folder',    // Create a folder on NC
            'wpnc_delete',           // Delete a file/folder on NC
            'wpnc_get_public_url',   // Get/create a share link
            'wpnc_download_proxy',   // Proxy-stream a file to browser
            'wpnc_sync_start',       // Trigger WP→NC sync
            'wpnc_upload_simple',    // Small file upload (browser → WP → NC)
            'wpnc_upload_init',      // Chunked upload: init temp dir on NC
            'wpnc_upload_chunk',     // Chunked upload: send one chunk
            'wpnc_upload_finish',    // Chunked upload: assemble on NC
        );

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, array( $this, 'dispatch' ) );
        }

        // Public proxy for viewing shared files (no login needed).
        add_action( 'wp_ajax_nopriv_wpnc_download_proxy', array( $this, 'handle_download_proxy' ) );
    }

    /* ================================================================
     *  DISPATCHER
     * ============================================================= */

    /**
     * Route the current request to the matching handler method.
     */
    public function dispatch() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        $map = array(
            'wpnc_browse'         => 'handle_browse',
            'wpnc_file_info'      => 'handle_file_info',
            'wpnc_create_folder'  => 'handle_create_folder',
            'wpnc_delete'         => 'handle_delete',
            'wpnc_get_public_url' => 'handle_get_public_url',
            'wpnc_download_proxy' => 'handle_download_proxy',
            'wpnc_sync_start'     => 'handle_sync_start',
            'wpnc_upload_simple'  => 'handle_upload_simple',
            'wpnc_upload_init'    => 'handle_upload_init',
            'wpnc_upload_chunk'   => 'handle_upload_chunk',
            'wpnc_upload_finish'  => 'handle_upload_finish',
        );

        if ( ! isset( $map[ $action ] ) ) {
            wp_send_json_error( __( 'Unknown action.', 'wp-nc-bridge' ), 400 );
        }

        call_user_func( array( $this, $map[ $action ] ) );
    }

    /* ================================================================
     *  HANDLERS
     * ============================================================= */

    /**
     * Browse: list the contents of a Nextcloud folder.
     *
     * Input:  path (string, optional)
     * Output: items[] — each with name, type, size, mime, fileid
     */
    public function handle_browse() {
        $this->verify_admin_request();

        $path   = $this->input( 'path', '' );
        $client = $this->client_or_die();

        $items = $client->list_folder( $path );
        if ( is_wp_error( $items ) ) {
            wp_send_json_error( $items->get_error_message() );
        }

        wp_send_json_success( array(
            'path'  => $path,
            'items' => $items,
        ) );
    }

    /**
     * File info: return metadata for a single path.
     *
     * Input:  path (string)
     */
    public function handle_file_info() {
        $this->verify_admin_request();

        $path   = $this->input( 'path' );
        $client = $this->client_or_die();

        $info = $client->get_info( $path );
        if ( is_wp_error( $info ) ) {
            wp_send_json_error( $info->get_error_message() );
        }

        wp_send_json_success( $info );
    }

    /**
     * Create folder on Nextcloud.
     *
     * Input:  path (string)
     */
    public function handle_create_folder() {
        $this->verify_admin_request();

        $path   = $this->input( 'path' );
        $client = $this->client_or_die();

        $result = $client->create_folder( $path );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'created' => $path ) );
    }

    /**
     * Delete a file or folder on Nextcloud.
     *
     * Input:  path (string)
     */
    public function handle_delete() {
        $this->verify_admin_request();

        $path   = $this->input( 'path' );
        $client = $this->client_or_die();

        $result = $client->delete( $path );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'deleted' => $path ) );
    }

    /**
     * Get (or create) a public share link.
     *
     * Input:  path (string)
     * Output: url (string)
     */
    public function handle_get_public_url() {
        $this->verify_admin_request();

        $path   = $this->input( 'path' );
        $client = $this->client_or_die();

        $url = $client->get_public_url( $path );
        if ( is_wp_error( $url ) ) {
            wp_send_json_error( $url->get_error_message() );
        }

        wp_send_json_success( array( 'url' => $url ) );
    }

    /**
     * Proxy a file download through WordPress.
     *
     * This allows the browser to fetch files without exposing NC credentials.
     * Works for both logged-in and anonymous users (if the admin configured it).
     *
     * Input:  path (string), nonce via _nonce
     */
    public function handle_download_proxy() {
        // For nopriv access we use a separate signed-token mechanism.
        // For admin access we use the standard nonce.
        if ( current_user_can( self::CAPABILITY ) ) {
            $this->verify_admin_request();
        } else {
            // Verify a time-limited token (generated elsewhere and appended to URL).
            $token = sanitize_text_field( $_GET['_wpnc_token'] ?? '' );
            $path  = sanitize_text_field( $_GET['path'] ?? '' );

            if ( ! $this->verify_download_token( $token, $path ) ) {
                wp_die( esc_html__( 'Invalid or expired download link.', 'wp-nc-bridge' ), 403 );
            }
        }

        $path   = $this->input( 'path' );
        $client = $this->client_or_die();

        $content = $client->download( $path );
        if ( is_wp_error( $content ) ) {
            wp_die( esc_html( $content->get_error_message() ), 502 );
        }

        // Guess MIME type from extension.
        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime = wp_check_filetype( 'file.' . $ext )['type'] ?: 'application/octet-stream';

        // Stream response.
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . strlen( $content ) );
        header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );
        header( 'Cache-Control: private, max-age=3600' );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary stream
        echo $content;
        exit;
    }

    /**
     * Start a WP → Nextcloud sync for configured directory pairs.
     *
     * Returns a log of operations performed.
     */
    public function handle_sync_start() {
        $this->verify_admin_request();

        $client = $this->client_or_die();

        $opts    = get_option( NC_Settings::OPTION_KEY, NC_Settings::defaults() );
        $dirs    = $opts['sync_dirs'] ?? array();
        $root    = $opts['nc_root_path'] ?? '/WordPress';
        $max_mb  = (int) ( $opts['max_file_size'] ?? 2048 );
        $max_bytes = $max_mb > 0 ? $max_mb * 1048576 : PHP_INT_MAX;

        $sync = new NC_Sync( $client, $max_bytes );

        if ( empty( $dirs ) ) {
            wp_send_json_error( __( 'No sync directories configured.', 'wp-nc-bridge' ) );
        }

        $log = array();

        foreach ( $dirs as $pair ) {
            $local_abs  = untrailingslashit( ABSPATH ) . '/' . ltrim( $pair['local'], '/' );
            $remote_rel = ltrim( $root, '/' ) . '/' . ltrim( $pair['remote'], '/' );

            if ( ! is_dir( $local_abs ) ) {
                $log[] = sprintf( '⚠ SKIP  Local dir not found: %s', $pair['local'] );
                continue;
            }

            $result = $sync->sync_directory( $local_abs, $remote_rel );
            if ( is_wp_error( $result ) ) {
                $log[] = sprintf( '✗ ERROR %s → %s : %s', $pair['local'], $pair['remote'], $result->get_error_message() );
            } else {
                $log = array_merge( $log, $result );
            }
        }

        wp_send_json_success( array( 'log' => $log ) );
    }

    /* ================================================================
     *  UPLOAD HANDLERS (browser → WP → Nextcloud)
     * ============================================================= */

    /**
     * Simple upload: small file (<= chunk size) sent as a single POST.
     *
     * Expects: $_FILES['file'], $_POST['path'] (remote destination folder).
     */
    public function handle_upload_simple() {
        $this->verify_admin_request();

        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( __( 'No file received.', 'wp-nc-bridge' ) );
        }

        $file = $_FILES['file'];
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( sprintf( __( 'Upload error code: %d', 'wp-nc-bridge' ), $file['error'] ) );
        }

        $dest_folder = $this->input( 'path', '' );
        $filename    = sanitize_file_name( $file['name'] );
        $remote_path = ( $dest_folder ? trim( $dest_folder, '/' ) . '/' : '' ) . $filename;

        $client = $this->client_or_die();
        $result = $client->upload( $file['tmp_name'], $remote_path );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'file'   => $filename,
            'remote' => $remote_path,
        ) );
    }

    /**
     * Chunked upload — Step 1: Create a temporary directory on Nextcloud.
     *
     * Input:  filename (string), path (destination folder)
     * Output: upload_id (UUID for subsequent chunk calls)
     */
    public function handle_upload_init() {
        $this->verify_admin_request();

        $filename = sanitize_file_name( $this->input( 'filename' ) );
        if ( empty( $filename ) ) {
            wp_send_json_error( __( 'Filename is required.', 'wp-nc-bridge' ) );
        }

        $dest_folder = $this->input( 'path', '' );
        $upload_id   = wp_generate_uuid4();

        $client = $this->client_or_die();

        // Create the temp chunk directory on NC: /uploads/{user}/{uuid}/
        $upload_url = $this->get_nc_upload_url( $client ) . $upload_id;
        $response   = $this->nc_raw_request( $client, 'MKCOL', $upload_url );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 && $code !== 405 ) {
            wp_send_json_error( sprintf( __( 'MKCOL chunk dir returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        wp_send_json_success( array(
            'upload_id' => $upload_id,
            'filename'  => $filename,
            'path'      => $dest_folder,
        ) );
    }

    /**
     * Chunked upload — Step 2: Upload one chunk.
     *
     * Expects: $_FILES['chunk'], $_POST['upload_id'], $_POST['offset']
     */
    public function handle_upload_chunk() {
        $this->verify_admin_request();

        if ( empty( $_FILES['chunk'] ) ) {
            wp_send_json_error( __( 'No chunk data received.', 'wp-nc-bridge' ) );
        }

        $chunk_file = $_FILES['chunk'];
        if ( $chunk_file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( sprintf( __( 'Chunk upload error code: %d', 'wp-nc-bridge' ), $chunk_file['error'] ) );
        }

        $upload_id = sanitize_text_field( $this->input( 'upload_id' ) );
        $offset    = (int) $this->input( 'offset', '0' );
        $chunk_data = file_get_contents( $chunk_file['tmp_name'] );

        if ( $chunk_data === false ) {
            wp_send_json_error( __( 'Cannot read chunk temp file.', 'wp-nc-bridge' ) );
        }

        $end       = $offset + strlen( $chunk_data );
        $chunk_name = str_pad( $offset, 15, '0', STR_PAD_LEFT ) . '-' . str_pad( $end, 15, '0', STR_PAD_LEFT );

        $client   = $this->client_or_die();
        $chunk_url = $this->get_nc_upload_url( $client ) . $upload_id . '/' . $chunk_name;

        $response = $this->nc_raw_request( $client, 'PUT', $chunk_url, array(
            'headers' => array( 'Content-Type' => 'application/octet-stream' ),
            'body'    => $chunk_data,
            'timeout' => 120,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 && $code !== 204 ) {
            wp_send_json_error( sprintf( __( 'Chunk PUT returned HTTP %d at offset %d.', 'wp-nc-bridge' ), $code, $offset ) );
        }

        wp_send_json_success( array(
            'offset' => $offset,
            'end'    => $end,
            'size'   => strlen( $chunk_data ),
        ) );
    }

    /**
     * Chunked upload — Step 3: Assemble chunks into final file.
     *
     * Input: upload_id, filename, path (destination folder)
     */
    public function handle_upload_finish() {
        $this->verify_admin_request();

        $upload_id = sanitize_text_field( $this->input( 'upload_id' ) );
        $filename  = sanitize_file_name( $this->input( 'filename' ) );
        $dest_folder = $this->input( 'path', '' );

        if ( empty( $upload_id ) || empty( $filename ) ) {
            wp_send_json_error( __( 'Missing upload_id or filename.', 'wp-nc-bridge' ) );
        }

        $remote_path = ( $dest_folder ? trim( $dest_folder, '/' ) . '/' : '' ) . $filename;

        $client    = $this->client_or_die();
        $source    = $this->get_nc_upload_url( $client ) . $upload_id . '/.file';
        $dest_url  = $this->get_nc_dav_url( $client ) . $this->encode_path( $remote_path );

        $response = $this->nc_raw_request( $client, 'MOVE', $source, array(
            'headers' => array( 'Destination' => $dest_url ),
            'timeout' => 120,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 && $code !== 204 ) {
            wp_send_json_error( sprintf( __( 'Chunk assembly MOVE returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        wp_send_json_success( array(
            'file'   => $filename,
            'remote' => $remote_path,
        ) );
    }

    /* ================================================================
     *  UPLOAD HELPERS
     * ============================================================= */

    /**
     * Get the NC uploads endpoint URL for the current user.
     */
    private function get_nc_upload_url( Nextcloud_Client $client ): string {
        $opts = get_option( NC_Settings::OPTION_KEY, NC_Settings::defaults() );
        $base = rtrim( $opts['nc_url'] ?? '', '/' );
        $user = $opts['nc_username'] ?? '';
        return $base . '/remote.php/dav/uploads/' . rawurlencode( $user ) . '/';
    }

    /**
     * Get the NC DAV files endpoint URL for the current user.
     */
    private function get_nc_dav_url( Nextcloud_Client $client ): string {
        $opts = get_option( NC_Settings::OPTION_KEY, NC_Settings::defaults() );
        $base = rtrim( $opts['nc_url'] ?? '', '/' );
        $user = $opts['nc_username'] ?? '';
        return $base . '/remote.php/dav/files/' . rawurlencode( $user ) . '/';
    }

    /**
     * Encode each segment of a remote path for use in a URL.
     */
    private function encode_path( string $path ): string {
        $path     = trim( $path, '/' );
        $segments = explode( '/', $path );
        $encoded  = array_map( 'rawurlencode', $segments );
        return implode( '/', $encoded );
    }

    /**
     * Send a raw authenticated request to Nextcloud using the same
     * transport-layer workaround as Nextcloud_Client::request().
     *
     * This is needed because Nextcloud_Client::request() is private.
     *
     * @param Nextcloud_Client $client  Used only to confirm configuration.
     * @param string           $method  HTTP method.
     * @param string           $url     Full URL.
     * @param array            $extra   Additional wp_remote_request args.
     * @return array|\WP_Error
     */
    private function nc_raw_request( Nextcloud_Client $client, string $method, string $url, array $extra = array() ) {
        $opts = get_option( NC_Settings::OPTION_KEY, NC_Settings::defaults() );
        $auth = 'Basic ' . base64_encode( ( $opts['nc_username'] ?? '' ) . ':' . ( $opts['nc_password'] ?? '' ) );

        $defaults = array(
            'method'      => $method,
            'timeout'     => 30,
            'httpversion' => '1.1',
            'headers'     => array(),
            'sslverify'   => true,
        );

        // Merge headers.
        $headers = array_merge(
            $defaults['headers'],
            $extra['headers'] ?? array()
        );
        $args = array_merge( $defaults, $extra );
        $args['headers'] = $headers;
        $args['headers']['Authorization'] = $auth;

        // WebDAV body workaround (same as Nextcloud_Client::request).
        $webdav = array( 'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK', 'REPORT', 'SEARCH' );
        $body   = $args['body'] ?? '';
        $need   = in_array( $method, $webdav, true ) && $body !== '';

        $cb = null;
        if ( $need ) {
            $cb = function ( $handle ) use ( $body ) {
                curl_setopt( $handle, CURLOPT_POSTFIELDS, $body );
            };
            add_action( 'http_api_curl', $cb, 99 );
        }

        $response = wp_remote_request( $url, $args );

        if ( $cb !== null ) {
            remove_action( 'http_api_curl', $cb, 99 );
        }

        return $response;
    }

    /* ================================================================
     *  DOWNLOAD TOKEN (for nopriv proxy access)
     * ============================================================= */

    /**
     * Generate a time-limited HMAC token for a specific file path.
     *
     * @param string $path    Remote file path.
     * @param int    $ttl     Lifetime in seconds (default 1 hour).
     * @return string  Token string.
     */
    public static function generate_download_token( string $path, int $ttl = 3600 ): string {
        $expires = time() + $ttl;
        $payload = $expires . '|' . $path;
        $hmac    = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        return base64_encode( $expires . '|' . $hmac );
    }

    /**
     * Verify a download token.
     *
     * @param string $token  Base64-encoded token.
     * @param string $path   Path that was requested.
     * @return bool
     */
    private function verify_download_token( string $token, string $path ): bool {
        $decoded = base64_decode( $token, true );
        if ( $decoded === false ) {
            return false;
        }

        $parts = explode( '|', $decoded, 2 );
        if ( count( $parts ) !== 2 ) {
            return false;
        }

        list( $expires, $hmac ) = $parts;

        // Expired?
        if ( (int) $expires < time() ) {
            return false;
        }

        // Verify HMAC.
        $expected_payload = $expires . '|' . $path;
        $expected_hmac    = hash_hmac( 'sha256', $expected_payload, wp_salt( 'auth' ) );

        return hash_equals( $expected_hmac, $hmac );
    }

    /* ================================================================
     *  INTERNAL HELPERS
     * ============================================================= */

    /**
     * Verify nonce + capability for admin AJAX requests.
     */
    private function verify_admin_request() {
        check_ajax_referer( self::NONCE_ACTION, '_nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nc-bridge' ), 403 );
        }
    }

    /**
     * Get a sanitised input value from $_POST or $_GET.
     *
     * @param string $key      Parameter name.
     * @param mixed  $default  Fallback value.
     * @return string
     */
    private function input( string $key, $default = '' ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
        $raw = $_REQUEST[ $key ] ?? $default;
        return sanitize_text_field( $raw );
    }

    /**
     * Instantiate a Nextcloud_Client or die with JSON error.
     *
     * @return Nextcloud_Client
     */
    private function client_or_die(): Nextcloud_Client {
        $client = Nextcloud_Client::from_settings();
        if ( is_wp_error( $client ) ) {
            wp_send_json_error( $client->get_error_message() );
        }
        return $client;
    }
}
