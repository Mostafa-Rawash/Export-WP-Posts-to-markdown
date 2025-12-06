<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPEM_Exporter {

    private $markdown;
    private $log;
    private $fail;
    private $stream;
    private $sync;

    public function __construct( $markdown, $logger, $failer, $streamer, $sync = null ) {
        $this->markdown = $markdown;
        $this->log      = $logger;
        $this->fail     = $failer;
        $this->stream   = $streamer;
        $this->sync     = $sync;
    }

    public function export_all( $filters = array(), $sync_overrides = array(), $stream_download = true ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->log_debug( 'ZipArchive extension is missing.' );
            $this->fail( esc_html__( 'The ZipArchive PHP extension is required to build the export archive.', 'export-posts-to-markdown' ) );
        }

        $query_args = array(
            'posts_per_page' => -1,
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( ! empty( $filters['status'] ) ) {
            $query_args['post_status'] = sanitize_key( $filters['status'] );
        }

        if ( ! empty( $filters['author'] ) ) {
            $query_args['author'] = (int) $filters['author'];
        }

        if ( ! empty( $filters['start_date'] ) || ! empty( $filters['end_date'] ) ) {
            $date_query = array();

            if ( ! empty( $filters['start_date'] ) ) {
                $date_query['after'] = $filters['start_date'];
            }

            if ( ! empty( $filters['end_date'] ) ) {
                $date_query['before'] = $filters['end_date'];
            }

            if ( ! empty( $date_query ) ) {
                $date_query['inclusive'] = true;
                $query_args['date_query'] = array( $date_query );
            }
        }

        if ( ! empty( $filters['exclude_exported'] ) ) {
            $query_args['meta_query'] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_wpexportmd_exported',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => '_wpexportmd_exported',
                    'value'   => 'yes',
                    'compare' => '!=',
                ),
            );
        }

        $this->log_debug(
            sprintf(
                'Export query args: status=%s, author=%s, start=%s, end=%s, exclude_exported=%s.',
                isset( $query_args['post_status'] ) ? ( is_array( $query_args['post_status'] ) ? implode( ',', $query_args['post_status'] ) : $query_args['post_status'] ) : 'any',
                isset( $query_args['author'] ) ? (int) $query_args['author'] : 'any',
                ! empty( $filters['start_date'] ) ? $filters['start_date'] : 'none',
                ! empty( $filters['end_date'] ) ? $filters['end_date'] : 'none',
                ! empty( $filters['exclude_exported'] ) ? 'yes' : 'no'
            )
        );

        $posts = get_posts( $query_args );

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
        $exported_files = array();

        foreach ( $posts as $post ) {
            $markdown = $this->post_to_markdown( $post );
            $filename = $this->generate_post_filename( $post, $used_filenames );
            $zip->addFromString( $filename, $markdown );
            $added_count++;
            update_post_meta( $post->ID, '_wpexportmd_exported', 'yes' );
            update_post_meta( $post->ID, '_wpexportmd_last_exported', gmdate( 'Y-m-d H:i:s' ) );
            $exported_files[] = array(
                'name'    => $filename,
                'content' => $markdown,
            );
        }

        $zip->close();

        $this->log_debug( sprintf( 'Added %d Markdown files to the archive.', $added_count ) );

        clearstatcache( true, $tmp_file );
        $archive_size = filesize( $tmp_file );
        if ( false !== $archive_size ) {
            $this->log_debug( 'ZIP size: ' . $archive_size . ' bytes.' );
        }

        $download_name = 'wordpress-markdown-export-' . gmdate( 'Ymd-His' ) . '.zip';

        if ( $this->sync ) {
            $this->sync->push_export_files( $exported_files, $download_name, $filters, $sync_overrides );
        }

        if ( $stream_download ) {
            $this->log_debug( 'Preparing download: ' . $download_name . '.' );
            call_user_func( $this->stream, $tmp_file, $download_name );
            $this->log_debug( 'Export completed successfully.' );
        } else {
            $this->log_debug( 'Download skipped (sync-only mode) for ' . $download_name . '.' );
        }

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
            $md_lines[] = 'categories:';
            $md_lines   = array_merge( $md_lines, $this->markdown->format_yaml_block_list( $category_names ) );
        }

        if ( ! empty( $tag_names ) ) {
            $md_lines[] = 'tags:';
            $md_lines   = array_merge( $md_lines, $this->markdown->format_yaml_block_list( $tag_names ) );
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
        $md_lines[] = $content;

        return implode( "\n", $md_lines ) . "\n";
    }

    private function generate_post_filename( $post, &$used_filenames ) {
        $segments   = array();
        $ancestors  = array_reverse( get_post_ancestors( $post ) );

        foreach ( $ancestors as $ancestor_id ) {
            $ancestor = get_post( $ancestor_id );

            if ( ! $ancestor ) {
                continue;
            }

            $ancestor_slug = $ancestor->post_name ? sanitize_title( $ancestor->post_name ) : sanitize_title( $ancestor->post_title );

            if ( '' === $ancestor_slug ) {
                $ancestor_slug = 'post-' . $ancestor->ID;
            }

            $segments[] = $ancestor_slug;
        }

        $current_slug = $post->post_name ? sanitize_title( $post->post_name ) : sanitize_title( $post->post_title );

        if ( '' === $current_slug ) {
            $current_slug = 'post-' . $post->ID;
        }

        $base_name  = $current_slug;
        $path_parts = $segments;
        $path_parts[] = $base_name;

        $filename  = implode( '/', $path_parts ) . '.md';
        $duplicate = 1;

        while ( isset( $used_filenames[ $filename ] ) ) {
            $duplicate++;
            $path_parts[ count( $path_parts ) - 1 ] = sprintf( '%s-%d', $base_name, $duplicate );
            $filename = implode( '/', $path_parts ) . '.md';
        }

        $used_filenames[ $filename ] = true;

        return $filename;
    }

    private function log_debug( $message ) {
        if ( is_callable( $this->log ) ) {
            call_user_func( $this->log, $message );
        }
    }

    private function fail( $message ) {
        if ( is_callable( $this->fail ) ) {
            call_user_func( $this->fail, $message );
        }
    }
}
