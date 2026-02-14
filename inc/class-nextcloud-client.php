<?php
/**
 * Nextcloud WebDAV & OCS Client
 *
 * Single-responsibility: speak the WebDAV/OCS protocol with a Nextcloud server.
 * Every public method returns either a value or WP_Error.
 * No WordPress side-effects (no DB writes, no hooks) — pure I/O.
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class Nextcloud_Client {

    /* ─── Config ────────────────────────────────────────────── */

    /** @var string e.g. https://cloud.example.com */
    private $base_url;

    /** @var string Nextcloud username */
    private $username;

    /** @var string Nextcloud password (or app-password) */
    private $password;

    /** @var int Seconds before a request is abandoned */
    private $timeout;

    /* ─── Derived URLs ──────────────────────────────────────── */

    /** @var string WebDAV file endpoint: {base}/remote.php/dav/files/{user}/ */
    private $dav_url;

    /** @var string WebDAV upload endpoint: {base}/remote.php/dav/uploads/{user}/ */
    private $upload_url;

    /** @var string OCS share API endpoint */
    private $ocs_url;

    /* ─── Chunked-upload tuning ─────────────────────────────── */

    /** 10 MB — chunk size for large-file uploads (Nextcloud allows up to 100 MB per chunk). */
    const CHUNK_SIZE = 10485760;

    /* ================================================================
     *  CONSTRUCTOR
     * ============================================================= */

    /**
     * @param string $base_url  Nextcloud root URL (no trailing slash).
     * @param string $username  Nextcloud username.
     * @param string $password  Password or App-Password.
     * @param int    $timeout   HTTP timeout in seconds (default 30).
     */
    public function __construct( string $base_url, string $username, string $password, int $timeout = 30 ) {
        $this->base_url  = rtrim( $base_url, '/' );
        $this->username  = $username;
        $this->password  = $password;
        $this->timeout   = $timeout;

        $this->dav_url    = $this->base_url . '/remote.php/dav/files/' . rawurlencode( $this->username ) . '/';
        $this->upload_url = $this->base_url . '/remote.php/dav/uploads/' . rawurlencode( $this->username ) . '/';
        $this->ocs_url    = $this->base_url . '/ocs/v2.php/apps/files_sharing/api/v1/shares';
    }

    /**
     * Build a client from the unified plugin settings array.
     *
     * @return self|WP_Error
     */
    public static function from_settings() {
        $opts = get_option( 'wpnc_settings', array() );

        if ( empty( $opts['nc_url'] ) || empty( $opts['nc_username'] ) || empty( $opts['nc_password'] ) ) {
            return new \WP_Error( 'wpnc_not_configured', __( 'Nextcloud connection is not configured.', 'wp-nc-bridge' ) );
        }

        return new self( $opts['nc_url'], $opts['nc_username'], $opts['nc_password'] );
    }

    /* ================================================================
     *  CONNECTION TEST
     * ============================================================= */

    /**
     * Connectivity check via PROPFIND Depth:0 on the user's DAV root.
     *
     * HEAD is unreliable on many Nextcloud setups (returns 400).
     * PROPFIND Depth:0 is the canonical WebDAV "ping" — lightweight
     * and always supported.
     *
     * @return true|\WP_Error
     */
    public function test_connection() {
        $xml_body = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:resourcetype/>
  </d:prop>
</d:propfind>';

        $response = $this->request( 'PROPFIND', $this->dav_url, array(
            'headers' => array(
                'Content-Type' => 'text/xml; charset=UTF-8',
                'Depth'        => '0',
            ),
            'body' => $xml_body,
        ) );

        if ( is_wp_error( $response ) ) {
            // Provide a clearer message for common cURL / DNS failures.
            $msg = $response->get_error_message();
            return new \WP_Error(
                'wpnc_connection_failed',
                sprintf( __( 'Could not reach Nextcloud: %s', 'wp-nc-bridge' ), $msg )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );

        // 207 Multi-Status = success for PROPFIND.
        if ( $code === 207 ) {
            return true;
        }

        // 401 / 403 = credentials wrong.
        if ( $code === 401 || $code === 403 ) {
            return new \WP_Error(
                'wpnc_auth_failed',
                __( 'Authentication failed. Check your username and password (or App-Password).', 'wp-nc-bridge' )
            );
        }

        // 404 = URL or username wrong.
        if ( $code === 404 ) {
            return new \WP_Error(
                'wpnc_not_found',
                __( 'WebDAV endpoint not found. Check the Nextcloud URL and username.', 'wp-nc-bridge' )
            );
        }

        return new \WP_Error(
            'wpnc_connection_failed',
            sprintf( __( 'Nextcloud responded with HTTP %d.', 'wp-nc-bridge' ), $code )
        );
    }

    /* ================================================================
     *  DIRECTORY OPERATIONS
     * ============================================================= */

    /**
     * List the contents of a remote folder.
     *
     * Returns an indexed array of items, each containing:
     *   href, name, type ('file'|'folder'), size, mime, fileid
     *
     * @param string $path  Remote path relative to user root (e.g. "Documents/photos").
     * @return array|\WP_Error
     */
    public function list_folder( string $path = '' ) {
        $url = $this->dav_url . $this->encode_path( $path );

        // Ensure trailing slash for PROPFIND on collections
        $url = rtrim( $url, '/' ) . '/';

        $xml_body = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <d:resourcetype/>
    <d:getcontenttype/>
    <d:getcontentlength/>
    <d:getlastmodified/>
    <oc:fileid/>
    <oc:size/>
  </d:prop>
</d:propfind>';

        $response = $this->request( 'PROPFIND', $url, array(
            'headers' => array(
                'Content-Type' => 'text/xml; charset=UTF-8',
                'Depth'        => '1',
            ),
            'body' => $xml_body,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 207 ) {
            return new \WP_Error( 'wpnc_propfind_failed', sprintf( __( 'PROPFIND returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        return $this->parse_propfind( wp_remote_retrieve_body( $response ), $path );
    }

    /**
     * Create a folder (and its parents) on Nextcloud.
     *
     * @param string $path  Remote path relative to user root.
     * @return true|\WP_Error
     */
    public function create_folder( string $path ) {
        // Ensure all ancestors exist first (recursive, root-down).
        $segments = array_filter( explode( '/', trim( $path, '/' ) ) );
        $current  = '';

        foreach ( $segments as $segment ) {
            $current .= $segment . '/';
            $url      = $this->dav_url . $this->encode_path( $current );

            $response = $this->request( 'MKCOL', $url );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            // 201 = created, 405 = already exists — both are fine.
            if ( $code !== 201 && $code !== 405 ) {
                return new \WP_Error(
                    'wpnc_mkcol_failed',
                    sprintf( __( 'MKCOL "%s" returned HTTP %d.', 'wp-nc-bridge' ), $current, $code )
                );
            }
        }

        return true;
    }

    /**
     * Delete a remote file or folder.
     *
     * @param string $path  Remote path relative to user root.
     * @return true|\WP_Error
     */
    public function delete( string $path ) {
        $url      = $this->dav_url . $this->encode_path( $path );
        $response = $this->request( 'DELETE', $url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        // 204 = deleted, 404 = already gone — both acceptable.
        if ( $code !== 204 && $code !== 404 ) {
            return new \WP_Error( 'wpnc_delete_failed', sprintf( __( 'DELETE returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        return true;
    }

    /**
     * Move / rename a remote resource.
     *
     * @param string $from_path  Current remote path.
     * @param string $to_path    New remote path.
     * @return true|\WP_Error
     */
    public function move( string $from_path, string $to_path ) {
        $from_url = $this->dav_url . $this->encode_path( $from_path );
        $to_url   = $this->dav_url . $this->encode_path( $to_path );

        $response = $this->request( 'MOVE', $from_url, array(
            'headers' => array( 'Destination' => $to_url ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 && $code !== 204 ) {
            return new \WP_Error( 'wpnc_move_failed', sprintf( __( 'MOVE returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        return true;
    }

    /* ================================================================
     *  FILE UPLOAD
     * ============================================================= */

    /**
     * Upload a local file to Nextcloud.
     *
     * Automatically chooses simple PUT or chunked upload based on file size.
     *
     * @param string $local_path   Absolute local file path.
     * @param string $remote_path  Destination path relative to user root.
     * @return true|\WP_Error
     */
    public function upload( string $local_path, string $remote_path ) {
        if ( ! file_exists( $local_path ) || ! is_readable( $local_path ) ) {
            return new \WP_Error( 'wpnc_file_not_found', sprintf( __( 'Local file not found: %s', 'wp-nc-bridge' ), $local_path ) );
        }

        $size = filesize( $local_path );

        if ( $size <= self::CHUNK_SIZE ) {
            return $this->upload_simple( $local_path, $remote_path );
        }

        return $this->upload_chunked( $local_path, $remote_path );
    }

    /**
     * Simple PUT upload (files ≤ CHUNK_SIZE).
     */
    private function upload_simple( string $local_path, string $remote_path ) {
        $url  = $this->dav_url . $this->encode_path( $remote_path );
        $body = file_get_contents( $local_path );

        $response = $this->request( 'PUT', $url, array(
            'headers' => array( 'Content-Type' => 'application/octet-stream' ),
            'body'    => $body,
            'timeout' => max( $this->timeout, 60 ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 && $code !== 204 ) {
            return new \WP_Error( 'wpnc_put_failed', sprintf( __( 'PUT returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        return true;
    }

    /**
     * Chunked upload for large files (Nextcloud recommended method).
     *
     * Flow:
     *   1. MKCOL  uploads/{user}/{uuid}/          — create temp directory
     *   2. PUT    uploads/{user}/{uuid}/0000-4MB   — upload each chunk
     *   3. MOVE   uploads/{user}/{uuid}/.file → dav/files/{user}/{dest}
     */
    private function upload_chunked( string $local_path, string $remote_path ) {
        $uuid      = wp_generate_uuid4();
        $chunk_dir = $this->upload_url . $uuid;

        /* Step 1 — Create chunk directory */
        $response = $this->request( 'MKCOL', $chunk_dir );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 && $code !== 405 ) {
            return new \WP_Error( 'wpnc_chunk_mkcol_failed', sprintf( __( 'Chunk MKCOL returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        /* Step 2 — Upload chunks */
        $handle = fopen( $local_path, 'rb' );
        if ( ! $handle ) {
            return new \WP_Error( 'wpnc_fopen_failed', __( 'Cannot open local file for reading.', 'wp-nc-bridge' ) );
        }

        $offset = 0;
        while ( ! feof( $handle ) ) {
            $chunk = fread( $handle, self::CHUNK_SIZE );
            if ( $chunk === false ) {
                fclose( $handle );
                // Try to clean up the temp directory on NC
                $this->request( 'DELETE', $chunk_dir );
                return new \WP_Error( 'wpnc_fread_failed', __( 'Failed to read local file chunk.', 'wp-nc-bridge' ) );
            }

            $end       = $offset + strlen( $chunk );
            $chunk_url = $chunk_dir . '/' . str_pad( $offset, 15, '0', STR_PAD_LEFT ) . '-' . str_pad( $end, 15, '0', STR_PAD_LEFT );

            $resp = $this->request( 'PUT', $chunk_url, array(
                'headers' => array( 'Content-Type' => 'application/octet-stream' ),
                'body'    => $chunk,
                'timeout' => max( $this->timeout, 120 ),
            ) );

            if ( is_wp_error( $resp ) ) {
                fclose( $handle );
                $this->request( 'DELETE', $chunk_dir );
                return $resp;
            }

            $code = wp_remote_retrieve_response_code( $resp );
            if ( $code !== 201 && $code !== 204 ) {
                fclose( $handle );
                $this->request( 'DELETE', $chunk_dir );
                return new \WP_Error( 'wpnc_chunk_put_failed', sprintf( __( 'Chunk PUT returned HTTP %d at offset %d.', 'wp-nc-bridge' ), $code, $offset ) );
            }

            $offset = $end;
        }
        fclose( $handle );

        /* Step 3 — Assemble: MOVE .file → final destination */
        $dest_url = $this->dav_url . $this->encode_path( $remote_path );

        $resp = $this->request( 'MOVE', $chunk_dir . '/.file', array(
            'headers' => array( 'Destination' => $dest_url ),
            'timeout' => max( $this->timeout, 120 ),
        ) );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 201 && $code !== 204 ) {
            return new \WP_Error( 'wpnc_chunk_move_failed', sprintf( __( 'Chunk assembly MOVE returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        return true;
    }

    /**
     * Upload a file directly from a URL to Nextcloud (no temp file).
     *
     * Downloads the entire source via a single cURL GET and uses
     * CURLOPT_WRITEFUNCTION to buffer data in CHUNK_SIZE pieces.
     * When a chunk is full it is immediately PUT to Nextcloud.
     *
     * - Small files (≤ CHUNK_SIZE): simple PUT.
     * - Large files: NC chunked upload (MKCOL → PUT chunks → MOVE).
     * - Does NOT require allow_url_fopen.
     * - Does NOT require HTTP Range support on the source server.
     * - Peak memory ≈ CHUNK_SIZE (10 MB).
     *
     * @param string $source_url   HTTP(S) URL of the source file.
     * @param string $remote_path  Destination path relative to user root.
     * @return true|\WP_Error
     */
    public function upload_from_url( string $source_url, string $remote_path ) {

        error_log( '[WPNC] upload_from_url: ' . $source_url . ' → ' . $remote_path );

        /*
         * State shared between the cURL write-callback and this method.
         * The callback buffers incoming data; when buffer ≥ CHUNK_SIZE
         * it flushes a chunk to Nextcloud.
         */
        $state = (object) array(
            'buffer'    => '',
            'offset'    => 0,       // total bytes flushed to NC so far
            'chunk_num' => 0,
            'chunk_dir' => '',      // set after MKCOL (large file path)
            'uuid'      => '',
            'is_large'  => false,   // flipped to true once we exceed one chunk
            'error'     => null,    // WP_Error if a NC upload fails inside the callback
            'client'    => $this,
        );

        // ── cURL: stream source URL ─────────────────────────────
        $ch = curl_init();
        curl_setopt_array( $ch, array(
            CURLOPT_URL            => $source_url,
            CURLOPT_RETURNTRANSFER => false,  // we handle data in WRITEFUNCTION
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 0,      // no timeout — large files take time
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_BUFFERSIZE     => 131072,  // 128 KB internal cURL buffer
        ) );

        $chunk_size = self::CHUNK_SIZE;

        curl_setopt( $ch, CURLOPT_WRITEFUNCTION,
            function ( $ch, $data ) use ( $state, $chunk_size ) {
                // Abort if a previous NC upload already failed.
                if ( $state->error !== null ) {
                    return -1;  // tell cURL to stop
                }

                $state->buffer .= $data;

                // Flush full chunks.
                while ( strlen( $state->buffer ) >= $chunk_size ) {
                    $chunk = substr( $state->buffer, 0, $chunk_size );
                    $state->buffer = substr( $state->buffer, $chunk_size );

                    $result = $state->client->_flush_chunk( $state, $chunk );
                    if ( is_wp_error( $result ) ) {
                        $state->error = $result;
                        return -1;
                    }
                }

                return strlen( $data );
            }
        );

        // Capture HTTP status code via HEADERFUNCTION.
        $http_code = 0;
        curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function ( $ch, $header ) use ( &$http_code ) {
            if ( preg_match( '/^HTTP\/[\d.]+ (\d{3})/', $header, $m ) ) {
                $http_code = (int) $m[1];
            }
            return strlen( $header );
        } );

        curl_exec( $ch );
        $curl_errno = curl_errno( $ch );
        $curl_err   = curl_error( $ch );
        curl_close( $ch );

        // ── Post-download checks ────────────────────────────────
        if ( $state->error !== null ) {
            // NC upload failed during streaming — clean up chunk dir if applicable.
            if ( $state->chunk_dir ) {
                $this->request( 'DELETE', $state->chunk_dir );
            }
            return $state->error;
        }

        if ( $curl_errno !== 0 ) {
            if ( $state->chunk_dir ) {
                $this->request( 'DELETE', $state->chunk_dir );
            }
            return new \WP_Error(
                'wpnc_curl_failed',
                sprintf( __( 'cURL error %d: %s (source: %s)', 'wp-nc-bridge' ), $curl_errno, $curl_err, $source_url )
            );
        }

        if ( $http_code !== 200 ) {
            return new \WP_Error(
                'wpnc_url_http_error',
                sprintf( __( 'Source URL returned HTTP %d.', 'wp-nc-bridge' ), $http_code )
            );
        }

        $total_received = $state->offset + strlen( $state->buffer );
        error_log( '[WPNC] Download complete. Total received: ' . $total_received . ' bytes, flushed: ' . $state->offset . ', remaining buffer: ' . strlen( $state->buffer ) );

        if ( $total_received === 0 ) {
            return new \WP_Error( 'wpnc_url_empty', __( 'Source URL returned no data.', 'wp-nc-bridge' ) );
        }

        // ── Flush remaining buffer ──────────────────────────────
        if ( strlen( $state->buffer ) > 0 ) {
            // If no chunks were flushed yet → small file, simple PUT.
            if ( ! $state->is_large ) {
                error_log( '[WPNC] Small file (' . strlen( $state->buffer ) . ' bytes), simple PUT.' );
                $url = $this->dav_url . $this->encode_path( $remote_path );

                $response = $this->request( 'PUT', $url, array(
                    'headers' => array( 'Content-Type' => 'application/octet-stream' ),
                    'body'    => $state->buffer,
                    'timeout' => max( $this->timeout, 120 ),
                ) );

                if ( is_wp_error( $response ) ) {
                    return $response;
                }

                $code = wp_remote_retrieve_response_code( $response );
                error_log( '[WPNC] Simple PUT response: HTTP ' . $code );
                if ( $code !== 201 && $code !== 204 ) {
                    return new \WP_Error( 'wpnc_put_failed', sprintf( __( 'PUT returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
                }

                return true;
            }

            // Large file: flush the tail as the last chunk.
            $result = $this->_flush_chunk( $state, $state->buffer );
            $state->buffer = '';
            if ( is_wp_error( $result ) ) {
                $this->request( 'DELETE', $state->chunk_dir );
                return $result;
            }
        }

        // ── Large file: MOVE to assemble ────────────────────────
        if ( $state->is_large ) {
            error_log( '[WPNC] All chunks uploaded (' . $state->chunk_num . ' chunks, ' . $state->offset . ' bytes). Assembling…' );
            $dest_url = $this->dav_url . $this->encode_path( $remote_path );

            $resp = $this->request( 'MOVE', $state->chunk_dir . '/.file', array(
                'headers' => array( 'Destination' => $dest_url ),
                'timeout' => max( $this->timeout, 300 ),
            ) );

            if ( is_wp_error( $resp ) ) {
                return $resp;
            }

            $code = wp_remote_retrieve_response_code( $resp );
            error_log( '[WPNC] MOVE response: HTTP ' . $code );
            if ( $code !== 201 && $code !== 204 ) {
                return new \WP_Error( 'wpnc_chunk_move_failed', sprintf( __( 'Chunk assembly MOVE returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
            }
        }

        return true;
    }

    /**
     * Flush a single chunk to Nextcloud (called from upload_from_url).
     *
     * On first invocation, creates the chunk directory (MKCOL).
     * Each chunk is PUT to the NC chunked upload directory.
     *
     * @internal  Public only so the cURL closure can call it via $state->client.
     *
     * @param object $state  Shared state object from upload_from_url.
     * @param string $chunk  Raw chunk data.
     * @return true|\WP_Error
     */
    public function _flush_chunk( object $state, string $chunk ) {

        // First chunk: create the chunk directory.
        if ( ! $state->is_large ) {
            $state->is_large = true;
            $state->uuid     = wp_generate_uuid4();
            $state->chunk_dir = $this->upload_url . $state->uuid;

            error_log( '[WPNC] Large file detected, creating chunk dir: ' . $state->chunk_dir );

            $response = $this->request( 'MKCOL', $state->chunk_dir );
            if ( is_wp_error( $response ) ) {
                return $response;
            }
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 201 && $code !== 405 ) {
                return new \WP_Error( 'wpnc_chunk_mkcol_failed', sprintf( __( 'Chunk MKCOL returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
            }
        }

        $end       = $state->offset + strlen( $chunk );
        $chunk_url = $state->chunk_dir . '/'
                   . str_pad( $state->offset, 15, '0', STR_PAD_LEFT ) . '-'
                   . str_pad( $end, 15, '0', STR_PAD_LEFT );

        error_log( '[WPNC] Chunk #' . $state->chunk_num . ': offset=' . $state->offset . ' size=' . strlen( $chunk ) );

        $resp = $this->request( 'PUT', $chunk_url, array(
            'headers' => array( 'Content-Type' => 'application/octet-stream' ),
            'body'    => $chunk,
            'timeout' => max( $this->timeout, 300 ),
        ) );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 201 && $code !== 204 ) {
            return new \WP_Error( 'wpnc_chunk_put_failed', sprintf( __( 'Chunk PUT returned HTTP %d at offset %d.', 'wp-nc-bridge' ), $code, $state->offset ) );
        }

        $state->offset = $end;
        $state->chunk_num++;

        return true;
    }

    /* ================================================================
     *  FILE DOWNLOAD
     * ============================================================= */

    /**
     * Download a remote file and return its raw content.
     *
     * @param string $path  Remote path relative to user root.
     * @return string|\WP_Error  File contents or error.
     */
    public function download( string $path ) {
        $url      = $this->dav_url . $this->encode_path( $path );
        $response = $this->request( 'GET', $url, array(
            'timeout' => max( $this->timeout, 120 ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new \WP_Error( 'wpnc_download_failed', sprintf( __( 'GET returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Download a remote file and save directly to a local path.
     *
     * @param string $remote_path  Remote path.
     * @param string $local_path   Absolute local path to save to.
     * @return true|\WP_Error
     */
    public function download_to_file( string $remote_path, string $local_path ) {
        $content = $this->download( $remote_path );

        if ( is_wp_error( $content ) ) {
            return $content;
        }

        $dir = dirname( $local_path );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $written = file_put_contents( $local_path, $content );
        if ( $written === false ) {
            return new \WP_Error( 'wpnc_write_failed', sprintf( __( 'Cannot write to %s.', 'wp-nc-bridge' ), $local_path ) );
        }

        return true;
    }

    /* ================================================================
     *  OCS SHARING API
     * ============================================================= */

    /**
     * Get an existing public share link for a path.
     *
     * @param string $path  Remote path relative to user root.
     * @return array|null|\WP_Error  Share data array, null if none, or error.
     */
    public function get_share( string $path ) {
        $url = add_query_arg( 'path', '/' . ltrim( $path, '/' ), $this->ocs_url );

        $response = $this->request( 'GET', $url, array(
            'headers' => array( 'OCS-APIRequest' => 'true' ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = $this->parse_ocs( wp_remote_retrieve_body( $response ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // OCS nests data under 'data' → 'element' (array of shares).
        if ( ! empty( $data['data']['element'] ) ) {
            $element = $data['data']['element'];
            // Single share comes as assoc, multiple as indexed.
            return isset( $element['token'] ) ? $element : end( $element );
        }

        return null; // No share exists yet.
    }

    /**
     * Create a public share link for a path.
     *
     * @param string $path  Remote path.
     * @return array|\WP_Error  Share data (contains 'token', 'url', etc.) or error.
     */
    public function create_share( string $path ) {
        $response = $this->request( 'POST', $this->ocs_url, array(
            'headers' => array(
                'OCS-APIRequest' => 'true',
                'Content-Type'   => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'path'      => '/' . ltrim( $path, '/' ),
                'shareType' => 3,  // 3 = public link
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = $this->parse_ocs( wp_remote_retrieve_body( $response ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        if ( ! empty( $data['data'] ) ) {
            return $data['data'];
        }

        return new \WP_Error( 'wpnc_share_failed', __( 'Unexpected OCS response when creating share.', 'wp-nc-bridge' ) );
    }

    /**
     * Get or create a public share link, return the full download URL.
     *
     * @param string $path  Remote path.
     * @return string|\WP_Error  Public download URL or error.
     */
    public function get_public_url( string $path ) {
        // Try to get existing share first.
        $share = $this->get_share( $path );
        if ( is_wp_error( $share ) ) {
            return $share;
        }

        // If no share exists, create one.
        if ( $share === null ) {
            $share = $this->create_share( $path );
            if ( is_wp_error( $share ) ) {
                return $share;
            }
        }

        if ( empty( $share['token'] ) ) {
            return new \WP_Error( 'wpnc_no_token', __( 'Share token not found.', 'wp-nc-bridge' ) );
        }

        return $this->base_url . '/index.php/s/' . $share['token'] . '/download';
    }

    /* ================================================================
     *  FILE INFO (single resource)
     * ============================================================= */

    /**
     * Retrieve metadata of a single file / folder (PROPFIND Depth: 0).
     *
     * @param string $path  Remote path.
     * @return array|\WP_Error  Associative array with href, name, type, size, mime, fileid.
     */
    public function get_info( string $path ) {
        $url = $this->dav_url . $this->encode_path( $path );

        // For folders, ensure trailing slash.
        if ( substr( $path, -1 ) === '/' || $path === '' ) {
            $url = rtrim( $url, '/' ) . '/';
        }

        $xml_body = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <d:resourcetype/>
    <d:getcontenttype/>
    <d:getcontentlength/>
    <d:getlastmodified/>
    <oc:fileid/>
    <oc:size/>
  </d:prop>
</d:propfind>';

        $response = $this->request( 'PROPFIND', $url, array(
            'headers' => array(
                'Content-Type' => 'text/xml; charset=UTF-8',
                'Depth'        => '0',
            ),
            'body' => $xml_body,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 207 ) {
            return new \WP_Error( 'wpnc_info_failed', sprintf( __( 'PROPFIND (Depth:0) returned HTTP %d.', 'wp-nc-bridge' ), $code ) );
        }

        $items = $this->parse_propfind( wp_remote_retrieve_body( $response ), $path, false );
        if ( is_wp_error( $items ) ) {
            return $items;
        }

        return ! empty( $items ) ? $items[0] : new \WP_Error( 'wpnc_info_empty', __( 'No info returned.', 'wp-nc-bridge' ) );
    }

    /* ================================================================
     *  INTERNAL: HTTP TRANSPORT
     * ============================================================= */

    /**
     * WebDAV methods that require special cURL handling.
     *
     * WordPress's WP_Http_Curl transport does not reliably send the
     * request body for non-standard HTTP methods.  Its switch/case
     * only covers GET, POST, PUT, HEAD — the "default" branch calls
     * curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $method ) but
     * never sets CURLOPT_POSTFIELDS, so the body is silently dropped.
     *
     * We work around this by using the `http_api_curl` filter to
     * force CURLOPT_POSTFIELDS onto the cURL handle for any request
     * that carries a body with a WebDAV method.
     */
    private static $webdav_methods = array(
        'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY',
        'MOVE', 'LOCK', 'UNLOCK', 'REPORT', 'SEARCH',
    );

    /**
     * Central HTTP dispatcher. All requests flow through here.
     *
     * @param string $method  HTTP method.
     * @param string $url     Full URL.
     * @param array  $extra   Additional args for wp_remote_request().
     * @return array|\WP_Error  wp_remote_request response.
     */
    private function request( string $method, string $url, array $extra = array() ) {
        $defaults = array(
            'method'      => $method,
            'timeout'     => $this->timeout,
            'redirection' => 5,
            'httpversion' => '1.1',
            'headers'     => array(),
            'sslverify'   => true,
        );

        $args = $this->merge_args( $defaults, $extra );

        // Inject Basic Auth into every request.
        $args['headers']['Authorization'] = 'Basic ' . base64_encode( $this->username . ':' . $this->password );

        /*
         * WordPress bug workaround: force the request body onto the
         * cURL handle for WebDAV methods that WP_Http_Curl ignores.
         *
         * The filter is added with a unique priority per call to
         * avoid collisions with concurrent requests and is removed
         * immediately after the HTTP call completes.
         */
        $body       = $args['body'] ?? '';
        $need_patch = in_array( $method, self::$webdav_methods, true ) && $body !== '';

        $filter_cb = null;
        if ( $need_patch ) {
            $filter_cb = function ( $handle ) use ( $body ) {
                curl_setopt( $handle, CURLOPT_POSTFIELDS, $body );
            };
            add_action( 'http_api_curl', $filter_cb, 99 );
        }

        $response = wp_remote_request( $url, $args );

        if ( $filter_cb !== null ) {
            remove_action( 'http_api_curl', $filter_cb, 99 );
        }

        return $response;
    }

    /* ================================================================
     *  INTERNAL: XML PARSING
     * ============================================================= */

    /**
     * Parse a multi-status PROPFIND XML response into a flat array.
     *
     * @param string $xml            Raw XML body.
     * @param string $requested_path The path that was requested (to skip self).
     * @param bool   $skip_self      Whether to skip the first entry (the folder itself).
     * @return array|\WP_Error
     */
    private function parse_propfind( string $xml, string $requested_path, bool $skip_self = true ) {
        // Normalise namespace prefixes so SimpleXML can handle everything.
        $xml = str_replace(
            array( 'd:', 'oc:', 'nc:', 's:' ),
            array( 'dav_', 'oc_', 'nc_', 's_' ),
            $xml
        );

        libxml_use_internal_errors( true );
        $sx = simplexml_load_string( $xml );
        if ( $sx === false ) {
            return new \WP_Error( 'wpnc_xml_parse', __( 'Failed to parse PROPFIND XML.', 'wp-nc-bridge' ) );
        }

        $items    = array();
        $base_seg = '/remote.php/dav/files/' . rawurlencode( $this->username ) . '/';

        foreach ( $sx->dav_response as $resp ) {
            $href_raw = (string) $resp->dav_href;
            // Extract the user-relative path.
            $pos = strpos( $href_raw, $base_seg );
            $rel = ( $pos !== false ) ? substr( $href_raw, $pos + strlen( $base_seg ) ) : $href_raw;
            $rel = rawurldecode( rtrim( $rel, '/' ) );

            // Determine type.
            $is_folder = false;
            $propstat  = $resp->dav_propstat;

            // Could be multiple propstat nodes; pick the 200 one.
            if ( isset( $propstat[0] ) ) {
                foreach ( $propstat as $ps ) {
                    if ( strpos( (string) $ps->dav_status, '200' ) !== false ) {
                        $propstat = $ps;
                        break;
                    }
                }
            }

            $prop = $propstat->dav_prop;

            // Check resourcetype for collection.
            if ( isset( $prop->dav_resourcetype ) && isset( $prop->dav_resourcetype->dav_collection ) ) {
                $is_folder = true;
            }

            $item = array(
                'href'     => $rel,
                'name'     => basename( $rel ) ?: '/',
                'type'     => $is_folder ? 'folder' : 'file',
                'mime'     => $is_folder ? '' : (string) ( $prop->dav_getcontenttype ?? '' ),
                'size'     => (int) ( $prop->oc_size ?? $prop->dav_getcontentlength ?? 0 ),
                'modified' => (string) ( $prop->dav_getlastmodified ?? '' ),
                'fileid'   => (string) ( $prop->oc_fileid ?? '' ),
            );

            $items[] = $item;
        }

        // Skip the first item (the folder itself) in Depth:1 results.
        if ( $skip_self && count( $items ) > 0 ) {
            array_shift( $items );
        }

        return $items;
    }

    /**
     * Parse an OCS XML response into an associative array.
     *
     * @param string $xml  Raw OCS XML.
     * @return array|\WP_Error
     */
    private function parse_ocs( string $xml ) {
        // Strip known namespace prefixes.
        $clean = str_replace( array( 'd:', 'oc:', 'nc:', 's:' ), '', $xml );
        $clean = trim( str_replace( '"', "'", $clean ) );

        libxml_use_internal_errors( true );
        $sx = simplexml_load_string( $clean );
        if ( $sx === false ) {
            return new \WP_Error( 'wpnc_ocs_parse', __( 'Failed to parse OCS XML.', 'wp-nc-bridge' ) );
        }

        // SimpleXML → JSON → array for easy traversal.
        $data = json_decode( wp_json_encode( $sx ), true );

        // Validate status code.
        $status_code = (int) ( $data['meta']['statuscode'] ?? 0 );
        if ( $status_code !== 200 && $status_code !== 100 ) {
            $message = $data['meta']['message'] ?? __( 'Unknown OCS error.', 'wp-nc-bridge' );
            return new \WP_Error( 'wpnc_ocs_error', $message );
        }

        return $data;
    }

    /* ================================================================
     *  INTERNAL: UTILITIES
     * ============================================================= */

    /**
     * Encode each segment of a remote path for use in a URL.
     *
     * "/Documents/my file.pdf" → "Documents/my%20file.pdf"
     *
     * @param string $path  Raw path.
     * @return string  URL-safe path (no leading slash).
     */
    private function encode_path( string $path ): string {
        $path     = trim( $path, '/' );
        $segments = explode( '/', $path );
        $encoded  = array_map( 'rawurlencode', $segments );
        return implode( '/', $encoded );
    }

    /**
     * Deep-merge two wp_remote_request arg arrays (headers are merged, not replaced).
     *
     * @param array $defaults  Default arguments.
     * @param array $extra     Override arguments.
     * @return array
     */
    private function merge_args( array $defaults, array $extra ): array {
        // Merge headers separately so they accumulate.
        $headers = array_merge(
            $defaults['headers'] ?? array(),
            $extra['headers'] ?? array()
        );

        $merged            = array_merge( $defaults, $extra );
        $merged['headers'] = $headers;

        return $merged;
    }
}
