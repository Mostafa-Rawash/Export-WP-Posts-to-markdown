<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-pwpm-markdown.php';
require_once __DIR__ . '/class-pwpm-media.php';
require_once __DIR__ . '/class-pwpm-sync.php';
require_once __DIR__ . '/class-pwpm-exporter.php';
require_once __DIR__ . '/class-pwpm-importer.php';

class WP_Posts_Markdown {

    private $debug_log = array();
    private $debug_transient_key = 'postsmd_last_debug';

    private $exporter;
    private $importer;
    private $sync;

    private $stats_cache_key = 'postsmd_stats_cache';
    private $activity_log_key = 'postsmd_activity_log';
    private $stats_cache_duration = HOUR_IN_SECONDS;

    public function __construct() {
        $markdown       = new PWPM_Markdown();
        $media          = new PWPM_Media( array( $this, 'log_debug' ) );
        $options        = get_option( 'postsmd_settings', array() );
        $options        = is_array( $options ) ? $options : array();
        $this->sync     = new PWPM_Sync( array( $this, 'log_debug' ), $options );
        $this->exporter = new PWPM_Exporter(
            $markdown,
            array( $this, 'log_debug' ),
            array( $this, 'fail_and_die' ),
            array( $this, 'stream_file_to_browser' ),
            $this->sync
        );
        $this->importer = new PWPM_Importer(
            $markdown,
            $media,
            array( $this, 'log_debug' ),
            array( $this, 'fail_and_die' ),
            $this->sync
        );

        add_action( 'admin_menu', array( $this, 'add_page' ) );
        add_action( 'admin_post_postsmd', array( $this, 'handle_export' ) );
        add_action( 'admin_post_postsmd_import', array( $this, 'handle_import' ) );
        add_action( 'admin_post_postsmd_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_postsmd_dashboard_export', array( $this, 'handle_dashboard_export' ) );
        add_action( 'admin_post_postsmd_dashboard_export_github', array( $this, 'handle_dashboard_export_github' ) );
        add_action( 'admin_post_postsmd_dashboard_export_drive', array( $this, 'handle_dashboard_export_drive' ) );
        add_action( 'admin_notices', array( $this, 'render_debug_notices' ) );
    }

    public function add_page() {
        add_menu_page(
            __( 'Posts Markdown', 'posts-markdown' ),
            __( 'Posts Markdown', 'posts-markdown' ),
            'manage_options',
            'posts-markdown',
            array( $this, 'render_dashboard_page' ),
            'dashicons-media-code'
        );

        add_submenu_page(
            'posts-markdown',
            __( 'Dashboard', 'posts-markdown' ),
            __( 'Dashboard', 'posts-markdown' ),
            'manage_options',
            'posts-markdown',
            array( $this, 'render_dashboard_page' )
        );

        add_submenu_page(
            'posts-markdown',
            __( 'Export', 'posts-markdown' ),
            __( 'Export', 'posts-markdown' ),
            'manage_options',
            'posts-markdown-export',
            array( $this, 'render_export_page' )
        );

        add_submenu_page(
            'posts-markdown',
            __( 'Import', 'posts-markdown' ),
            __( 'Import', 'posts-markdown' ),
            'manage_options',
            'posts-markdown-import',
            array( $this, 'render_import_page' )
        );

        add_submenu_page(
            'posts-markdown',
            __( 'Integrations', 'posts-markdown' ),
            __( 'Integrations', 'posts-markdown' ),
            'manage_options',
            'posts-markdown-integrations',
            array( $this, 'render_integrations_page' )
        );
    }

