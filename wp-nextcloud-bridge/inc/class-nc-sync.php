<?php
/**
 * Directory Sync Engine  (WP → Nextcloud)
 *
 * Compares a local directory tree against a remote Nextcloud folder,
 * then uploads new or modified files and creates missing directories.
 *
 * Design principles:
 *   - Pure logic: receives a Nextcloud_Client, never touches wp_options.
 *   - Deterministic: same local + remote state → same operations.
 *   - Resumable-friendly: processes one file at a time, returns a log.
 *   - No deletes on remote by default (additive sync).
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class NC_Sync {

    /** @var Nextcloud_Client */
    private $client;

    /** @var string[] Operation log. */
    private $log = array();

    /** @var array Glob patterns to skip. */
    private $skip_patterns = array(
        '.',
        '..',
        '.git',
        '.svn',
        '.DS_Store',
        'Thumbs.db',
        '.htaccess',
        'node_modules',
        'vendor',
    );

    /** @var int Max file size to sync in bytes (default 100 MB). */
    private $max_file_size;

    /* ================================================================
     *  CONSTRUCTOR
     * ============================================================= */

    /**
     * @param Nextcloud_Client $client         Authenticated client.
     * @param int              $max_file_size  Max file size in bytes (default 2 GB).
     */
    public function __construct( Nextcloud_Client $client, int $max_file_size = 2147483648 ) {
        $this->client        = $client;
        $this->max_file_size = $max_file_size;
    }

    /* ================================================================
     *  PUBLIC API
     * ============================================================= */

    /**
     * Sync a local directory to a remote Nextcloud path.
     *
     * Returns an array of log strings describing every action taken,
     * or WP_Error if a fatal error occurs during initialisation.
     *
     * @param string $local_abs   Absolute local directory path.
     * @param string $remote_rel  Remote path relative to NC user root.
     * @return string[]|\WP_Error  Log entries.
     */
    public function sync_directory( string $local_abs, string $remote_rel ) {
        $this->log = array();

        $local_abs  = rtrim( $local_abs, '/' );
        $remote_rel = trim( $remote_rel, '/' );

        if ( ! is_dir( $local_abs ) ) {
            return new \WP_Error(
                'wpnc_sync_no_dir',
                sprintf( __( 'Local directory does not exist: %s', 'wp-nc-bridge' ), $local_abs )
            );
        }

        // Ensure remote root exists.
        $mk = $this->client->create_folder( $remote_rel );
        if ( is_wp_error( $mk ) ) {
            return $mk;
        }

        $this->log( '▶ START  %s → %s', $local_abs, $remote_rel );

        // Build remote index for fast lookups.
        $remote_index = $this->build_remote_index( $remote_rel );
        if ( is_wp_error( $remote_index ) ) {
            $this->log( '⚠ Could not index remote: %s (will upload everything)', $remote_index->get_error_message() );
            $remote_index = array();
        }

        // Walk local tree.
        $this->walk_and_sync( $local_abs, $remote_rel, $remote_index );

        $this->log( '■ DONE   %d entries logged', count( $this->log ) );

        return $this->log;
    }

    /**
     * Get skip patterns (so callers can inspect/extend).
     *
     * @return array
     */
    public function get_skip_patterns(): array {
        return $this->skip_patterns;
    }

    /**
     * Add patterns to skip during sync.
     *
     * @param string[] $patterns  File/directory names to skip.
     */
    public function add_skip_patterns( array $patterns ) {
        $this->skip_patterns = array_unique( array_merge( $this->skip_patterns, $patterns ) );
    }

    /* ================================================================
     *  INTERNAL: RECURSIVE WALKER
     * ============================================================= */

    /**
     * Recursively walk a local directory and sync to remote.
     *
     * @param string $local_dir    Current local directory (absolute).
     * @param string $remote_dir   Current remote directory (relative to NC root).
     * @param array  $remote_index Map of remote_path → { size, modified }.
     */
    private function walk_and_sync( string $local_dir, string $remote_dir, array &$remote_index ) {
        $entries = scandir( $local_dir );
        if ( $entries === false ) {
            $this->log( '⚠ SKIP   Cannot read: %s', $local_dir );
            return;
        }

        // Separate folders and files — process folders first so remote dirs exist.
        $folders = array();
        $files   = array();

        foreach ( $entries as $entry ) {
            if ( $this->should_skip( $entry ) ) {
                continue;
            }

            $local_path = $local_dir . '/' . $entry;

            if ( is_dir( $local_path ) ) {
                $folders[] = $entry;
            } elseif ( is_file( $local_path ) ) {
                $files[] = $entry;
            }
        }

        // 1. Process sub-directories.
        foreach ( $folders as $folder ) {
            $local_sub  = $local_dir . '/' . $folder;
            $remote_sub = $remote_dir . '/' . $folder;

            // Create if not already on remote.
            if ( ! isset( $remote_index[ $remote_sub ] ) ) {
                $mk = $this->client->create_folder( $remote_sub );
                if ( is_wp_error( $mk ) ) {
                    $this->log( '✗ MKDIR  %s — %s', $remote_sub, $mk->get_error_message() );
                    continue; // Skip this sub-tree.
                }
                $this->log( '+ MKDIR  %s', $remote_sub );
            }

            // Recurse.
            $this->walk_and_sync( $local_sub, $remote_sub, $remote_index );
        }

        // 2. Process files.
        foreach ( $files as $file ) {
            $local_path  = $local_dir . '/' . $file;
            $remote_path = $remote_dir . '/' . $file;

            $this->sync_file( $local_path, $remote_path, $remote_index );
        }
    }

    /**
     * Decide whether a single file needs uploading, then upload if needed.
     *
     * @param string $local_path   Absolute local file path.
     * @param string $remote_path  Relative remote path.
     * @param array  $remote_index Remote index.
     */
    private function sync_file( string $local_path, string $remote_path, array &$remote_index ) {
        $local_size = filesize( $local_path );

        // Guard: file too large.
        if ( $local_size > $this->max_file_size ) {
            $this->log(
                '⚠ SKIP   %s (%.1f MB > %.1f MB limit)',
                $remote_path,
                $local_size / 1048576,
                $this->max_file_size / 1048576
            );
            return;
        }

        // Decide: skip, or upload.
        $dominated = $this->needs_upload( $local_path, $local_size, $remote_path, $remote_index );

        if ( ! $dominated ) {
            return; // Already up-to-date.
        }

        $result = $this->client->upload( $local_path, $remote_path );

        if ( is_wp_error( $result ) ) {
            $this->log( '✗ UPLOAD %s — %s', $remote_path, $result->get_error_message() );
        } else {
            $this->log( '↑ UPLOAD %s (%s)', $remote_path, $this->human_size( $local_size ) );
        }
    }

    /* ================================================================
     *  INTERNAL: CHANGE DETECTION
     * ============================================================= */

    /**
     * Determine whether a local file should be uploaded.
     *
     * Strategy (cheap to expensive):
     *   1. File does not exist on remote → upload.
     *   2. File sizes differ → upload.
     *   3. Local mtime is newer than remote mtime → upload.
     *
     * We intentionally do NOT compare checksums — the round-trip cost
     * of downloading file content for hashing defeats the purpose.
     *
     * @param string $local_path   Absolute local path.
     * @param int    $local_size   Local file size.
     * @param string $remote_path  Relative remote path.
     * @param array  $remote_index Remote index.
     * @return bool  True if the file needs uploading.
     */
    private function needs_upload( string $local_path, int $local_size, string $remote_path, array &$remote_index ): bool {
        // 1. Not on remote.
        if ( ! isset( $remote_index[ $remote_path ] ) ) {
            return true;
        }

        $remote = $remote_index[ $remote_path ];

        // 2. Size mismatch.
        if ( (int) $remote['size'] !== $local_size ) {
            return true;
        }

        // 3. Modification time (local newer than remote).
        $local_mtime = filemtime( $local_path );
        if ( $local_mtime === false ) {
            return true; // Cannot determine — play it safe.
        }

        if ( ! empty( $remote['modified'] ) ) {
            $remote_ts = strtotime( $remote['modified'] );
            if ( $remote_ts !== false && $local_mtime > $remote_ts ) {
                return true;
            }
        }

        return false;
    }

    /* ================================================================
     *  INTERNAL: REMOTE INDEXING
     * ============================================================= */

    /**
     * Build a flat map of all files and folders under a remote path.
     *
     * Key   = relative path (same format as walk_and_sync uses).
     * Value = { type, size, modified }
     *
     * Uses recursive PROPFIND (one call per sub-folder).
     *
     * @param string $remote_root  Relative remote root.
     * @return array|\WP_Error     Flat index or error.
     */
    private function build_remote_index( string $remote_root ) {
        $index = array();
        $this->index_recursive( $remote_root, $index );
        return $index;
    }

    /**
     * Recursive helper to populate the remote index.
     *
     * @param string $path   Current remote path.
     * @param array  $index  Reference to the accumulating index.
     */
    private function index_recursive( string $path, array &$index ) {
        $items = $this->client->list_folder( $path );

        if ( is_wp_error( $items ) ) {
            // Non-fatal: just log and return.
            $this->log( '⚠ INDEX  Cannot list %s: %s', $path, $items->get_error_message() );
            return;
        }

        foreach ( $items as $item ) {
            // Build the full relative path.
            $item_path = trim( $path, '/' ) . '/' . $item['name'];

            $index[ $item_path ] = array(
                'type'     => $item['type'],
                'size'     => $item['size'],
                'modified' => $item['modified'],
            );

            // Recurse into sub-folders.
            if ( $item['type'] === 'folder' ) {
                $this->index_recursive( $item_path, $index );
            }
        }
    }

    /* ================================================================
     *  INTERNAL: UTILITIES
     * ============================================================= */

    /**
     * Should this file/directory name be skipped?
     *
     * @param string $name  Base name.
     * @return bool
     */
    private function should_skip( string $name ): bool {
        return in_array( $name, $this->skip_patterns, true );
    }

    /**
     * Append a formatted line to the internal log.
     *
     * @param string $format  sprintf-style format.
     * @param mixed  ...$args
     */
    private function log( string $format, ...$args ) {
        $this->log[] = vsprintf( $format, $args );
    }

    /**
     * Human-readable file size.
     *
     * @param int $bytes  Size in bytes.
     * @return string
     */
    private function human_size( int $bytes ): string {
        $units = array( 'B', 'KB', 'MB', 'GB' );
        $i     = 0;
        $size  = (float) $bytes;
        while ( $size >= 1024 && $i < count( $units ) - 1 ) {
            $size /= 1024;
            $i++;
        }
        return round( $size, 1 ) . ' ' . $units[ $i ];
    }
}
