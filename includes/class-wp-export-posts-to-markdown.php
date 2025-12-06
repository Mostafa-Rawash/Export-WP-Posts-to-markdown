<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-wpem-markdown.php';
require_once __DIR__ . '/class-wpem-media.php';
require_once __DIR__ . '/class-wpem-sync.php';
require_once __DIR__ . '/class-wpem-exporter.php';
require_once __DIR__ . '/class-wpem-importer.php';

class WP_Export_Posts_To_Markdown {

    private $debug_log = array();
    private $debug_transient_key = 'wpexportmd_last_debug';

    private $exporter;
    private $importer;
    private $sync;
    private $cron_hook = 'wpexportmd_cron_export_sync';
    private $cron_interval_option = 'wpexportmd_cron_interval_minutes';

    public function __construct() {
        $markdown       = new WPEM_Markdown();
        $media          = new WPEM_Media( array( $this, 'log_debug' ) );
        $options        = get_option( 'wpexportmd_settings', array() );
        $options        = is_array( $options ) ? $options : array();
        $this->sync     = new WPEM_Sync( array( $this, 'log_debug' ), $options );
        $this->exporter = new WPEM_Exporter(
            $markdown,
            array( $this, 'log_debug' ),
            array( $this, 'fail_and_die' ),
            array( $this, 'stream_file_to_browser' ),
            $this->sync
        );
        $this->importer = new WPEM_Importer(
            $markdown,
            $media,
            array( $this, 'log_debug' ),
            array( $this, 'fail_and_die' ),
            $this->sync
        );

        add_action( 'admin_menu', array( $this, 'add_page' ) );
        add_action( 'admin_post_wpexportmd', array( $this, 'handle_export' ) );
        add_action( 'admin_post_wpexportmd_import', array( $this, 'handle_import' ) );
        add_action( 'admin_post_wpexportmd_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_notices', array( $this, 'render_debug_notices' ) );
        add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
        add_action( $this->cron_hook, array( $this, 'run_cron_export_sync' ) );
        add_filter( 'cron_schedules', array( $this, 'register_custom_cron_schedule' ) );
    }

    public function add_page() {
        add_menu_page(
            __( 'Markdown Export/Import', 'export-posts-to-markdown' ),
            __( 'Markdown Export', 'export-posts-to-markdown' ),
            'manage_options',
            'export-to-markdown',
            array( $this, 'render_export_page' ),
            'dashicons-media-code'
        );

        add_submenu_page(
            'export-to-markdown',
            __( 'Export to Markdown', 'export-posts-to-markdown' ),
            __( 'Export', 'export-posts-to-markdown' ),
            'manage_options',
            'export-to-markdown',
            array( $this, 'render_export_page' )
        );

        add_submenu_page(
            'export-to-markdown',
            __( 'Import from Markdown', 'export-posts-to-markdown' ),
            __( 'Import', 'export-posts-to-markdown' ),
            'manage_options',
            'export-to-markdown-import',
            array( $this, 'render_import_page' )
        );

        add_submenu_page(
            'export-to-markdown',
            __( 'Integrations', 'export-posts-to-markdown' ),
            __( 'Integrations', 'export-posts-to-markdown' ),
            'manage_options',
            'export-to-markdown-integrations',
            array( $this, 'render_integrations_page' )
        );
    }

    public function render_export_page() {
        $options = get_option( 'wpexportmd_settings', array() );
        $options = is_array( $options ) ? $options : array();
        $github_enabled = ! empty( $options['github_enabled'] );
        $drive_enabled  = ! empty( $options['drive_enabled'] );
        ?>
        <div class="wrap">
            <h1>Export Posts to Markdown</h1>
            <p><?php esc_html_e( 'Choose filters (optional) then download posts as Markdown files in a single ZIP archive. Sync buttons appear only when the corresponding integration is enabled on the Integrations page.', 'export-posts-to-markdown' ); ?></p>
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
                                            'capability' => 'publish_posts',
                                            'fields'     => array( 'ID', 'display_name' ),
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
                <?php
                if ( $github_enabled ) {
                    echo get_submit_button( __( 'Export to GitHub', 'export-posts-to-markdown' ), 'secondary', 'wpexportmd_export_github', false );
                }
                if ( $drive_enabled ) {
                    echo get_submit_button( __( 'Export to Drive', 'export-posts-to-markdown' ), 'secondary', 'wpexportmd_export_drive', false );
                }
                ?>
            </form>
        </div>
        <?php
    }