    public function render_export_page() {
        $options = get_option( 'postsmd_settings', array() );
        $options = is_array( $options ) ? $options : array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Export Posts to Markdown', 'posts-markdown' ); ?></h1>
            <p><?php esc_html_e( 'Choose filters (optional) then download posts as Markdown files in a single ZIP archive.', 'posts-markdown' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'postsmd', 'postsmd_nonce' ); ?>
                <input type="hidden" name="action" value="postsmd" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="postsmd_status"><?php esc_html_e( 'Status', 'posts-markdown' ); ?></label></th>
                            <td>
                                <select name="postsmd_status" id="postsmd_status">
                                    <option value=""><?php esc_html_e( 'All', 'posts-markdown' ); ?></option>
                                    <option value="publish"><?php esc_html_e( 'Published', 'posts-markdown' ); ?></option>
                                    <option value="draft"><?php esc_html_e( 'Draft', 'posts-markdown' ); ?></option>
                                    <option value="pending"><?php esc_html_e( 'Pending', 'posts-markdown' ); ?></option>
                                    <option value="future"><?php esc_html_e( 'Scheduled', 'posts-markdown' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_author"><?php esc_html_e( 'Author', 'posts-markdown' ); ?></label></th>
                            <td>
                                <select name="postsmd_author" id="postsmd_author">
                                    <option value=""><?php esc_html_e( 'All authors', 'posts-markdown' ); ?></option>
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
                            <th scope="row"><?php esc_html_e( 'Date range', 'posts-markdown' ); ?></th>
                            <td>
                                <label>
                                    <?php esc_html_e( 'From', 'posts-markdown' ); ?>
                                    <input type="date" name="postsmd_start_date" />
                                </label>
                                <label style="margin-left:10px;">
                                    <?php esc_html_e( 'To', 'posts-markdown' ); ?>
                                    <input type="date" name="postsmd_end_date" />
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_exclude_exported"><?php esc_html_e( 'Exclude previously exported', 'posts-markdown' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="postsmd_exclude_exported" id="postsmd_exclude_exported" value="1" checked />
                                    <?php esc_html_e( 'Skip posts already marked as exported', 'posts-markdown' ); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Download Markdown ZIP', 'posts-markdown' ) ); ?>
                <?php submit_button( __( 'Export & Sync to GitHub', 'posts-markdown' ), 'secondary', 'postsmd_export_github', false ); ?>
                <?php submit_button( __( 'Export & Sync to Drive', 'posts-markdown' ), 'secondary', 'postsmd_export_drive', false ); ?>
            </form>
        </div>
        <?php
    }

    public function render_import_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Import Posts from Markdown', 'posts-markdown' ); ?></h1>
            <p><?php esc_html_e( 'Upload a ZIP archive or a single .md file generated by this plugin to import or update posts.', 'posts-markdown' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'postsmd_import', 'postsmd_import_nonce' ); ?>
                <input type="hidden" name="action" value="postsmd_import" />
                <input type="file" name="postsmd_file" accept=".zip,.md" required />
                <?php submit_button( __( 'Import Markdown', 'posts-markdown' ) ); ?>
                <?php submit_button( __( 'Import & Sync to GitHub', 'posts-markdown' ), 'secondary', 'postsmd_import_github', false ); ?>
                <?php submit_button( __( 'Import & Sync to Drive', 'posts-markdown' ), 'secondary', 'postsmd_import_drive', false ); ?>
            </form>
        </div>
        <?php
    }

