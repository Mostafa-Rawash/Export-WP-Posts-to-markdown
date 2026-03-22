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

    private $stats_cache_key = 'wpexportmd_stats_cache';
    private $activity_log_key = 'wpexportmd_activity_log';
    private $stats_cache_duration = HOUR_IN_SECONDS;

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
        add_action( 'admin_post_wpexportmd_dashboard_export', array( $this, 'handle_dashboard_export' ) );
        add_action( 'admin_post_wpexportmd_dashboard_export_github', array( $this, 'handle_dashboard_export_github' ) );
        add_action( 'admin_post_wpexportmd_dashboard_export_drive', array( $this, 'handle_dashboard_export_drive' ) );
        add_action( 'admin_notices', array( $this, 'render_debug_notices' ) );
    }

    public function add_page() {
        add_menu_page(
            __( 'Markdown Export/Import', 'export-posts-to-markdown' ),
            __( 'Markdown Export', 'export-posts-to-markdown' ),
            'manage_options',
            'export-to-markdown',
            array( $this, 'render_dashboard_page' ),
            'dashicons-media-code'
        );

        add_submenu_page(
            'export-to-markdown',
            __( 'Dashboard', 'export-posts-to-markdown' ),
            __( 'Dashboard', 'export-posts-to-markdown' ),
            'manage_options',
            'export-to-markdown',
            array( $this, 'render_dashboard_page' )
        );

        add_submenu_page(
            'export-to-markdown',
            __( 'Export to Markdown', 'export-posts-to-markdown' ),
            __( 'Export', 'export-posts-to-markdown' ),
            'manage_options',
            'export-to-markdown-export',
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
Export & Sync to GitHub                <?php submit_button( __( 'Export & Sync to GitHub', 'export-posts-to-markdown' ), 'secondary', 'wpexportmd_export_github', false ); ?>
                <?php submit_button( __( 'Export & Sync to Drive', 'export-posts-to-markdown' ), 'secondary', 'wpexportmd_export_drive', false ); ?>
            </form>
        </div>
        <?php
    }

    public function render_import_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Import posts from Markdown', 'export-posts-to-markdown' ); ?></h1>
            <p><?php esc_html_e( 'Upload a ZIP archive or a single .md file generated by this plugin to import or update posts.', 'export-posts-to-markdown' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'wpexportmd_import', 'wpexportmd_import_nonce' ); ?>
                <input type="hidden" name="action" value="wpexportmd_import" />
                <input type="file" name="wpexportmd_file" accept=".zip,.md" required />
                <?php submit_button( __( 'Import Markdown', 'export-posts-to-markdown' ) ); ?>
                <?php submit_button( __( 'Import & Sync to GitHub', 'export-posts-to-markdown' ), 'secondary', 'wpexportmd_import_github', false ); ?>
                <?php submit_button( __( 'Import & Sync to Drive', 'export-posts-to-markdown' ), 'secondary', 'wpexportmd_import_drive', false ); ?>
            </form>
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
                            <th scope="row"><label for="wpexportmd_drive_enabled"><?php esc_html_e( 'Enable Drive export/import', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="checkbox" name="wpexportmd_drive_enabled" id="wpexportmd_drive_enabled" value="1" <?php checked( ! empty( $options['drive_enabled'] ) ); ?> /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_token"><?php esc_html_e( 'Google Drive access token', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="password" name="wpexportmd_drive_token" id="wpexportmd_drive_token" value="<?php echo esc_attr( isset( $options['drive_token'] ) ? $options['drive_token'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpexportmd_drive_folder_id"><?php esc_html_e( 'Drive folder ID (optional)', 'export-posts-to-markdown' ); ?></label></th>
                            <td><input type="text" name="wpexportmd_drive_folder_id" id="wpexportmd_drive_folder_id" value="<?php echo esc_attr( isset( $options['drive_folder_id'] ) ? $options['drive_folder_id'] : '' ); ?>" class="regular-text" /></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Save Integration Settings', 'export-posts-to-markdown' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function render_dashboard_page() {
        $stats        = $this->get_export_stats();
        $activity_log = $this->get_activity_log();
        $options      = get_option( 'wpexportmd_settings', array() );
        $options      = is_array( $options ) ? $options : array();

        $github_connected = ! empty( $options['github_enabled'] ) && ! empty( $options['github_repo'] ) && ! empty( $options['github_token'] );
        $drive_connected = ! empty( $options['drive_enabled'] ) && ! empty( $options['drive_token'] );
        ?>
        <div class="wrap wpexportmd-dashboard">
            <h1><?php esc_html_e( 'Export to Markdown - Dashboard', 'export-posts-to-markdown' ); ?></h1>

            <div class="wpexportmd-stats-grid">
                <div class="wpexportmd-stat-card">
                    <h2 class="wpexportmd-stat-number"><?php echo esc_html( $stats['total_posts'] ); ?></h2>
                    <p class="wpexportmd-stat-label"><?php esc_html_e( 'Total Posts', 'export-posts-to-markdown' ); ?></p>
                </div>
                <div class="wpexportmd-stat-card wpexportmd-stat-published">
                    <h2 class="wpexportmd-stat-number"><?php echo esc_html( $stats['published'] ); ?></h2>
                    <p class="wpexportmd-stat-label"><?php esc_html_e( 'Published', 'export-posts-to-markdown' ); ?></p>
                </div>
                <div class="wpexportmd-stat-card">
                    <h2 class="wpexportmd-stat-number"><?php echo esc_html( $stats['draft'] ); ?></h2>
                    <p class="wpexportmd-stat-label"><?php esc_html_e( 'Drafts', 'export-posts-to-markdown' ); ?></p>
                </div>
                <div class="wpexportmd-stat-card">
                    <h2 class="wpexportmd-stat-number"><?php echo esc_html( $stats['pending'] ); ?></h2>
                    <p class="wpexportmd-stat-label"><?php esc_html_e( 'Pending Review', 'export-posts-to-markdown' ); ?></p>
                </div>
                <div class="wpexportmd-stat-card">
                    <h2 class="wpexportmd-stat-number"><?php echo esc_html( $stats['scheduled'] ); ?></h2>
                    <p class="wpexportmd-stat-label"><?php esc_html_e( 'Scheduled', 'export-posts-to-markdown' ); ?></p>
                </div>
            </div>

            <hr />

            <h2><?php esc_html_e( 'Export Statistics', 'export-posts-to-markdown' ); ?></h2>
            <div class="wpexportmd-stats-grid">
                <div class="wpexportmd-stat-card wpexportmd-stat-exported">
                    <h2 class="wpexportmd-stat-number"><?php echo esc_html( $stats['exported'] ); ?></h2>
                    <p class="wpexportmd-stat-label"><?php esc_html_e( 'Exported Posts', 'export-posts-to-markdown' ); ?></p>
                </div>
                <div class="wpexportmd-stat-card wpexportmd-stat-not-exported">
                    <h2 class="wpexportmd-stat-number"><?php echo esc_html( $stats['not_exported'] ); ?></h2>
                    <p class="wpexportmd-stat-label"><?php esc_html_e( 'Not Exported', 'export-posts-to-markdown' ); ?></p>
                </div>
            </div>
            <p class="wpexportmd-last-export">
                <?php
                if ( ! empty( $stats['last_export'] ) ) {
                    printf(
                        esc_html__( 'Last export: %s', 'export-posts-to-markdown' ),
                        esc_html( $stats['last_export'] )
                    );
                } else {
                    esc_html_e( 'No exports yet', 'export-posts-to-markdown' );
                }
                ?>
            </p>

            <hr />

            <h2><?php esc_html_e( 'Cloud Sync Status', 'export-posts-to-markdown' ); ?></h2>
            <div class="wpexportmd-sync-status">
                <p>
                    <?php if ( $github_connected ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php
                        printf(
                            esc_html__( 'GitHub: Connected (%s)', 'export-posts-to-markdown' ),
                            '<code>' . esc_html( $options['github_repo'] ) . '</code>'
                        );
                        ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-dismiss" style="color: #646970;"></span>
                        <?php esc_html_e( 'GitHub: Not configured', 'export-posts-to-markdown' ); ?>
                        - <a href="<?php echo esc_url( admin_url( 'tools.php?page=export-to-markdown-integrations' ) ); ?>"><?php esc_html_e( 'Configure', 'export-posts-to-markdown' ); ?></a>
                    <?php endif; ?>
                </p>
                <p>
                    <?php if ( $drive_connected ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e( 'Google Drive: Connected', 'export-posts-to-markdown' ); ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-dismiss" style="color: #646970;"></span>
                        <?php esc_html_e( 'Google Drive: Not configured', 'export-posts-to-markdown' ); ?>
                        - <a href="<?php echo esc_url( admin_url( 'tools.php?page=export-to-markdown-integrations' ) ); ?>"><?php esc_html_e( 'Configure', 'export-posts-to-markdown' ); ?></a>
                    <?php endif; ?>
                </p>
            </div>

            <hr />

            <h2><?php esc_html_e( 'Quick Actions', 'export-posts-to-markdown' ); ?></h2>
            <div class="wpexportmd-quick-actions">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field( 'wpexportmd_dashboard_export', 'wpexportmd_dashboard_export_nonce' ); ?>
                    <input type="hidden" name="action" value="wpexportmd_dashboard_export" />
                    <input type="hidden" name="wpexportmd_status" value="publish" />
                    <input type="hidden" name="wpexportmd_exclude_exported" value="1" />
                    <?php submit_button( __( 'Export All Published', 'export-posts-to-markdown' ), 'primary', '', false ); ?>
                </form>
                <?php if ( $github_connected ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field( 'wpexportmd_dashboard_export_github', 'wpexportmd_dashboard_export_github_nonce' ); ?>
                        <input type="hidden" name="action" value="wpexportmd_dashboard_export_github" />
                        <input type="hidden" name="wpexportmd_status" value="publish" />
                        <input type="hidden" name="wpexportmd_exclude_exported" value="1" />
                        <?php submit_button( __( 'Export & Sync to GitHub', 'export-posts-to-markdown' ), 'secondary', '', false ); ?>
                    </form>
                <?php endif; ?>
                <?php if ( $drive_connected ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field( 'wpexportmd_dashboard_export_drive', 'wpexportmd_dashboard_export_drive_nonce' ); ?>
                        <input type="hidden" name="action" value="wpexportmd_dashboard_export_drive" />
                        <input type="hidden" name="wpexportmd_status" value="publish" />
                        <input type="hidden" name="wpexportmd_exclude_exported" value="1" />
                        <?php submit_button( __( 'Export & Sync to Drive', 'export-posts-to-markdown' ), 'secondary', '', false ); ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $activity_log ) ) : ?>
                <hr />
                <h2><?php esc_html_e( 'Recent Activity', 'export-posts-to-markdown' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Action', 'export-posts-to-markdown' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'export-posts-to-markdown' ); ?></th>
                            <th><?php esc_html_e( 'Time', 'export-posts-to-markdown' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $activity_log as $activity ) : ?>
                            <tr>
                                <td><span class="dashicons dashicons-<?php echo esc_attr( $activity['icon'] ); ?>"></span> <?php echo esc_html( $activity['action'] ); ?></td>
                                <td><?php echo esc_html( $activity['details'] ); ?></td>
                                <td><?php echo esc_html( $activity['time'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <style>
            .wpexportmd-dashboard .wpexportmd-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
                margin: 20px 0;
            }
            .wpexportmd-dashboard .wpexportmd-stat-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                text-align: center;
                box-shadow: 0 1px 1px rgba(0,0,0,0.04);
            }
            .wpexportmd-dashboard .wpexportmd-stat-card:hover {
                border-color: #2271b1;
            }
            .wpexportmd-dashboard .wpexportmd-stat-number {
                font-size: 32px;
                font-weight: 600;
                margin: 0 0 5px 0;
                color: #1d2327;
            }
            .wpexportmd-dashboard .wpexportmd-stat-label {
                margin: 0;
                color: #646970;
                font-size: 14px;
            }
            .wpexportmd-dashboard .wpexportmd-stat-exported .wpexportmd-stat-number {
                color: #00a32a;
            }
            .wpexportmd-dashboard .wpexportmd-stat-not-exported .wpexportmd-stat-number {
                color: #d63638;
            }
            .wpexportmd-dashboard .wpexportmd-last-export {
                color: #646970;
                font-size: 13px;
                margin-top: -10px;
            }
            .wpexportmd-dashboard .wpexportmd-sync-status p {
                margin: 8px 0;
            }
            .wpexportmd-dashboard .wpexportmd-quick-actions {
                margin: 15px 0;
            }
        </style>
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

        $this->exporter->export_all( $filters, $sync_overrides );
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

        if ( isset( $_POST['wpexportmd_github_token'] ) && '' !== $_POST['wpexportmd_github_token'] ) {
            $options['github_token'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_github_token'] ) );
        }

        $options['drive_enabled'] = ! empty( $_POST['wpexportmd_drive_enabled'] );
        if ( isset( $_POST['wpexportmd_drive_token'] ) && '' !== $_POST['wpexportmd_drive_token'] ) {
            $options['drive_token'] = sanitize_text_field( wp_unslash( $_POST['wpexportmd_drive_token'] ) );
        }

        $options['drive_folder_id'] = isset( $_POST['wpexportmd_drive_folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wpexportmd_drive_folder_id'] ) ) : '';

        update_option( 'wpexportmd_settings', $options );

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

    public function get_export_stats() {
        $cached = get_transient( $this->stats_cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $stats = array(
            'total_posts'   => 0,
            'published'     => 0,
            'draft'         => 0,
            'pending'       => 0,
            'scheduled'     => 0,
            'exported'      => 0,
            'not_exported'  => 0,
            'last_export'   => '',
        );

        $counts = wp_count_posts( 'post' );
        if ( $counts ) {
            $stats['total_posts'] = (int) $counts->publish + (int) $counts->draft + (int) $counts->pending + (int) $counts->future + (int) $counts->private;
            $stats['published']   = (int) $counts->publish;
            $stats['draft']       = (int) $counts->draft;
            $stats['pending']     = (int) $counts->pending;
            $stats['scheduled']   = (int) $counts->future;
        }

        $exported_posts = get_posts(
            array(
                'post_type'      => 'post',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => '_wpexportmd_exported',
                        'value'   => 'yes',
                        'compare' => '=',
                    ),
                ),
            )
        );
        $stats['exported']     = count( $exported_posts );
        $stats['not_exported'] = $stats['total_posts'] - $stats['exported'];

        if ( ! empty( $exported_posts ) ) {
            $last_exported_id = max( $exported_posts );
            $last_export      = get_post_meta( $last_exported_id, '_wpexportmd_last_exported', true );
            if ( $last_export ) {
                $stats['last_export'] = $last_export . ' UTC';
            }
        }

        set_transient( $this->stats_cache_key, $stats, $this->stats_cache_duration );

        return $stats;
    }

    public function get_activity_log( $limit = 10 ) {
        $log = get_option( $this->activity_log_key, array() );
        if ( ! is_array( $log ) ) {
            return array();
        }

        return array_slice( $log, 0, $limit );
    }

    public function log_activity( $action, $details = '' ) {
        $log = get_option( $this->activity_log_key, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        $icon_map = array(
            'export'  => 'upload',
            'import'  => 'download',
            'github'  => 'github',
            'drive'   => '-cloud',
            'settings' => 'admin-settings',
        );

        $entry = array(
            'action'  => $action,
            'details' => $details,
            'time'    => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
            'icon'    => isset( $icon_map[ $action ] ) ? $icon_map[ $action ] : 'admin-generic',
        );

        array_unshift( $log, $entry );

        $log = array_slice( $log, 0, 20 );

        update_option( $this->activity_log_key, $log );

        delete_transient( $this->stats_cache_key );
    }

    public function handle_dashboard_export() {
        $this->log_debug( 'Dashboard export request received.' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->fail_and_die( esc_html__( 'You do not have permission to export content.', 'export-posts-to-markdown' ) );
        }

        if ( ! isset( $_POST['wpexportmd_dashboard_export_nonce'] ) || ! wp_verify_nonce( $_POST['wpexportmd_dashboard_export_nonce'], 'wpexportmd_dashboard_export' ) ) {
            $this->fail_and_die( esc_html__( 'Security check failed.', 'export-posts-to-markdown' ) );
        }

        $filters        = array();
        $sync_overrides = array();

        if ( ! empty( $_POST['wpexportmd_status'] ) ) {
            $filters['status'] = sanitize_key( wp_unslash( $_POST['wpexportmd_status'] ) );
        }

        $filters['exclude_exported'] = ! empty( $_POST['wpexportmd_exclude_exported'] );

        $this->exporter->export_all( $filters, $sync_overrides );
        $this->persist_debug_log();

        exit;
    }

    public function handle_dashboard_export_github() {
        $this->log_debug( 'Dashboard export + GitHub sync request received.' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->fail_and_die( esc_html__( 'You do not have permission to export content.', 'export-posts-to-markdown' ) );
        }

        if ( ! isset( $_POST['wpexportmd_dashboard_export_github_nonce'] ) || ! wp_verify_nonce( $_POST['wpexportmd_dashboard_export_github_nonce'], 'wpexportmd_dashboard_export_github' ) ) {
            $this->fail_and_die( esc_html__( 'Security check failed.', 'export-posts-to-markdown' ) );
        }

        $filters        = array();
        $sync_overrides = array( 'github_enabled' => true );

        if ( ! empty( $_POST['wpexportmd_status'] ) ) {
            $filters['status'] = sanitize_key( wp_unslash( $_POST['wpexportmd_status'] ) );
        }

        $filters['exclude_exported'] = ! empty( $_POST['wpexportmd_exclude_exported'] );

        $this->exporter->export_all( $filters, $sync_overrides );
        $this->persist_debug_log();

        exit;
    }

    public function handle_dashboard_export_drive() {
        $this->log_debug( 'Dashboard export + Drive sync request received.' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->fail_and_die( esc_html__( 'You do not have permission to export content.', 'export-posts-to-markdown' ) );
        }

        if ( ! isset( $_POST['wpexportmd_dashboard_export_drive_nonce'] ) || ! wp_verify_nonce( $_POST['wpexportmd_dashboard_export_drive_nonce'], 'wpexportmd_dashboard_export_drive' ) ) {
            $this->fail_and_die( esc_html__( 'Security check failed.', 'export-posts-to-markdown' ) );
        }

        $filters        = array();
        $sync_overrides = array( 'drive_enabled' => true );

        if ( ! empty( $_POST['wpexportmd_status'] ) ) {
            $filters['status'] = sanitize_key( wp_unslash( $_POST['wpexportmd_status'] ) );
        }

        $filters['exclude_exported'] = ! empty( $_POST['wpexportmd_exclude_exported'] );

        $this->exporter->export_all( $filters, $sync_overrides );
        $this->persist_debug_log();

        exit;
    }
}