    public function render_import_page() {
        $options = get_option( 'wpexportmd_settings', array() );
        $options = is_array( $options ) ? $options : array();
        $github_enabled = ! empty( $options['github_enabled'] );
        $drive_enabled  = ! empty( $options['drive_enabled'] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Import posts from Markdown', 'export-posts-to-markdown' ); ?></h1>
            <p><?php esc_html_e( 'Choose how to import: upload a Markdown file, upload a ZIP, pull directly from Drive, or pull directly from GitHub. Sync buttons appear only when the corresponding integration is enabled on the Integrations page.', 'export-posts-to-markdown' ); ?></p>

            <h2><?php esc_html_e( '1) Upload Markdown file (.md)', 'export-posts-to-markdown' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-bottom:2rem;">
                <?php wp_nonce_field( 'wpexportmd_import', 'wpexportmd_import_nonce' ); ?>
                <input type="hidden" name="action" value="wpexportmd_import" />
                <input type="hidden" name="wpexportmd_source" value="upload_md" />
                <input type="file" name="wpexportmd_file" accept=".md" required />
                <?php submit_button( __( 'Import Markdown file', 'export-posts-to-markdown' ) ); ?>
            </form>

            <h2><?php esc_html_e( '2) Upload ZIP', 'export-posts-to-markdown' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-bottom:2rem;">
                <?php wp_nonce_field( 'wpexportmd_import', 'wpexportmd_import_nonce' ); ?>
                <input type="hidden" name="action" value="wpexportmd_import" />
                <input type="hidden" name="wpexportmd_source" value="upload_zip" />
                <input type="file" name="wpexportmd_file" accept=".zip" required />
                <?php submit_button( __( 'Import ZIP', 'export-posts-to-markdown' ) ); ?>
            </form>

            <?php if ( $drive_enabled ) : ?>
                <h2><?php esc_html_e( '3) Import from Google Drive', 'export-posts-to-markdown' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:2rem;">
                    <?php wp_nonce_field( 'wpexportmd_import', 'wpexportmd_import_nonce' ); ?>
                    <input type="hidden" name="action" value="wpexportmd_import" />
                    <input type="hidden" name="wpexportmd_source" value="drive" />
                    <p>
                        <label for="wpexportmd_drive_file_id"><?php esc_html_e( 'Drive file ID', 'export-posts-to-markdown' ); ?></label><br />
                        <input type="text" name="wpexportmd_drive_file_id" id="wpexportmd_drive_file_id" class="regular-text" required />
                    </p>
                    <?php submit_button( __( 'Import from Drive', 'export-posts-to-markdown' ) ); ?>
                </form>
            <?php endif; ?>

            <?php if ( $github_enabled ) : ?>
                <h2><?php esc_html_e( '4) Import from GitHub', 'export-posts-to-markdown' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wpexportmd_import', 'wpexportmd_import_nonce' ); ?>
                    <input type="hidden" name="action" value="wpexportmd_import" />
                    <input type="hidden" name="wpexportmd_source" value="github" />
                    <p>
                        <label for="wpexportmd_github_file_path"><?php esc_html_e( 'GitHub file path (relative to repo, e.g. exports/post-1.md or exports/archive.zip)', 'export-posts-to-markdown' ); ?></label><br />
                        <input type="text" name="wpexportmd_github_file_path" id="wpexportmd_github_file_path" class="regular-text" required />
                    </p>
                    <?php submit_button( __( 'Import from GitHub', 'export-posts-to-markdown' ) ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_integrations_page() {
        $options = get_option( 'wpexportmd_settings', array() );
        $options = is_array( $options ) ? $options : array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Integrations (GitHub / Drive)', 'export-posts-to-markdown' ); ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wpexportmd_save_settings', 'wpexportmd_save_settings_nonce' ); ?>
                <input type="hidden" name="action" value="wpexportmd_save_settings" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="wpexportmd_github_enabled"><?php esc_html_e( 'Enable GitHub export/import', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="checkbox" name="wpexportmd_github_enabled" id="wpexportmd_github_enabled" value="1" <?php checked( ! empty( $options['github_enabled'] ) ); ?> /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_github_repo"><?php esc_html_e( 'GitHub repo (owner/repo)', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="text" name="wpexportmd_github_repo" id="wpexportmd_github_repo" value="<?php echo esc_attr( isset( $options['github_repo'] ) ? $options['github_repo'] : '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_github_branch"><?php esc_html_e( 'GitHub branch', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="text" name="wpexportmd_github_branch" id="wpexportmd_github_branch" value="<?php echo esc_attr( isset( $options['github_branch'] ) ? $options['github_branch'] : 'main' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_github_path"><?php esc_html_e( 'GitHub path prefix', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="text" name="wpexportmd_github_path" id="wpexportmd_github_path" value="<?php echo esc_attr( isset( $options['github_path'] ) ? $options['github_path'] : 'exports' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_github_token"><?php esc_html_e( 'GitHub token', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="password" name="wpexportmd_github_token" id="wpexportmd_github_token" value="<?php echo esc_attr( isset( $options['github_token'] ) ? $options['github_token'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_github_auto_sync"><?php esc_html_e( 'Enable scheduled auto-sync to GitHub', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="checkbox" name="wpexportmd_github_auto_sync" id="wpexportmd_github_auto_sync" value="1" <?php checked( ! empty( $options['github_auto_sync'] ) ); ?> /> <p class="description"><?php esc_html_e( 'Runs on the custom interval below; exports recent posts and pushes to GitHub without downloading.', 'export-posts-to-markdown' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_auto_sync_interval"><?php esc_html_e( 'Auto-sync interval (minutes)', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="number" min="5" step="5" name="wpexportmd_auto_sync_interval" id="wpexportmd_auto_sync_interval" value="<?php echo esc_attr( isset( $options['auto_sync_interval'] ) ? (int) $options['auto_sync_interval'] : 60 ); ?>" class="small-text" /> <span class="description"><?php esc_html_e( 'Minimum 5 minutes. Applies to GitHub/Drive auto-sync.', 'export-posts-to-markdown' ); ?></span></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_enabled"><?php esc_html_e( 'Enable Drive export/import', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="checkbox" name="wpexportmd_drive_enabled" id="wpexportmd_drive_enabled" value="1" <?php checked( ! empty( $options['drive_enabled'] ) ); ?> /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_token"><?php esc_html_e( 'Google Drive access token', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="password" name="wpexportmd_drive_token" id="wpexportmd_drive_token" value="<?php echo esc_attr( isset( $options['drive_token'] ) ? $options['drive_token'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_client_id"><?php esc_html_e( 'Drive Client ID', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="text" name="wpexportmd_drive_client_id" id="wpexportmd_drive_client_id" value="<?php echo esc_attr( isset( $options['drive_client_id'] ) ? $options['drive_client_id'] : '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_client_secret"><?php esc_html_e( 'Drive Client Secret', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="password" name="wpexportmd_drive_client_secret" id="wpexportmd_drive_client_secret" value="<?php echo esc_attr( isset( $options['drive_client_secret'] ) ? $options['drive_client_secret'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_refresh_token"><?php esc_html_e( 'Drive Refresh Token', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="password" name="wpexportmd_drive_refresh_token" id="wpexportmd_drive_refresh_token" value="<?php echo esc_attr( isset( $options['drive_refresh_token'] ) ? $options['drive_refresh_token'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_folder_id"><?php esc_html_e( 'Drive folder ID (optional)', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="text" name="wpexportmd_drive_folder_id" id="wpexportmd_drive_folder_id" value="<?php echo esc_attr( isset( $options['drive_folder_id'] ) ? $options['drive_folder_id'] : '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_auto_sync"><?php esc_html_e( 'Enable scheduled auto-sync to Drive', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="checkbox" name="wpexportmd_drive_auto_sync" id="wpexportmd_drive_auto_sync" value="1" <?php checked( ! empty( $options['drive_auto_sync'] ) ); ?> /> <p class="description"><?php esc_html_e( 'Runs on the custom interval above; exports recent posts and pushes to Drive without downloading.', 'export-posts-to-markdown' ); ?></p></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Save Integration Settings', 'export-posts-to-markdown' ) ); ?>
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

        $filters         = array();
        $sync_overrides  = array();
        $stream_download = true;

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

        if ( ! empty( $_POST['wpexportmd_export_github'] ) ) {
            $sync_overrides['github_enabled'] = true;
        }

        if ( ! empty( $_POST['wpexportmd_export_drive'] ) ) {
            $sync_overrides['drive_enabled'] = true;
        }

        if ( ! empty( $_POST['wpexportmd_sync_only'] ) ) {
            $stream_download              = false;
            $sync_overrides['github_enabled'] = true;
        }

        $this->exporter->export_all( $filters, $sync_overrides, $stream_download );
        $this->persist_debug_log();

        if ( $stream_download ) {
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=export-to-markdown' ) );
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

        $source = isset( $_POST['wpexportmd_source'] ) ? sanitize_key( $_POST['wpexportmd_source'] ) : 'upload_md';
        $tmp_path = '';
        $name     = '';

        if ( in_array( $source, array( 'upload_md', 'upload_zip' ), true ) ) {
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

            $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            if ( 'upload_md' === $source && 'md' !== $ext ) {
                $this->log_debug( 'Upload MD source but extension is .' . $ext );
                $this->fail_and_die( esc_html__( 'Please upload a .md file.', 'export-posts-to-markdown' ) );
            }
            if ( 'upload_zip' === $source && 'zip' !== $ext ) {
                $this->log_debug( 'Upload ZIP source but extension is .' . $ext );
                $this->fail_and_die( esc_html__( 'Please upload a .zip file.', 'export-posts-to-markdown' ) );
            }
        } elseif ( 'github' === $source ) {
            $path = isset( $_POST['wpexportmd_github_file_path'] ) ? sanitize_text_field( wp_unslash( $_POST['wpexportmd_github_file_path'] ) ) : '';
            if ( '' === $path ) {
                $this->fail_and_die( esc_html__( 'Please provide a GitHub file path.', 'export-posts-to-markdown' ) );
            }
            if ( ! $this->sync ) {
                $this->fail_and_die( esc_html__( 'GitHub integration not available.', 'export-posts-to-markdown' ) );
            }
            $fetched = $this->sync->fetch_github_file( $path );
            if ( is_wp_error( $fetched ) ) {
                $this->log_debug( 'GitHub fetch failed: ' . $fetched->get_error_message() );
                $this->fail_and_die( esc_html__( 'Could not download the GitHub file.', 'export-posts-to-markdown' ) );
            }
            $tmp_path = $fetched['tmp_path'];
            $name     = $fetched['name'];
            $this->log_debug( 'Fetched GitHub file ' . $path . ' for import.' );
        } elseif ( 'drive' === $source ) {
            $file_id = isset( $_POST['wpexportmd_drive_file_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wpexportmd_drive_file_id'] ) ) : '';
            if ( '' === $file_id ) {
                $this->fail_and_die( esc_html__( 'Please provide a Google Drive file ID.', 'export-posts-to-markdown' ) );
            }
            if ( ! $this->sync ) {
                $this->fail_and_die( esc_html__( 'Drive integration not available.', 'export-posts-to-markdown' ) );
            }
            $fetched = $this->sync->fetch_drive_file( $file_id );
            if ( is_wp_error( $fetched ) ) {
                $this->log_debug( 'Drive fetch failed: ' . $fetched->get_error_message() );
                $this->fail_and_die( esc_html__( 'Could not download the Drive file.', 'export-posts-to-markdown' ) );
            }
            $tmp_path = $fetched['tmp_path'];
            $name     = $fetched['name'];
            $this->log_debug( 'Fetched Drive file ' . $file_id . ' for import.' );
        } else {
            $this->fail_and_die( esc_html__( 'Unknown import source.', 'export-posts-to-markdown' ) );
        }

        $sync_overrides = array();
        if ( ! empty( $_POST['wpexportmd_import_github'] ) ) {
            $sync_overrides['github_enabled'] = true;
        }
        if ( ! empty( $_POST['wpexportmd_import_drive'] ) ) {
            $sync_overrides['drive_enabled'] = true;
        }

        $stats = $this->importer->import_file( $tmp_path, $name, $sync_overrides );

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

    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            $this->fail_and_die( esc_html__( 'You do not have permission to update settings.', 'export-posts-to-markdown' ) );
        }

        if ( ! isset( $_POST['wpexportmd_save_settings_nonce'] ) || ! wp_verify_nonce( $_POST['wpexportmd_save_settings_nonce'], 'wpexportmd_save_settings' ) ) {
            $this->fail_and_die( esc_html__( 'Security check failed for settings.', 'export-posts-to-markdown' ) );
        }

        $options = get_option( 'wpexportmd_settings', array() );

        $options['github_enabled'] = ! empty( $_POST['wpexportmd_github_enabled'] );
        $options['github_repo']   = isset( $_POST['wpexportmd_github_repo'] ) ? sanitize_text_field( wp_unslash( $_POST['wpexportmd_github_repo'] ) ) : '';
        $options['github_branch'] = isset( $_POST['wpexportmd_github_branch'] ) ? sanitize_text_field( wp_unslash( $_POST['wpexportmd_github_branch'] ) ) : 'main';
        $options['github_path']   = isset( $_POST['wpexportmd_github_path'] ) ? sanitize_text_field( wp_unslash( $_POST['wpexportmd_github_path'] ) ) : 'exports';
        $options['github_auto_sync'] = ! empty( $_POST['wpexportmd_github_auto_sync'] );
        $interval_minutes = isset( $_POST['wpexportmd_auto_sync_interval'] ) ? max( 5, (int) $_POST['wpexportmd_auto_sync_interval'] ) : 60;
        $options['auto_sync_interval'] = $interval_minutes;

        if ( isset( $_POST['wpexportmd_github_token'] ) && '' !== $_POST['wpexportmd_github_token'] ) {
            $options['github_token'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_github_token'] ) );
        }

        $options['drive_enabled'] = ! empty( $_POST['wpexportmd_drive_enabled'] );
        if ( isset( $_POST['wpexportmd_drive_token'] ) && '' !== $_POST['wpexportmd_drive_token'] ) {
            $options['drive_token'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_drive_token'] ) );
        }
        if ( isset( $_POST['wpexportmd_drive_client_id'] ) ) {
            $options['drive_client_id'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_drive_client_id'] ) );
        }
        if ( isset( $_POST['wpexportmd_drive_client_secret'] ) && '' !== $_POST['wpexportmd_drive_client_secret'] ) {
            $options['drive_client_secret'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_drive_client_secret'] ) );
        }
        if ( isset( $_POST['wpexportmd_drive_refresh_token'] ) && '' !== $_POST['wpexportmd_drive_refresh_token'] ) {
            $options['drive_refresh_token'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_drive_refresh_token'] ) );
        }

        $options['drive_folder_id'] = isset( $_POST['wpexportmd_drive_folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wpexportmd_drive_folder_id'] ) ) : '';
        $options['drive_auto_sync'] = ! empty( $_POST['wpexportmd_drive_auto_sync'] );

        update_option( 'wpexportmd_settings', $options );
        $this->maybe_update_cron( $options );

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
        $allowed_ids = array(
            'toplevel_page_export-to-markdown',
            'markdown-export_page_export-to-markdown',
            'markdown-export_page_export-to-markdown-import',
            'markdown-export_page_export-to-markdown-integrations',
        );

        if ( ! $screen || ! in_array( $screen->id, $allowed_ids, true ) ) {
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

    public function maybe_schedule_cron() {
        $options = get_option( 'wpexportmd_settings', array() );
        $options = is_array( $options ) ? $options : array();
        $this->maybe_update_cron( $options );
    }

    private function maybe_update_cron( $options ) {
        $github_ready = ! empty( $options['github_auto_sync'] ) && ! empty( $options['github_enabled'] ) && ! empty( $options['github_repo'] ) && ! empty( $options['github_token'] );
        $drive_ready  = ! empty( $options['drive_auto_sync'] ) && ! empty( $options['drive_enabled'] ) && ( ! empty( $options['drive_token'] ) || ( ! empty( $options['drive_client_id'] ) && ! empty( $options['drive_client_secret'] ) && ! empty( $options['drive_refresh_token'] ) ) );
        $interval     = isset( $options['auto_sync_interval'] ) ? max( 5, (int) $options['auto_sync_interval'] ) : 60;

        $needs_cron = $github_ready || $drive_ready;

        if ( $needs_cron && ! wp_next_scheduled( $this->cron_hook ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wpexportmd_custom', $this->cron_hook );
        }

        if ( ! $needs_cron ) {
            $this->clear_cron();
            return;
        }

        // Reschedule if interval changed.
        $existing = wp_get_scheduled_event( $this->cron_hook );
        if ( $existing && isset( $existing->interval ) && (int) $existing->interval !== $interval * MINUTE_IN_SECONDS ) {
            $this->clear_cron();
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wpexportmd_custom', $this->cron_hook );
        }

        update_option( $this->cron_interval_option, $interval );
    }

    private function clear_cron() {
        $timestamp = wp_next_scheduled( $this->cron_hook );
        if ( false !== $timestamp ) {
            wp_unschedule_event( $timestamp, $this->cron_hook );
        }
    }

    public function run_cron_export_sync() {
        $options = get_option( 'wpexportmd_settings', array() );
        $options = is_array( $options ) ? $options : array();

        $github_ready = ! empty( $options['github_auto_sync'] ) && ! empty( $options['github_enabled'] ) && ! empty( $options['github_repo'] ) && ! empty( $options['github_token'] );
        $drive_ready  = ! empty( $options['drive_auto_sync'] ) && ! empty( $options['drive_enabled'] ) && ( ! empty( $options['drive_token'] ) || ( ! empty( $options['drive_client_id'] ) && ! empty( $options['drive_client_secret'] ) && ! empty( $options['drive_refresh_token'] ) ) );

        if ( ! $github_ready && ! $drive_ready ) {
            $this->log_debug( 'Cron auto-sync aborted: no enabled integrations ready.' );
            $this->persist_debug_log();
            return;
        }

        $filters = array(
            'exclude_exported' => true,
        );
        $overrides = array(
            'github_enabled' => $github_ready,
            'drive_enabled'  => $drive_ready,
        );

        $this->log_debug( 'Cron auto-sync started.' );
        $this->exporter->export_all( $filters, $overrides, false );
        $this->log_debug( 'Cron auto-sync finished.' );
        $this->persist_debug_log();
    }

    public function register_custom_cron_schedule( $schedules ) {
        $interval_minutes = get_option( 'wpexportmd_settings', array() );
        $interval_minutes = is_array( $interval_minutes ) && isset( $interval_minutes['auto_sync_interval'] ) ? max( 5, (int) $interval_minutes['auto_sync_interval'] ) : 60;
        $interval_seconds = $interval_minutes * MINUTE_IN_SECONDS;

        $schedules['wpexportmd_custom'] = array(
            'interval' => $interval_seconds,
            'display'  => sprintf( __( 'Every %d minutes (Export to Markdown)', 'export-posts-to-markdown' ), $interval_minutes ),
        );

        return $schedules;
    }
}