    public function render_integrations_page() {
        $options = get_option( 'postsmd_settings', array() );
        $options = is_array( $options ) ? $options : array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Integrations (GitHub / Drive)', 'posts-markdown' ); ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'postsmd_save_settings', 'postsmd_save_settings_nonce' ); ?>
                <input type="hidden" name="action" value="postsmd_save_settings" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="postsmd_github_enabled"><?php esc_html_e( 'Enable GitHub sync', 'posts-markdown' ); ?></label></th>
                            <td><input type="checkbox" name="postsmd_github_enabled" id="postsmd_github_enabled" value="1" <?php checked( ! empty( $options['github_enabled'] ) ); ?> /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_github_repo"><?php esc_html_e( 'GitHub repo (owner/repo)', 'posts-markdown' ); ?></label></th>
                            <td><input type="text" name="postsmd_github_repo" id="postsmd_github_repo" value="<?php echo esc_attr( isset( $options['github_repo'] ) ? $options['github_repo'] : '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_github_branch"><?php esc_html_e( 'GitHub branch', 'posts-markdown' ); ?></label></th>
                            <td><input type="text" name="postsmd_github_branch" id="postsmd_github_branch" value="<?php echo esc_attr( isset( $options['github_branch'] ) ? $options['github_branch'] : 'main' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_github_path"><?php esc_html_e( 'GitHub path prefix', 'posts-markdown' ); ?></label></th>
                            <td><input type="text" name="postsmd_github_path" id="postsmd_github_path" value="<?php echo esc_attr( isset( $options['github_path'] ) ? $options['github_path'] : 'exports' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_github_token"><?php esc_html_e( 'GitHub token', 'posts-markdown' ); ?></label></th>
                            <td><input type="password" name="postsmd_github_token" id="postsmd_github_token" value="<?php echo esc_attr( isset( $options['github_token'] ) ? $options['github_token'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_drive_enabled"><?php esc_html_e( 'Enable Drive sync', 'posts-markdown' ); ?></label></th>
                            <td><input type="checkbox" name="postsmd_drive_enabled" id="postsmd_drive_enabled" value="1" <?php checked( ! empty( $options['drive_enabled'] ) ); ?> /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_drive_token"><?php esc_html_e( 'Google Drive access token', 'posts-markdown' ); ?></label></th>
                            <td><input type="password" name="postsmd_drive_token" id="postsmd_drive_token" value="<?php echo esc_attr( isset( $options['drive_token'] ) ? $options['drive_token'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="postsmd_drive_folder_id"><?php esc_html_e( 'Drive folder ID (optional)', 'posts-markdown' ); ?></label></th>
                            <td><input type="text" name="postsmd_drive_folder_id" id="postsmd_drive_folder_id" value="<?php echo esc_attr( isset( $options['drive_folder_id'] ) ? $options['drive_folder_id'] : '' ); ?>" class="regular-text" /></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Save Integration Settings', 'posts-markdown' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function render_dashboard_page() {
        $stats        = $this->get_export_stats();
        $activity_log = $this->get_activity_log();
        $options      = get_option( 'postsmd_settings', array() );
        $options      = is_array( $options ) ? $options : array();

        $github_connected = ! empty( $options['github_enabled'] ) && ! empty( $options['github_repo'] ) && ! empty( $options['github_token'] );
        $drive_connected = ! empty( $options['drive_enabled'] ) && ! empty( $options['drive_token'] );
        ?>
        <div class="wrap postsmd-dashboard">
            <h1><?php esc_html_e( 'Posts Markdown - Dashboard', 'posts-markdown' ); ?></h1>

            <div class="postsmd-stats-grid">
                <div class="postsmd-stat-card">
                    <h2 class="postsmd-stat-number"><?php echo esc_html( $stats['total_posts'] ); ?></h2>
                    <p class="postsmd-stat-label"><?php esc_html_e( 'Total Posts', 'posts-markdown' ); ?></p>
                </div>
                <div class="postsmd-stat-card postsmd-stat-published">
                    <h2 class="postsmd-stat-number"><?php echo esc_html( $stats['published'] ); ?></h2>
                    <p class="postsmd-stat-label"><?php esc_html_e( 'Published', 'posts-markdown' ); ?></p>
                </div>
                <div class="postsmd-stat-card">
                    <h2 class="postsmd-stat-number"><?php echo esc_html( $stats['draft'] ); ?></h2>
                    <p class="postsmd-stat-label"><?php esc_html_e( 'Drafts', 'posts-markdown' ); ?></p>
                </div>
                <div class="postsmd-stat-card">
                    <h2 class="postsmd-stat-number"><?php echo esc_html( $stats['pending'] ); ?></h2>
                    <p class="postsmd-stat-label"><?php esc_html_e( 'Pending Review', 'posts-markdown' ); ?></p>
                </div>
                <div class="postsmd-stat-card">
                    <h2 class="postsmd-stat-number"><?php echo esc_html( $stats['scheduled'] ); ?></h2>
                    <p class="postsmd-stat-label"><?php esc_html_e( 'Scheduled', 'posts-markdown' ); ?></p>
                </div>
            </div>

            <hr />

            <h2><?php esc_html_e( 'Export Statistics', 'posts-markdown' ); ?></h2>
            <div class="postsmd-stats-grid">
                <div class="postsmd-stat-card postsmd-stat-exported">
                    <h2 class="postsmd-stat-number"><?php echo esc_html( $stats['exported'] ); ?></h2>
                    <p class="postsmd-stat-label"><?php esc_html_e( 'Exported Posts', 'posts-markdown' ); ?></p>
                </div>
                <div class="postsmd-stat-card postsmd-stat-not-exported">
                    <h2 class="postsmd-stat-number"><?php echo esc_html( $stats['not_exported'] ); ?></h2>
                    <p class="postsmd-stat-label"><?php esc_html_e( 'Not Exported', 'posts-markdown' ); ?></p>
                </div>
            </div>
            <p class="postsmd-last-export">
                <?php
                if ( ! empty( $stats['last_export'] ) ) {
                    printf(
                        esc_html__( 'Last export: %s', 'posts-markdown' ),
                        esc_html( $stats['last_export'] )
                    );
                } else {
                    esc_html_e( 'No exports yet', 'posts-markdown' );
                }
                ?>
            </p>

            <hr />

            <h2><?php esc_html_e( 'Cloud Sync Status', 'posts-markdown' ); ?></h2>
            <div class="postsmd-sync-status">
                <p>
                    <?php if ( $github_connected ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php
                        printf(
                            esc_html__( 'GitHub: Connected (%s)', 'posts-markdown' ),
                            '<code>' . esc_html( $options['github_repo'] ) . '</code>'
                        );
                        ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-dismiss" style="color: #646970;"></span>
                        <?php esc_html_e( 'GitHub: Not configured', 'posts-markdown' ); ?>
                        - <a href="<?php echo esc_url( admin_url( 'admin.php?page=posts-markdown-integrations' ) ); ?>"><?php esc_html_e( 'Configure', 'posts-markdown' ); ?></a>
                    <?php endif; ?>
                </p>
                <p>
                    <?php if ( $drive_connected ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e( 'Google Drive: Connected', 'posts-markdown' ); ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-dismiss" style="color: #646970;"></span>
                        <?php esc_html_e( 'Google Drive: Not configured', 'posts-markdown' ); ?>
                        - <a href="<?php echo esc_url( admin_url( 'admin.php?page=posts-markdown-integrations' ) ); ?>"><?php esc_html_e( 'Configure', 'posts-markdown' ); ?></a>
                    <?php endif; ?>
                </p>
            </div>

            <hr />

            <h2><?php esc_html_e( 'Quick Actions', 'posts-markdown' ); ?></h2>
            <div class="postsmd-quick-actions">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field( 'postsmd_dashboard_export', 'postsmd_dashboard_export_nonce' ); ?>
                    <input type="hidden" name="action" value="postsmd_dashboard_export" />
                    <input type="hidden" name="postsmd_status" value="publish" />
                    <input type="hidden" name="postsmd_exclude_exported" value="1" />
                    <?php submit_button( __( 'Export All Published', 'posts-markdown' ), 'primary', '', false ); ?>
                </form>
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
                    <input type="hidden" name="page" value="posts-markdown-import" />
                    <?php submit_button( __( 'Import Posts', 'posts-markdown' ), 'secondary', '', false ); ?>
                </form>
                <?php if ( $github_connected ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field( 'postsmd_dashboard_export_github', 'postsmd_dashboard_export_github_nonce' ); ?>
                        <input type="hidden" name="action" value="postsmd_dashboard_export_github" />
                        <input type="hidden" name="postsmd_status" value="publish" />
                        <input type="hidden" name="postsmd_exclude_exported" value="1" />
                        <?php submit_button( __( 'Export & Sync to GitHub', 'posts-markdown' ), 'secondary', '', false ); ?>
                    </form>
                <?php endif; ?>
                <?php if ( $drive_connected ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field( 'postsmd_dashboard_export_drive', 'postsmd_dashboard_export_drive_nonce' ); ?>
                        <input type="hidden" name="action" value="postsmd_dashboard_export_drive" />
                        <input type="hidden" name="postsmd_status" value="publish" />
                        <input type="hidden" name="postsmd_exclude_exported" value="1" />
                        <?php submit_button( __( 'Export & Sync to Drive', 'posts-markdown' ), 'secondary', '', false ); ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $activity_log ) ) : ?>
                <hr />
                <h2><?php esc_html_e( 'Recent Activity', 'posts-markdown' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Action', 'posts-markdown' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'posts-markdown' ); ?></th>
                            <th><?php esc_html_e( 'Time', 'posts-markdown' ); ?></th>
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
            .postsmd-dashboard .postsmd-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
                margin: 20px 0;
            }
            .postsmd-dashboard .postsmd-stat-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                text-align: center;
                box-shadow: 0 1px 1px rgba(0,0,0,0.04);
            }
            .postsmd-dashboard .postsmd-stat-card:hover {
                border-color: #2271b1;
            }
            .postsmd-dashboard .postsmd-stat-number {
                font-size: 32px;
                font-weight: 600;
                margin: 0 0 5px 0;
                color: #1d2327;
            }
            .postsmd-dashboard .postsmd-stat-label {
                margin: 0;
                color: #646970;
                font-size: 14px;
            }
            .postsmd-dashboard .postsmd-stat-exported .postsmd-stat-number {
                color: #00a32a;
            }
            .postsmd-dashboard .postsmd-stat-not-exported .postsmd-stat-number {
                color: #d63638;
            }
            .postsmd-dashboard .postsmd-last-export {
                color: #646970;
                font-size: 13px;
                margin-top: -10px;
            }
            .postsmd-dashboard .postsmd-sync-status p {
                margin: 8px 0;
            }
            .postsmd-dashboard .postsmd-quick-actions {
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
            $this->fail_and_die( esc_html__( 'You do not have permission to export content.', 'posts-markdown' ) );
        }

        if ( ! isset( $_POST['postsmd_nonce'] ) || ! wp_verify_nonce( $_POST['postsmd_nonce'], 'postsmd' ) ) {
            $this->log_debug( 'Nonce verification failed.' );
            $this->fail_and_die( esc_html__( 'Security check failed.', 'posts-markdown' ) );
        }

        $filters         = array();
        $sync_overrides  = array();

        if ( ! empty( $_POST['postsmd_status'] ) ) {
            $filters['status'] = sanitize_key( wp_unslash( $_POST['postsmd_status'] ) );
        }

        if ( ! empty( $_POST['postsmd_author'] ) ) {
            $filters['author'] = absint( $_POST['postsmd_author'] );
        }

        if ( ! empty( $_POST['postsmd_start_date'] ) && false !== strtotime( $_POST['postsmd_start_date'] ) ) {
            $filters['start_date'] = sanitize_text_field( wp_unslash( $_POST['postsmd_start_date'] ) );
        }

        if ( ! empty( $_POST['postsmd_end_date'] ) && false !== strtotime( $_POST['postsmd_end_date'] ) ) {
            $filters['end_date'] = sanitize_text_field( wp_unslash( $_POST['postsmd_end_date'] ) );
        }

        $filters['exclude_exported'] = ! empty( $_POST['postsmd_exclude_exported'] );

        if ( ! empty( $_POST['postsmd_export_github'] ) ) {
            $sync_overrides['github_enabled'] = true;
        }

        if ( ! empty( $_POST['postsmd_export_drive'] ) ) {
            $sync_overrides['drive_enabled'] = true;
        }

        $added_count = 0;
        $this->exporter->export_all( $filters, $sync_overrides );
        $this->persist_debug_log();
        delete_transient( $this->stats_cache_key );
        $this->log_activity( 'export', sprintf( 'Exported posts' ) );

        exit;
    }

    public function handle_import() {
        $this->log_debug( 'Import request received at ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC.' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->log_debug( 'Capability check failed for current user.' );
            $this->fail_and_die( esc_html__( 'You do not have permission to import content.', 'posts-markdown' ) );
        }

        if ( ! isset( $_POST['postsmd_import_nonce'] ) || ! wp_verify_nonce( $_POST['postsmd_import_nonce'], 'postsmd_import' ) ) {
            $this->log_debug( 'Import nonce verification failed.' );
            $this->fail_and_die( esc_html__( 'Security check failed for import.', 'posts-markdown' ) );
        }

        if ( empty( $_FILES['postsmd_file'] ) || ! is_array( $_FILES['postsmd_file'] ) ) {
            $this->log_debug( 'No file uploaded for import.' );
            $this->fail_and_die( esc_html__( 'Please choose a Markdown file or ZIP archive to import.', 'posts-markdown' ) );
        }

        $file = $_FILES['postsmd_file'];

        if ( ! empty( $file['error'] ) ) {
            $this->log_debug( 'File upload error code: ' . $file['error'] );
            $this->fail_and_die( esc_html__( 'File upload failed. Please try again.', 'posts-markdown' ) );
        }

        $tmp_path = $file['tmp_name'];
        $name     = $file['name'];

        if ( ! file_exists( $tmp_path ) || ! is_readable( $tmp_path ) ) {
            $this->log_debug( 'Uploaded file missing at ' . $tmp_path . '.' );
            $this->fail_and_die( esc_html__( 'Uploaded file could not be read.', 'posts-markdown' ) );
        }

        $sync_overrides = array();
        if ( ! empty( $_POST['postsmd_import_github'] ) ) {
            $sync_overrides['github_enabled'] = true;
        }
        if ( ! empty( $_POST['postsmd_import_drive'] ) ) {
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
        delete_transient( $this->stats_cache_key );
        $this->log_activity( 'import', sprintf( 'Imported: %d created, %d updated', $stats['created'], $stats['updated'] ) );

        wp_safe_redirect( admin_url( 'admin.php?page=posts-markdown' ) );
        exit;
    }

    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            $this->fail_and_die( esc_html__( 'You do not have permission to update settings.', 'posts-markdown' ) );
        }

        if ( ! isset( $_POST['postsmd_save_settings_nonce'] ) || ! wp_verify_nonce( $_POST['postsmd_save_settings_nonce'], 'postsmd_save_settings' ) ) {
            $this->fail_and_die( esc_html__( 'Security check failed for settings.', 'posts-markdown' ) );
        }

        $options = get_option( 'postsmd_settings', array() );

        $options['github_enabled'] = ! empty( $_POST['postsmd_github_enabled'] );
        $options['github_repo']   = isset( $_POST['postsmd_github_repo'] ) ? sanitize_text_field( wp_unslash( $_POST['postsmd_github_repo'] ) ) : '';
        $options['github_branch'] = isset( $_POST['postsmd_github_branch'] ) ? sanitize_text_field( wp_unslash( $_POST['postsmd_github_branch'] ) ) : 'main';
        $options['github_path']   = isset( $_POST['postsmd_github_path'] ) ? sanitize_text_field( wp_unslash( $_POST['postsmd_github_path'] ) ) : 'exports';

        if ( isset( $_POST['postsmd_github_token'] ) && '' !== $_POST['postsmd_github_token'] ) {
            $options['github_token'] = sanitize_text_field( wp_unslash( $_POST['postsmd_github_token'] ) );
        }

        $options['drive_enabled'] = ! empty( $_POST['postsmd_drive_enabled'] );
        if ( isset( $_POST['postsmd_drive_token'] ) && '' !== $_POST['postsmd_drive_token'] ) {
            $options['drive_token'] = sanitize_text_field( wp_unslash( $_POST['postsmd_drive_token'] ) );
        }

        $options['drive_folder_id'] = isset( $_POST['postsmd_drive_folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['postsmd_drive_folder_id'] ) ) : '';

        update_option( 'postsmd_settings', $options );

        wp_safe_redirect( admin_url( 'admin.php?page=posts-markdown' ) );
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
            'toplevel_page_posts-markdown',
            'posts-markdown_page_posts-markdown-export',
            'posts-markdown_page_posts-markdown-import',
            'posts-markdown_page_posts-markdown-integrations',
        );

        if ( ! $screen || ! in_array( $screen->id, $allowed_ids, true ) ) {
            return;
        }

        $messages = get_transient( $this->debug_transient_key );
        if ( empty( $messages ) || ! is_array( $messages ) ) {
            return;
        }

        delete_transient( $this->debug_transient_key );

        echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Posts Markdown debug log', 'posts-markdown' ) . '</strong></p><ul>';
        foreach ( $messages as $message ) {
            echo '<li>' . esc_html( $message ) . '</li>';
        }
        echo '</ul></div>';
    }

    public function stream_file_to_browser( $path, $download_name ) {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            $this->log_debug( 'Export file missing or unreadable at ' . $path . '.' );
            $this->fail_and_die( esc_html__( 'Export file could not be read.', 'posts-markdown' ) );
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
            $this->fail_and_die( esc_html__( 'Failed to stream the export file.', 'posts-markdown' ) );
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
                        'key'     => '_postsmd_exported',
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
            $last_export      = get_post_meta( $last_exported_id, '_postsmd_last_exported', true );
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
            'drive'   => 'cloud',
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
            $this->fail_and_die( esc_html__( 'You do not have permission to export content.', 'posts-markdown' ) );
        }

        if ( ! isset( $_POST['postsmd_dashboard_export_nonce'] ) || ! wp_verify_nonce( $_POST['postsmd_dashboard_export_nonce'], 'postsmd_dashboard_export' ) ) {
            $this->fail_and_die( esc_html__( 'Security check failed.', 'posts-markdown' ) );
        }

        $filters        = array();
        $sync_overrides = array();

        if ( ! empty( $_POST['postsmd_status'] ) ) {
            $filters['status'] = sanitize_key( wp_unslash( $_POST['postsmd_status'] ) );
        }

        $filters['exclude_exported'] = ! empty( $_POST['postsmd_exclude_exported'] );

        $this->exporter->export_all( $filters, $sync_overrides );
        $this->persist_debug_log();
        delete_transient( $this->stats_cache_key );
        $this->log_activity( 'export', 'Dashboard export' );

        exit;
    }

