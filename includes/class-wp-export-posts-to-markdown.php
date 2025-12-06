<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-wpem-markdown.php';
require_once __DIR__ . '/class-wpem-media.php';
require_once __DIR__ . '/class-wpem-exporter.php';
require_once __DIR__ . '/class-wpem-importer.php';

class WP_Export_Posts_To_Markdown {

    private $debug_log = array();
    private $debug_transient_key = 'wpexportmd_last_debug';

    private $exporter;
    private $importer;

    public function __construct() {
        $markdown       = new WPEM_Markdown();
        $media          = new WPEM_Media( array( $this, 'log_debug' ) );
        $this->exporter = new WPEM_Exporter(
            $markdown,
            array( $this, 'log_debug' ),
            array( $this, 'fail_and_die' ),
            array( $this, 'stream_file_to_browser' )
        );
        $this->importer = new WPEM_Importer(
            $markdown,
            $media,
            array( $this, 'log_debug' ),
            array( $this, 'fail_and_die' )
        );

        add_action( 'admin_menu', array( $this, 'add_page' ) );
        add_action( 'admin_post_wpexportmd', array( $this, 'handle_export' ) );
        add_action( 'admin_post_wpexportmd_import', array( $this, 'handle_import' ) );
        add_action( 'admin_notices', array( $this, 'render_debug_notices' ) );
    }

