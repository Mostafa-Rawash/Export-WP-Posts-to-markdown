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
    private $debug_log_option_key = 'postsmd_debug_log';

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
        add_action( 'wp_ajax_postsmd_get_post_content', array( $this, 'ajax_get_post_content' ) );
        add_action( 'wp_ajax_postsmd_clear_debug_log', array( $this, 'ajax_clear_debug_log' ) );
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
        $debug_log    = $this->get_debug_log();
        $options      = get_option( 'postsmd_settings', array() );
        $options      = is_array( $options ) ? $options : array();

        $github_connected = ! empty( $options['github_enabled'] ) && ! empty( $options['github_repo'] ) && ! empty( $options['github_token'] );
        $drive_connected = ! empty( $options['drive_enabled'] ) && ! empty( $options['drive_token'] );
        $nonce = wp_create_nonce( 'postsmd_debug' );
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

            <hr />

            <h2><?php esc_html_e( 'Debug Tools', 'posts-markdown' ); ?></h2>
            <div class="postsmd-debug-tools">
                <p>
                    <label for="postsmd-post-id"><?php esc_html_e( 'Inspect Post Content:', 'posts-markdown' ); ?></label>
                    <input type="number" id="postsmd-post-id" placeholder="<?php esc_attr_e( 'Post ID', 'posts-markdown' ); ?>" style="width: 100px; margin: 0 5px;" />
                    <button type="button" class="button" id="postsmd-view-content"><?php esc_html_e( 'View Content', 'posts-markdown' ); ?></button>
                </p>
                <div id="postsmd-post-content-result" style="display: none; margin-top: 10px;">
                    <h4><?php esc_html_e( 'Post Content', 'posts-markdown' ); ?>: <span id="postsmd-post-title"></span> (ID: <span id="postsmd-post-id-display"></span>, Length: <span id="postsmd-post-length"></span> bytes)</h4>
                    <pre id="postsmd-post-content" style="background: #f6f7f7; padding: 15px; overflow: auto; max-height: 400px; border: 1px solid #c3c4c7; font-size: 12px;"></pre>
                </div>
            </div>

            <hr />

            <h2><?php esc_html_e( 'Debug Log', 'posts-markdown' ); ?> 
                <button type="button" class="button" id="postsmd-clear-debug-log"><?php esc_html_e( 'Clear Log', 'posts-markdown' ); ?></button>
            </h2>
            <?php if ( ! empty( $debug_log ) ) : ?>
                <table class="wp-list-table widefat fixed striped postsmd-debug-log-table">
                    <thead>
                        <tr>
                            <th style="width: 100px;"><?php esc_html_e( 'Time', 'posts-markdown' ); ?></th>
                            <th style="width: 80px;"><?php esc_html_e( 'Status', 'posts-markdown' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'posts-markdown' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $debug_log as $entry ) : 
                            $time = '';
                            $status = 'info';
                            $message = '';

                            if ( is_array( $entry ) ) {
                                $time    = isset( $entry['time'] ) ? $entry['time'] : '';
                                $status  = isset( $entry['status'] ) ? $entry['status'] : 'info';
                                $message = isset( $entry['message'] ) ? $entry['message'] : '';
                            } else {
                                if ( preg_match( '/^\[([^\]]+)\]\s+(.+)$/', $entry, $matches ) ) {
                                    $time = $matches[1];
                                    $message = $matches[2];
                                } else {
                                    $message = $entry;
                                }
                            }

                            $status_class = 'postsmd-status-' . esc_attr( $status );
                            $status_label = $this->get_status_label( $status );
                        ?>
                            <tr>
                                <td><?php echo esc_html( $time ); ?></td>
                                <td><span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
                                <td><code><?php echo esc_html( $message ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'No debug log entries yet. Export or import a post to see debug information.', 'posts-markdown' ); ?></p>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            $('#postsmd-view-content').on('click', function() {
                var postId = $('#postsmd-post-id').val();
                if (!postId) {
                    alert('<?php echo esc_js( __( 'Please enter a Post ID', 'posts-markdown' ) ); ?>');
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'postsmd_get_post_content',
                        nonce: nonce,
                        post_id: postId
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#postsmd-post-title').text(response.data.title);
                            $('#postsmd-post-id-display').text(response.data.id);
                            $('#postsmd-post-length').text(response.data.length);
                            $('#postsmd-post-content').text(response.data.content);
                            $('#postsmd-post-content-result').show();
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js( __( 'Error fetching post content', 'posts-markdown' ) ); ?>');
                    }
                });
            });

            $('#postsmd-clear-debug-log').on('click', function() {
                if (!confirm('<?php echo esc_js( __( 'Are you sure you want to clear the debug log?', 'posts-markdown' ) ); ?>')) {
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'postsmd_clear_debug_log',
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
            });
        });
        </script>

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
        $this->log_success( 'Export completed successfully.' );
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

        $status = ( $stats['created'] > 0 || $stats['updated'] > 0 ) ? 'success' : 'warning';
        $this->log_debug(
            sprintf(
                'Import completed: processed=%d, updated=%d, created=%d, skipped=%d.',
                $stats['processed'],
                $stats['updated'],
                $stats['created'],
                $stats['skipped']
            ),
            $status
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

        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>' . esc_html__( 'Posts Markdown Debug Log', 'posts-markdown' ) . '</strong></p>';
        echo '<table class="wp-list-table widefat fixed striped postsmd-debug-table" style="margin-top: 10px;">';
        echo '<thead><tr>';
        echo '<th scope="col" class="manage-column" style="width: 100px;">' . esc_html__( 'Time', 'posts-markdown' ) . '</th>';
        echo '<th scope="col" class="manage-column" style="width: 100px;">' . esc_html__( 'Status', 'posts-markdown' ) . '</th>';
        echo '<th scope="col" class="manage-column">' . esc_html__( 'Message', 'posts-markdown' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $messages as $entry ) {
            $time = '';
            $status = 'info';
            $message = '';

            if ( is_array( $entry ) ) {
                $time    = isset( $entry['time'] ) ? $entry['time'] : '';
                $status  = isset( $entry['status'] ) ? $entry['status'] : 'info';
                $message = isset( $entry['message'] ) ? $entry['message'] : '';
            } else {
                if ( preg_match( '/^\[([^\]]+)\]\s+(.+)$/', $entry, $matches ) ) {
                    $time = $matches[1];
                    $message = $matches[2];
                } else {
                    $message = $entry;
                }
            }

            $status_class = 'postsmd-status-' . esc_attr( $status );
            $status_label = $this->get_status_label( $status );

            echo '<tr>';
            echo '<td>' . esc_html( $time ) . '</td>';
            echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span></td>';
            echo '<td>' . esc_html( $message ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<style>
            .postsmd-debug-table { border-collapse: collapse; }
            .postsmd-debug-table th { background: #f6f7f7; }
            .postsmd-debug-table td { padding: 8px 10px; }
            .postsmd-status-success { color: #00a32a; font-weight: 600; }
            .postsmd-status-error { color: #d63638; font-weight: 600; }
            .postsmd-status-warning { color: #dba617; font-weight: 600; }
            .postsmd-status-info { color: #2271b1; font-weight: 600; }
        </style>';
        echo '</div>';
    }

    private function get_status_label( $status ) {
        $labels = array(
            'success' => __( 'Success', 'posts-markdown' ),
            'error'   => __( 'Error', 'posts-markdown' ),
            'warning' => __( 'Warning', 'posts-markdown' ),
            'info'    => __( 'Info', 'posts-markdown' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
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

    public function log_debug( $message, $status = 'info' ) {
        $message = wp_strip_all_tags( (string) $message );
        if ( '' === $message ) {
            return;
        }

        $valid_statuses = array( 'success', 'error', 'warning', 'info' );
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            $status = 'info';
        }

        $this->debug_log[] = array(
            'time'    => gmdate( 'H:i:s' ) . ' UTC',
            'status'  => $status,
            'message' => $message,
        );
    }

    public function log_success( $message ) {
        $this->log_debug( $message, 'success' );
    }

    public function log_error( $message ) {
        $this->log_debug( $message, 'error' );
    }

    public function log_warning( $message ) {
        $this->log_debug( $message, 'warning' );
    }

    private function persist_debug_log() {
        if ( empty( $this->debug_log ) ) {
            return;
        }

        set_transient( $this->debug_transient_key, $this->debug_log, 5 * MINUTE_IN_SECONDS );

        $existing_log = get_option( $this->debug_log_option_key, array() );
        if ( ! is_array( $existing_log ) ) {
            $existing_log = array();
        }
        $merged = array_merge( $this->debug_log, $existing_log );
        $merged = array_slice( $merged, 0, 100 );
        update_option( $this->debug_log_option_key, $merged );
    }

    public function get_debug_log() {
        $log = get_option( $this->debug_log_option_key, array() );
        return is_array( $log ) ? $log : array();
    }

    public function ajax_get_post_content() {
        check_ajax_referer( 'postsmd_debug', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'Invalid post ID' ) );
        }

        $post = get_post( $post_id );

        if ( ! $post ) {
            wp_send_json_error( array( 'message' => 'Post not found' ) );
        }

        $content = $post->post_content;

        wp_send_json_success( array(
            'id'      => $post_id,
            'title'   => $post->post_title,
            'content' => $content,
            'length'  => strlen( $content ),
        ) );
    }

    public function ajax_clear_debug_log() {
        check_ajax_referer( 'postsmd_debug', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        delete_option( $this->debug_log_option_key );
        delete_transient( $this->debug_transient_key );
        $this->debug_log = array();

        wp_send_json_success( array( 'message' => 'Debug log cleared' ) );
    }

    public function fail_and_die( $message ) {
        $this->log_error( 'Failure: ' . wp_strip_all_tags( $message ) );
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
        $this->log_success( 'Dashboard export completed.' );
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
        $this->log_success( 'Export + GitHub sync completed.' );
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
        $this->log_success( 'Export + Drive sync completed.' );
        $this->persist_debug_log();
        delete_transient( $this->stats_cache_key );
        $this->log_activity( 'drive', 'Export + Drive sync' );

        exit;
    }
}