    public function handle_dashboard_export_github() {
        $this->log_debug( 'Dashboard export + GitHub sync request received.' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->fail_and_die( esc_html__( 'You do not have permission to export content.', 'posts-markdown' ) );
        }

        if ( ! isset( $_POST['postsmd_dashboard_export_github_nonce'] ) || ! wp_verify_nonce( $_POST['postsmd_dashboard_export_github_nonce'], 'postsmd_dashboard_export_github' ) ) {
            $this->fail_and_die( esc_html__( 'Security check failed.', 'posts-markdown' ) );
        }

        $filters        = array();
        $sync_overrides = array( 'github_enabled' => true );

        if ( ! empty( $_POST['postsmd_status'] ) ) {
            $filters['status'] = sanitize_key( wp_unslash( $_POST['postsmd_status'] ) );
        }

        $filters['exclude_exported'] = ! empty( $_POST['postsmd_exclude_exported'] );

        $this->exporter->export_all( $filters, $sync_overrides );
        $this->persist_debug_log();
        delete_transient( $this->stats_cache_key );
        $this->log_activity( 'github', 'Export + GitHub sync' );

        exit;
    }

    public function handle_dashboard_export_drive() {
        $this->log_debug( 'Dashboard export + Drive sync request received.' );

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->fail_and_die( esc_html__( 'You do not have permission to export content.', 'posts-markdown' ) );
        }

        if ( ! isset( $_POST['postsmd_dashboard_export_drive_nonce'] ) || ! wp_verify_nonce( $_POST['postsmd_dashboard_export_drive_nonce'], 'postsmd_dashboard_export_drive' ) ) {
            $this->fail_and_die( esc_html__( 'Security check failed.', 'posts-markdown' ) );
        }

        $filters        = array();
        $sync_overrides = array( 'drive_enabled' => true );

        if ( ! empty( $_POST['postsmd_status'] ) ) {
            $filters['status'] = sanitize_key( wp_unslash( $_POST['postsmd_status'] ) );
        }

        $filters['exclude_exported'] = ! empty( $_POST['postsmd_exclude_exported'] );

        $this->exporter->export_all( $filters, $sync_overrides );
        $this->persist_debug_log();
        delete_transient( $this->stats_cache_key );
        $this->log_activity( 'drive', 'Export + Drive sync' );

        exit;
    }
}
