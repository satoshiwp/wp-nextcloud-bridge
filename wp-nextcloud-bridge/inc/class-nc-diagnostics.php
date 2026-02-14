<?php
/**
 * Diagnostics Page
 *
 * Provides a detailed backend test interface that shows the raw HTTP
 * request and response for both `wp_remote_request()` and direct cURL,
 * making it easy to pinpoint transport-layer issues.
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class NC_Diagnostics {

    const PAGE_SLUG  = 'wpnc-diagnostics';
    const CAPABILITY = 'manage_options';

    /* ================================================================
     *  BOOTSTRAP
     * ============================================================= */

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_run' ) );
    }

    /* ================================================================
     *  MENU (sub-page under Settings)
     * ============================================================= */

    public function add_menu() {
        add_submenu_page(
            'options-general.php',
            __( 'NC Bridge Diagnostics', 'wp-nc-bridge' ),
            __( 'NC Diagnostics', 'wp-nc-bridge' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /* ================================================================
     *  PAGE RENDERER
     * ============================================================= */

    public function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $opts     = get_option( 'wpnc_settings', array() );
        $nc_url   = $opts['nc_url']      ?? '';
        $nc_user  = $opts['nc_username']  ?? '';
        $nc_pass  = $opts['nc_password']  ?? '';
        $has_conf = ( $nc_url && $nc_user && $nc_pass );

        // Results from the current test run (if any).
        $results = get_transient( 'wpnc_diag_results' );
        if ( $results ) {
            delete_transient( 'wpnc_diag_results' );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Nextcloud Bridge — Diagnostics', 'wp-nc-bridge' ); ?></h1>

            <?php if ( ! $has_conf ) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'Please configure the Nextcloud connection first (Settings → Nextcloud Bridge).', 'wp-nc-bridge' ); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Current Settings (masked)', 'wp-nc-bridge' ); ?></h2>
            <table class="widefat" style="max-width:600px">
                <tr><th>nc_url</th><td><code><?php echo esc_html( $nc_url ); ?></code></td></tr>
                <tr><th>nc_username</th><td><code><?php echo esc_html( $nc_user ); ?></code></td></tr>
                <tr><th>nc_password</th><td><code><?php echo $nc_pass ? esc_html( substr( $nc_pass, 0, 3 ) . '***' . substr( $nc_pass, -3 ) ) : '(empty)'; ?></code></td></tr>
                <tr><th>DAV URL</th><td><code><?php echo esc_html( $nc_url . '/remote.php/dav/files/' . rawurlencode( $nc_user ) . '/' ); ?></code></td></tr>
            </table>

            <h2 style="margin-top:24px"><?php esc_html_e( 'Run Diagnostic Tests', 'wp-nc-bridge' ); ?></h2>
            <p class="description"><?php esc_html_e( 'This will send test requests to your Nextcloud server and display the full raw results.', 'wp-nc-bridge' ); ?></p>

            <form method="post">
                <?php wp_nonce_field( 'wpnc_run_diag', '_wpnc_diag_nonce' ); ?>
                <p>
                    <label>
                        <input type="checkbox" name="test_wp_http" value="1" checked />
                        <?php esc_html_e( 'Test 1: PROPFIND via wp_remote_request() (with http_api_curl filter)', 'wp-nc-bridge' ); ?>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="test_raw_curl" value="1" checked />
                        <?php esc_html_e( 'Test 2: PROPFIND via direct cURL (bypass WordPress HTTP API)', 'wp-nc-bridge' ); ?>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="test_head" value="1" />
                        <?php esc_html_e( 'Test 3: Simple GET on Nextcloud status.php', 'wp-nc-bridge' ); ?>
                    </label>
                </p>
                <?php submit_button( __( 'Run Tests', 'wp-nc-bridge' ), 'primary', 'wpnc_run_diag', false ); ?>
            </form>

            <?php if ( $results ) : ?>
                <hr />
                <h2><?php esc_html_e( 'Test Results', 'wp-nc-bridge' ); ?></h2>
                <?php foreach ( $results as $label => $data ) : ?>
                    <div style="margin-bottom:32px;">
                        <h3><?php echo esc_html( $label ); ?></h3>
                        <pre style="background:#1d2327;color:#c3c4c7;padding:16px;overflow:auto;max-height:500px;font-size:13px;line-height:1.6;border-radius:4px;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html( $data ); ?></pre>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ================================================================
     *  HANDLE TEST RUN
     * ============================================================= */

    public function handle_run() {
        if ( ! isset( $_POST['wpnc_run_diag'] ) ) {
            return;
        }
        if ( ! check_admin_referer( 'wpnc_run_diag', '_wpnc_diag_nonce' ) ) {
            return;
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $opts    = get_option( 'wpnc_settings', array() );
        $nc_url  = rtrim( $opts['nc_url'] ?? '', '/' );
        $nc_user = $opts['nc_username'] ?? '';
        $nc_pass = $opts['nc_password'] ?? '';
        $dav_url = $nc_url . '/remote.php/dav/files/' . rawurlencode( $nc_user ) . '/';

        $xml_body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                  . '<d:propfind xmlns:d="DAV:">' . "\n"
                  . '  <d:prop><d:resourcetype/></d:prop>' . "\n"
                  . '</d:propfind>';

        $auth_header = 'Basic ' . base64_encode( $nc_user . ':' . $nc_pass );

        $results = array();

        /* ── Test 1: wp_remote_request + http_api_curl filter ──── */
        if ( ! empty( $_POST['test_wp_http'] ) ) {
            $results['Test 1: wp_remote_request() + http_api_curl filter'] = $this->test_wp_http( $dav_url, $xml_body, $auth_header );
        }

        /* ── Test 2: Direct cURL ───────────────────────────────── */
        if ( ! empty( $_POST['test_raw_curl'] ) ) {
            $results['Test 2: Direct cURL (bypass WordPress)'] = $this->test_raw_curl( $dav_url, $xml_body, $auth_header );
        }

        /* ── Test 3: GET status.php ────────────────────────────── */
        if ( ! empty( $_POST['test_head'] ) ) {
            $results['Test 3: GET status.php (basic connectivity)'] = $this->test_status_page( $nc_url );
        }

        set_transient( 'wpnc_diag_results', $results, 120 );

        // Redirect back to the page (PRG pattern).
        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    /* ================================================================
     *  TEST 1: wp_remote_request with filter
     * ============================================================= */

    private function test_wp_http( string $dav_url, string $xml_body, string $auth_header ): string {
        $out = "── REQUEST ──\n";
        $out .= "Method:  PROPFIND\n";
        $out .= "URL:     {$dav_url}\n";
        $out .= "Headers:\n";
        $out .= "  Authorization: Basic ***\n";
        $out .= "  Content-Type:  text/xml; charset=UTF-8\n";
        $out .= "  Depth:         0\n";
        $out .= "Body:\n{$xml_body}\n";
        $out .= "Body length: " . strlen( $xml_body ) . " bytes\n\n";

        // Track what the filter actually does.
        $filter_fired = false;
        $filter_info  = '';

        $filter_cb = function ( $handle ) use ( $xml_body, &$filter_fired, &$filter_info ) {
            $filter_fired = true;
            curl_setopt( $handle, CURLOPT_POSTFIELDS, $xml_body );
            $filter_info = 'CURLOPT_POSTFIELDS set (' . strlen( $xml_body ) . ' bytes)';
        };

        add_action( 'http_api_curl', $filter_cb, 99 );

        $start = microtime( true );
        $response = wp_remote_request( $dav_url, array(
            'method'      => 'PROPFIND',
            'timeout'     => 30,
            'httpversion' => '1.1',
            'sslverify'   => true,
            'headers'     => array(
                'Authorization' => $auth_header,
                'Content-Type'  => 'text/xml; charset=UTF-8',
                'Depth'         => '0',
            ),
            'body'        => $xml_body,
        ) );
        $elapsed = round( ( microtime( true ) - $start ) * 1000 );

        remove_action( 'http_api_curl', $filter_cb, 99 );

        $out .= "── FILTER STATUS ──\n";
        $out .= "http_api_curl fired: " . ( $filter_fired ? 'YES' : 'NO ← PROBLEM!' ) . "\n";
        if ( $filter_info ) {
            $out .= "Filter action: {$filter_info}\n";
        }
        $out .= "\n";

        $out .= "── RESPONSE ({$elapsed}ms) ──\n";

        if ( is_wp_error( $response ) ) {
            $out .= "WP_Error code:    " . $response->get_error_code() . "\n";
            $out .= "WP_Error message: " . $response->get_error_message() . "\n";
            return $out;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $message = wp_remote_retrieve_response_message( $response );
        $headers = wp_remote_retrieve_headers( $response );
        $body    = wp_remote_retrieve_body( $response );

        $out .= "HTTP Status: {$code} {$message}\n\n";
        $out .= "Response Headers:\n";
        foreach ( $headers as $key => $val ) {
            if ( is_array( $val ) ) {
                $val = implode( ', ', $val );
            }
            $out .= "  {$key}: {$val}\n";
        }
        $out .= "\nResponse Body (" . strlen( $body ) . " bytes):\n";
        $out .= substr( $body, 0, 5000 );
        if ( strlen( $body ) > 5000 ) {
            $out .= "\n... (truncated)";
        }

        return $out;
    }

    /* ================================================================
     *  TEST 2: Direct cURL
     * ============================================================= */

    private function test_raw_curl( string $dav_url, string $xml_body, string $auth_header ): string {
        if ( ! function_exists( 'curl_init' ) ) {
            return "cURL extension is NOT available on this server.";
        }

        $out = "── REQUEST ──\n";
        $out .= "Method:  PROPFIND\n";
        $out .= "URL:     {$dav_url}\n";
        $out .= "Headers:\n";
        $out .= "  Authorization: Basic ***\n";
        $out .= "  Content-Type:  text/xml; charset=UTF-8\n";
        $out .= "  Depth:         0\n";
        $out .= "Body:\n{$xml_body}\n";
        $out .= "Body length: " . strlen( $xml_body ) . " bytes\n\n";

        $ch = curl_init();

        $resp_headers_raw = '';
        curl_setopt_array( $ch, array(
            CURLOPT_URL            => $dav_url,
            CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
            CURLOPT_POSTFIELDS     => $xml_body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: ' . $auth_header,
                'Content-Type: text/xml; charset=UTF-8',
                'Depth: 0',
                'Content-Length: ' . strlen( $xml_body ),
            ),
            CURLOPT_HEADERFUNCTION => function ( $ch, $header ) use ( &$resp_headers_raw ) {
                $resp_headers_raw .= $header;
                return strlen( $header );
            },
        ) );

        // Verbose debug.
        $verbose_handle = fopen( 'php://temp', 'w+' );
        curl_setopt( $ch, CURLOPT_VERBOSE, true );
        curl_setopt( $ch, CURLOPT_STDERR, $verbose_handle );

        $start = microtime( true );
        $body  = curl_exec( $ch );
        $elapsed = round( ( microtime( true ) - $start ) * 1000 );

        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_err  = curl_error( $ch );
        $curl_errno = curl_errno( $ch );
        $info       = curl_getinfo( $ch );
        curl_close( $ch );

        // Read verbose log.
        rewind( $verbose_handle );
        $verbose_log = stream_get_contents( $verbose_handle );
        fclose( $verbose_handle );

        $out .= "── RESPONSE ({$elapsed}ms) ──\n";

        if ( $curl_errno ) {
            $out .= "cURL Error #{$curl_errno}: {$curl_err}\n";
            return $out;
        }

        $out .= "HTTP Status: {$http_code}\n\n";
        $out .= "Response Headers:\n{$resp_headers_raw}\n";
        $out .= "Response Body (" . strlen( $body ) . " bytes):\n";
        $out .= substr( $body, 0, 5000 );
        if ( strlen( $body ) > 5000 ) {
            $out .= "\n... (truncated)";
        }

        $out .= "\n\n── cURL VERBOSE LOG ──\n";
        // Mask the auth header in verbose output.
        $verbose_log = preg_replace( '/Authorization: Basic .+/', 'Authorization: Basic ***', $verbose_log );
        $out .= $verbose_log;

        $out .= "\n── cURL INFO ──\n";
        $out .= "Total time:     " . round( $info['total_time'], 3 ) . "s\n";
        $out .= "DNS time:       " . round( $info['namelookup_time'], 3 ) . "s\n";
        $out .= "Connect time:   " . round( $info['connect_time'], 3 ) . "s\n";
        $out .= "SSL time:       " . round( $info['appconnect_time'], 3 ) . "s\n";
        $out .= "Upload size:    " . $info['size_upload'] . " bytes\n";
        $out .= "Download size:  " . $info['size_download'] . " bytes\n";
        $out .= "Primary IP:     " . ( $info['primary_ip'] ?? 'N/A' ) . "\n";
        $out .= "SSL verify:     " . ( $info['ssl_verify_result'] === 0 ? 'OK' : 'FAIL(' . $info['ssl_verify_result'] . ')' ) . "\n";

        return $out;
    }

    /* ================================================================
     *  TEST 3: GET status.php
     * ============================================================= */

    private function test_status_page( string $nc_url ): string {
        $url = $nc_url . '/status.php';

        $out = "── REQUEST ──\n";
        $out .= "Method: GET\n";
        $out .= "URL:    {$url}\n\n";

        $start = microtime( true );
        $response = wp_remote_get( $url, array(
            'timeout'   => 15,
            'sslverify' => true,
        ) );
        $elapsed = round( ( microtime( true ) - $start ) * 1000 );

        $out .= "── RESPONSE ({$elapsed}ms) ──\n";

        if ( is_wp_error( $response ) ) {
            $out .= "WP_Error: " . $response->get_error_message() . "\n";
            return $out;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        $out .= "HTTP Status: {$code}\n\n";
        $out .= "Body:\n{$body}\n\n";

        // Try to parse as JSON.
        $json = json_decode( $body, true );
        if ( $json ) {
            $out .= "── Parsed Nextcloud Status ──\n";
            foreach ( $json as $k => $v ) {
                $out .= "  {$k}: " . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : $v ) . "\n";
            }
        }

        $out .= "\n── PHP & Transport Info ──\n";
        $out .= "PHP version:     " . PHP_VERSION . "\n";
        $out .= "cURL available:  " . ( function_exists( 'curl_init' ) ? 'YES' : 'NO' ) . "\n";
        if ( function_exists( 'curl_version' ) ) {
            $cv = curl_version();
            $out .= "cURL version:    " . $cv['version'] . "\n";
            $out .= "SSL version:     " . $cv['ssl_version'] . "\n";
        }
        $out .= "WP HTTP trans:   " . $this->detect_wp_transport() . "\n";
        $out .= "OpenSSL:         " . ( defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'N/A' ) . "\n";

        return $out;
    }

    /* ================================================================
     *  HELPERS
     * ============================================================= */

    /**
     * Detect which transport WordPress is using for HTTP requests.
     */
    private function detect_wp_transport(): string {
        if ( function_exists( 'curl_init' ) && class_exists( 'WP_Http_Curl' ) ) {
            return 'WP_Http_Curl (cURL)';
        }
        if ( class_exists( 'WP_Http_Streams' ) ) {
            return 'WP_Http_Streams (PHP streams)';
        }
        return 'Unknown';
    }
}
