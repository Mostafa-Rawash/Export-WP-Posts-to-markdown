<?php
/**
 * Plugin Name: Export Posts to Markdown
 * Description: Download all published posts as .md in a ZIP.
 * Version: 1.0.0
 * Author: You
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Export_Posts_To_Markdown {

    private $debug_log = array();
    private $debug_transient_key = 'wpexportmd_last_debug';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_page' ) );
        add_action( 'admin_post_wpexportmd', array( $this, 'handle_export' ) );
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
            <p><?php esc_html_e( 'Click the button below to download all published posts as Markdown files in a single ZIP archive.', 'export-posts-to-markdown' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wpexportmd', 'wpexportmd_nonce' ); ?>
                <input type="hidden" name="action" value="wpexportmd" />
                <?php submit_button( __( 'Download Markdown ZIP', 'export-posts-to-markdown' ) ); ?>
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

        $this->log_debug( 'Capability check passed.' );

        if ( ! isset( $_POST['wpexportmd_nonce'] ) || ! wp_verify_nonce( $_POST['wpexportmd_nonce'], 'wpexportmd' ) ) {
            $this->log_debug( 'Nonce verification failed.' );
            $this->fail_and_die( esc_html__( 'Security check failed.', 'export-posts-to-markdown' ) );
        }

        $this->log_debug( 'Nonce verification passed.' );

        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->log_debug( 'ZipArchive extension is missing.' );
            $this->fail_and_die( esc_html__( 'The ZipArchive PHP extension is required to build the export archive.', 'export-posts-to-markdown' ) );
        }

        $this->log_debug( 'ZipArchive extension detected.' );

        $posts = get_posts( array(
            'posts_per_page' => -1,
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        if ( empty( $posts ) ) {
            $this->log_debug( 'No published posts returned by query.' );
            $this->fail_and_die( esc_html__( 'No published posts found to export.', 'export-posts-to-markdown' ) );
        }

        $post_count = count( $posts );
        $this->log_debug( sprintf( 'Found %d published posts to export.', $post_count ) );

        $tmp_file = wp_tempnam( 'wpmd_' );
        if ( ! $tmp_file ) {
            $this->log_debug( 'Failed to create temporary file for archive.' );
            $this->fail_and_die( esc_html__( 'Could not create a temporary file for the export.', 'export-posts-to-markdown' ) );
        }

        $this->log_debug( 'Temporary file created at ' . $tmp_file . '.' );

        $zip   = new ZipArchive();
        $flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;

        if ( true !== $zip->open( $tmp_file, $flags ) ) {
            $this->log_debug( 'ZipArchive::open failed for temporary file.' );
            $this->fail_and_die( esc_html__( 'Could not create ZIP file.', 'export-posts-to-markdown' ) );
        }

        $this->log_debug( 'ZIP archive initialised.' );
        $used_filenames = array();
        $added_count    = 0;

        foreach ( $posts as $post ) {
            $markdown = $this->post_to_markdown( $post );
            $filename = $this->generate_post_filename( $post, $used_filenames );
            $zip->addFromString( $filename, $markdown );
            $added_count++;
        }

        $zip->close();

        $this->log_debug( sprintf( 'Added %d Markdown files to the archive.', $added_count ) );

        clearstatcache( true, $tmp_file );
        $archive_size = filesize( $tmp_file );
        if ( false !== $archive_size ) {
            $this->log_debug( 'ZIP size: ' . $archive_size . ' bytes.' );
        }

        $download_name = 'wordpress-markdown-export-' . gmdate( 'Ymd-His' ) . '.zip';

        $this->log_debug( 'Preparing download: ' . $download_name . '.' );

        $this->stream_file_to_browser( $tmp_file, $download_name );

        $this->log_debug( 'Export completed successfully.' );
        $this->persist_debug_log();

        @unlink( $tmp_file );
        exit;
    }

    private function stream_file_to_browser( $path, $download_name ) {
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

    private function log_debug( $message ) {
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

    private function fail_and_die( $message ) {
        $this->log_debug( 'Failure: ' . wp_strip_all_tags( $message ) );
        $this->persist_debug_log();
        wp_die( $message );
    }

    private function post_to_markdown( $post ) {
        $title      = get_the_title( $post );
        $date       = get_the_date( 'Y-m-d', $post );
        $permalink  = esc_url_raw( get_permalink( $post ) );
        $status     = get_post_status( $post );
        $slug       = $post->post_name ? $post->post_name : 'post-' . $post->ID;
        $author     = get_the_author_meta( 'display_name', $post->post_author );
        $excerpt    = get_post_field( 'post_excerpt', $post );
        $thumbnail  = get_the_post_thumbnail_url( $post, 'full' );

        $categories = get_the_category( $post->ID );
        $tags       = get_the_tags( $post->ID );

        $category_names = $categories ? wp_list_pluck( $categories, 'name' ) : array();
        $tag_names      = $tags ? wp_list_pluck( $tags, 'name' ) : array();

        $content = $post->post_content;
        $content = wpautop( $content );
        $content = $this->basic_html_to_md( $content );

        $md_lines   = array();
        $md_lines[] = '---';
        $md_lines[] = 'title: "' . $this->escape_yaml( $title ) . '"';
        $md_lines[] = 'date: ' . $date;
        $md_lines[] = 'status: "' . $this->escape_yaml( $status ) . '"';
        $md_lines[] = 'slug: "' . $this->escape_yaml( $slug ) . '"';
        $md_lines[] = 'permalink: ' . $permalink;

        if ( $author ) {
            $md_lines[] = 'author: "' . $this->escape_yaml( $author ) . '"';
        }

        if ( ! empty( $category_names ) ) {
            $md_lines[] = 'categories: ' . $this->format_yaml_list( $category_names );
        }

        if ( ! empty( $tag_names ) ) {
            $md_lines[] = 'tags: ' . $this->format_yaml_list( $tag_names );
        }

        if ( $excerpt ) {
            $excerpt_text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $excerpt ) );
            $md_lines[]   = 'excerpt: "' . $this->escape_yaml( trim( $excerpt_text ) ) . '"';
        }

        if ( $thumbnail ) {
            $md_lines[] = 'featured_image: ' . esc_url_raw( $thumbnail );
        }

        $md_lines[] = '---';
        $md_lines[] = '';
        $md_lines[] = '# ' . $title;
        $md_lines[] = '';
        $md_lines[] = $content;

        return implode( "\n", $md_lines ) . "\n";
    }

    private function basic_html_to_md( $html ) {
        $html = preg_replace_callback(
            '/<pre[^>]*><code[^>]*>(.*?)<\\/code><\\/pre>/is',
            function ( $matches ) {
                $code = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
                $code = trim( $code, "\r\n" );

                return "\n```\n" . $code . "\n```\n\n";
            },
            $html
        );

        $html = preg_replace_callback(
            '/<code[^>]*>(.*?)<\\/code>/is',
            function ( $matches ) {
                $code = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );

                return '`' . trim( $code ) . '`';
            },
            $html
        );

        $html = preg_replace( '/<h1[^>]*>(.*?)<\\/h1>/is', "# $1\n\n", $html );
        $html = preg_replace( '/<h2[^>]*>(.*?)<\\/h2>/is', "## $1\n\n", $html );
        $html = preg_replace( '/<h3[^>]*>(.*?)<\\/h3>/is', "### $1\n\n", $html );
        $html = preg_replace( '/<h4[^>]*>(.*?)<\\/h4>/is', "#### $1\n\n", $html );

        $html = preg_replace( '/<blockquote[^>]*>(.*?)<\\/blockquote>/is', "> $1\n\n", $html );

        $html = preg_replace( '/<(ul|ol)[^>]*>/', "\n", $html );
        $html = preg_replace( '/<\\/(ul|ol)>/', "\n", $html );
        $html = preg_replace_callback(
            '/<li[^>]*>(.*?)<\\/li>/is',
            function ( $matches ) {
                $item = trim( wp_strip_all_tags( $matches[1] ) );

                return '- ' . $item . "\n";
            },
            $html
        );

        $html = preg_replace( '/<(strong|b)>(.*?)<\\/\\1>/is', "**$2**", $html );
        $html = preg_replace( '/<(em|i)>(.*?)<\\/\\1>/is', "*$2*", $html );

        $html = preg_replace_callback(
            '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\\/a>/is',
            function ( $matches ) {
                return '[' . trim( $matches[2] ) . '](' . esc_url_raw( $matches[1] ) . ')';
            },
            $html
        );

        $html = preg_replace_callback(
            '/<img[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/is',
            function ( $matches ) {
                return '![' . trim( $matches[2] ) . '](' . esc_url_raw( $matches[1] ) . ')';
            },
            $html
        );

        $html = preg_replace( '/<hr\s*\/?\>/i', "\n---\n", $html );
        $html = preg_replace( '/<p[^>]*>(.*?)<\\/p>/is', "$1\n\n", $html );
        $html = preg_replace( '/<br\s*\/?\>/i', "  \n", $html );

        $text = wp_strip_all_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/(\S)(\n-\s)/', "$1\n\n- ", $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        return trim( $text );
    }

    private function format_yaml_list( array $items ) {
        $items = array_filter( array_map( 'wp_strip_all_tags', $items ) );
        $items = array_unique( array_map( 'trim', $items ) );

        if ( empty( $items ) ) {
            return '[]';
        }

        $escaped = array();

        foreach ( $items as $item ) {
            $escaped[] = '"' . $this->escape_yaml( $item ) . '"';
        }

        return '[' . implode( ', ', $escaped ) . ']';
    }

    private function generate_post_filename( $post, &$used_filenames ) {
        $slug = $post->post_name ? $post->post_name : 'post-' . $post->ID;
        $slug = sanitize_title( $slug );

        if ( '' === $slug ) {
            $slug = 'post-' . $post->ID;
        }

        $base_name = $slug;
        $filename  = $post->ID . '.md';
        $duplicate = 1;

        while ( isset( $used_filenames[ $filename ] ) ) {
            $duplicate++;
            $filename = sprintf( '%s-%d.md', $base_name, $duplicate );
        }

        $used_filenames[ $filename ] = true;

        return $filename;
    }

    private function escape_yaml( $str ) {
        $str = (string) $str;

        return strtr(
            $str,
            array(
                "\\"  => "\\\\",
                '"'   => '\\"',
                "\r"  => ' ',
                "\n"  => '\\n',
            )
        );
    }
}

new WP_Export_Posts_To_Markdown();

