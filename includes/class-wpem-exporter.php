<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPEM_Exporter {

    private $markdown;
    private $log;
    private $fail;
    private $stream;

    public function __construct( $markdown, $logger, $failer, $streamer ) {
        $this->markdown = $markdown;
        $this->log      = $logger;
        $this->fail     = $failer;
        $this->stream   = $streamer;
    }

    public function export_all() {
        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->log_debug( 'ZipArchive extension is missing.' );
            $this->fail( esc_html__( 'The ZipArchive PHP extension is required to build the export archive.', 'export-posts-to-markdown' ) );
        }

        $posts = get_posts(
            array(
                'posts_per_page' => -1,
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        if ( empty( $posts ) ) {
            $this->log_debug( 'No published posts returned by query.' );
            $this->fail( esc_html__( 'No published posts found to export.', 'export-posts-to-markdown' ) );
        }

        $post_count = count( $posts );
        $this->log_debug( sprintf( 'Found %d published posts to export.', $post_count ) );

        $tmp_file = wp_tempnam( 'wpmd_' );
        if ( ! $tmp_file ) {
            $this->log_debug( 'Failed to create temporary file for archive.' );
            $this->fail( esc_html__( 'Could not create a temporary file for the export.', 'export-posts-to-markdown' ) );
        }

        $this->log_debug( 'Temporary file created at ' . $tmp_file . '.' );

        $zip   = new ZipArchive();
        $flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;

        if ( true !== $zip->open( $tmp_file, $flags ) ) {
            $this->log_debug( 'ZipArchive::open failed for temporary file.' );
            $this->fail( esc_html__( 'Could not create ZIP file.', 'export-posts-to-markdown' ) );
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

        call_user_func( $this->stream, $tmp_file, $download_name );

        $this->log_debug( 'Export completed successfully.' );

        @unlink( $tmp_file );
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
        $content = $this->markdown->html_to_markdown( $content );

        $md_lines   = array();
        $md_lines[] = '---';
        $md_lines[] = 'title: "' . $this->markdown->escape_yaml( $title ) . '"';
        $md_lines[] = 'date: ' . $date;
        $md_lines[] = 'status: "' . $this->markdown->escape_yaml( $status ) . '"';
        $md_lines[] = 'slug: "' . $this->markdown->escape_yaml( $slug ) . '"';
        $md_lines[] = 'permalink: ' . $permalink;
        $md_lines[] = 'id: ' . absint( $post->ID );

        if ( $author ) {
            $md_lines[] = 'author: "' . $this->markdown->escape_yaml( $author ) . '"';
        }

        if ( ! empty( $category_names ) ) {
            $md_lines[] = 'categories: ' . $this->markdown->format_yaml_list( $category_names );
        }

        if ( ! empty( $tag_names ) ) {
            $md_lines[] = 'tags: ' . $this->markdown->format_yaml_list( $tag_names );
        }

        if ( $excerpt ) {
            $excerpt_text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $excerpt ) );
            $md_lines[]   = 'excerpt: "' . $this->markdown->escape_yaml( trim( $excerpt_text ) ) . '"';
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

    private function log_debug( $message ) {
        if ( is_callable( $this->log ) ) {
            call_user_func( $this->log, $message );
        }
    }
}