    public function add_page() {
        add_management_page(
            'Export to Markdown',
            'Export to Markdown',
            'manage_options',
            'export-to-markdown',
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Export Posts to Markdown</h1>
            <p><?php esc_html_e( 'Choose filters (optional) then download posts as Markdown files in a single ZIP archive.', 'export-posts-to-markdown' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wpexportmd', 'wpexportmd_nonce' ); ?>
                <input type="hidden" name="action" value="wpexportmd" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="wpexportmd_status"><?php esc_html_e( 'Status', 'export-posts-to-markdown' ); ?></label></th>
                            <td>
                                <select name="wpexportmd_status" id="wpexportmd_status">
                                    <option value=""><?php esc_html_e( 'All', 'export-posts-to-markdown' ); ?></option>
                                    <option value="publish"><?php esc_html_e( 'Published', 'export-posts-to-markdown' ); ?></option>
                                    <option value="draft"><?php esc_html_e( 'Draft', 'export-posts-to-markdown' ); ?></option>
                                    <option value="pending"><?php esc_html_e( 'Pending', 'export-posts-to-markdown' ); ?></option>
                                    <option value="future"><?php esc_html_e( 'Scheduled', 'export-posts-to-markdown' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_author"><?php esc_html_e( 'Author', 'export-posts-to-markdown' ); ?></label></th>
                            <td>
                                <select name="wpexportmd_author" id="wpexportmd_author">
                                    <option value=""><?php esc_html_e( 'All authors', 'export-posts-to-markdown' ); ?></option>
                                    <?php
                                    $authors = get_users(
                                        array(
                                            'who'    => 'authors',
                                            'fields' => array( 'ID', 'display_name' ),
                                        )
                                    );
                                    foreach ( $authors as $author ) :
                                        ?>
                                        <option value="<?php echo esc_attr( $author->ID ); ?>"><?php echo esc_html( $author->display_name ); ?></option>
                                        <?php
                                    endforeach;
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Date range', 'export-posts-to-markdown' ); ?></th>
                            <td>
                                <label>
                                    <?php esc_html_e( 'From', 'export-posts-to-markdown' ); ?>
                                    <input type="date" name="wpexportmd_start_date" />
                                </label>
                                <label style="margin-left:10px;">
                                    <?php esc_html_e( 'To', 'export-posts-to-markdown' ); ?>
                                    <input type="date" name="wpexportmd_end_date" />
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_exclude_exported"><?php esc_html_e( 'Exclude previously exported', 'export-posts-to-markdown' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wpexportmd_exclude_exported" id="wpexportmd_exclude_exported" value="1" checked />
                                    <?php esc_html_e( 'Skip posts already marked as exported', 'export-posts-to-markdown' ); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Download Markdown ZIP', 'export-posts-to-markdown' ) ); ?>
            </form>
            <hr />
            <h2><?php esc_html_e( 'Import posts from Markdown', 'export-posts-to-markdown' ); ?></h2>
            <p><?php esc_html_e( 'Upload a ZIP archive or a single .md file generated by this plugin to import or update posts.', 'export-posts-to-markdown' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'wpexportmd_import', 'wpexportmd_import_nonce' ); ?>
                <input type="hidden" name="action" value="wpexportmd_import" />
                <input type="file" name="wpexportmd_file" accept=".zip,.md" required />
                <?php submit_button( __( 'Import Markdown', 'export-posts-to-markdown' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_export() {
        $this->log_debug( 'Export request received at ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC.' );

        $current_user_id = get_current_user_id();
        if ( $current_user_id ) {
            $this->log_debug( 'Triggered by user ID ' . $current_user_id . '.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->log_debug( 'Capability check failed for current user.' );
            $this->fail_and_die( esc_html__( 'You do not have permission to export content.', 'export-posts-to-markdown' ) );
        }

        if ( ! isset( $_POST['wpexportmd_nonce'] ) || ! wp_verify_nonce( $_POST['wpexportmd_nonce'], 'wpexportmd' ) ) {
            $this->log_debug( 'Nonce verification failed.' );
            $this->fail_and_die( esc_html__( 'Security check failed.', 'export-posts-to-markdown' ) );
        }

        $filters = array();

        if ( ! empty( $_POST['wpexportmd_status'] ) ) {
            $filters['status'] = sanitize_key( wp_unslash( $_POST['wpexportmd_status'] ) );
        }

        if ( ! empty( $_POST['wpexportmd_author'] ) ) {
            $filters['author'] = absint( $_POST['wpexportmd_author'] );
        }

        if ( ! empty( $_POST['wpexportmd_start_date'] ) && false !== strtotime( $_POST['wpexportmd_start_date'] ) ) {
            $filters['start_date'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_start_date'] ) );
        }

        if ( ! empty( $_POST['wpexportmd_end_date'] ) && false !== strtotime( $_POST['wpexportmd_end_date'] ) ) {
            $filters['end_date'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_end_date'] ) );
        }

        $filters['exclude_exported'] = ! empty( $_POST['wpexportmd_exclude_exported'] );

        $this->exporter->export_all( $filters );
        $this->persist_debug_log();

        exit;
    }

    public function handle_import() {
        $this->log_debug( 'Import request received at ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC.' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->log_debug( 'Capability check failed for current user.' );
            $this->fail_and_die( esc_html__( 'You do not have permission to import content.', 'export-posts-to-markdown' ) );
        }

        if ( ! isset( $_POST['wpexportmd_import_nonce'] ) || ! wp_verify_nonce( $_POST['wpexportmd_import_nonce'], 'wpexportmd_import' ) ) {
            $this->log_debug( 'Import nonce verification failed.' );
            $this->fail_and_die( esc_html__( 'Security check failed for import.', 'export-posts-to-markdown' ) );
        }

        if ( empty( $_FILES['wpexportmd_file'] ) || ! is_array( $_FILES['wpexportmd_file'] ) ) {
            $this->log_debug( 'No file uploaded for import.' );
            $this->fail_and_die( esc_html__( 'Please choose a Markdown file or ZIP archive to import.', 'export-posts-to-markdown' ) );
        }

        $file = $_FILES['wpexportmd_file'];

        if ( ! empty( $file['error'] ) ) {
            $this->log_debug( 'File upload error code: ' . $file['error'] );
            $this->fail_and_die( esc_html__( 'File upload failed. Please try again.', 'export-posts-to-markdown' ) );
        }

        $tmp_path = $file['tmp_name'];
        $name     = $file['name'];

        if ( ! file_exists( $tmp_path ) || ! is_readable( $tmp_path ) ) {
            $this->log_debug( 'Uploaded file missing at ' . $tmp_path . '.' );
            $this->fail_and_die( esc_html__( 'Uploaded file could not be read.', 'export-posts-to-markdown' ) );
        }

        $stats = $this->importer->import_file( $tmp_path, $name );

        $this->log_debug(
            sprintf(
                'Import completed: processed=%d, updated=%d, created=%d, skipped=%d.',
                $stats['processed'],
                $stats['updated'],
                $stats['created'],
                $stats['skipped']
            )
        );

        $this->persist_debug_log();

        wp_safe_redirect( admin_url( 'tools.php?page=export-to-markdown' ) );
        exit;
    }

    public function render_debug_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'tools_page_export-to-markdown' !== $screen->id ) {
            return;
        }

        $messages = get_transient( $this->debug_transient_key );
        if ( empty( $messages ) || ! is_array( $messages ) ) {
            return;
        }

        delete_transient( $this->debug_transient_key );

        echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Export to Markdown debug log', 'export-posts-to-markdown' ) . '</strong></p><ul>';
        foreach ( $messages as $message ) {
            echo '<li>' . esc_html( $message ) . '</li>';
        }
        echo '</ul></div>';
    }

    public function stream_file_to_browser( $path, $download_name ) {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            $this->log_debug( 'Export file missing or unreadable at ' . $path . '.' );
            $this->fail_and_die( esc_html__( 'Export file could not be read.', 'export-posts-to-markdown' ) );
        }

        ignore_user_abort( true );
        nocache_headers();

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        clearstatcache( true, $path );

        $size          = filesize( $path );
        $download_name = sanitize_file_name( $download_name );

        $this->log_debug( sprintf( 'Streaming ZIP file (%d bytes) as %s.', (int) $size, $download_name ) );

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . (int) $size );
        header( 'Connection: close' );

        $result = readfile( $path );

        if ( false === $result ) {
            $this->log_debug( 'readfile() returned false while streaming.' );
            $this->fail_and_die( esc_html__( 'Failed to stream the export file.', 'export-posts-to-markdown' ) );
        }
    }

    public function log_debug( $message ) {
        $message = wp_strip_all_tags( (string) $message );
        if ( '' === $message ) {
            return;
        }

        $this->debug_log[] = '[' . gmdate( 'H:i:s' ) . ' UTC] ' . $message;
    }

    private function persist_debug_log() {
        if ( empty( $this->debug_log ) ) {
            return;
        }

        set_transient( $this->debug_transient_key, $this->debug_log, 5 * MINUTE_IN_SECONDS );
    }

    public function fail_and_die( $message ) {
        $this->log_debug( 'Failure: ' . wp_strip_all_tags( $message ) );
        $this->persist_debug_log();
        wp_die( $message );
    }
}
