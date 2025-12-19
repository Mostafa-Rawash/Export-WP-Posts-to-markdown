<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPEM_Importer {

    private $markdown;
    private $media;
    private $log;
    private $fail;
    private $sync;

    public function __construct( $markdown, $media, $logger, $failer, $sync = null ) {
        $this->markdown = $markdown;
        $this->media    = $media;
        $this->log      = $logger;
        $this->fail     = $failer;
        $this->sync     = $sync;
    }

    public function import_file( $tmp_path, $name, $sync_overrides = array() ) {
        $extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

        $this->log_debug( 'Uploaded file detected: ' . $name . ' (.' . $extension . ').' );

        $stats = array(
            'processed' => 0,
            'updated'   => 0,
            'created'   => 0,
            'skipped'   => 0,
        );

        if ( 'zip' === $extension ) {
            if ( ! class_exists( 'ZipArchive' ) ) {
                $this->log_debug( 'ZipArchive extension is missing for import.' );
                $this->fail( esc_html__( 'The ZipArchive PHP extension is required to import from ZIP.', 'export-posts-to-markdown' ) );
            }

            $zip = new ZipArchive();

            if ( true !== $zip->open( $tmp_path ) ) {
                $this->log_debug( 'ZipArchive::open failed for uploaded file.' );
                $this->fail( esc_html__( 'Could not open the uploaded ZIP file.', 'export-posts-to-markdown' ) );
            }

            $media_map = $this->media->prepare_zip_media_map( $zip );
            $folders   = array();
            $indexed   = array();

            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $entry_name = $zip->getNameIndex( $i );

                if ( ! $entry_name || 'md' !== strtolower( pathinfo( $entry_name, PATHINFO_EXTENSION ) ) ) {
                    continue;
                }

                $dir_name = trim( str_replace( '\\', '/', dirname( $entry_name ) ), '/' );
                if ( '' !== $dir_name && '.' !== $dir_name ) {
                    $folders[ $dir_name ] = true;
                }
                if ( preg_match( '#/index\\.md$#i', $entry_name ) ) {
                    $indexed[ $dir_name ] = true;
                }

                $markdown = $zip->getFromIndex( $i );

                if ( false === $markdown ) {
                    $this->log_debug( 'Failed to read ' . $entry_name . ' from ZIP.' );
                    $stats['skipped']++;
                    continue;
                }

                $result = $this->import_markdown_post( $markdown, $entry_name, $media_map );
                $stats['processed']++;
                $stats[ $result ]++;
            }

            $this->maybe_create_folder_posts( $folders, $indexed );

            $zip->close();
        } elseif ( 'md' === $extension ) {
            $markdown = file_get_contents( $tmp_path );

            if ( false === $markdown ) {
                $this->log_debug( 'Failed to read uploaded Markdown file.' );
                $this->fail( esc_html__( 'Could not read the uploaded Markdown file.', 'export-posts-to-markdown' ) );
            }

            $result              = $this->import_markdown_post( $markdown, $name, array() );
            $stats['processed']  = 1;
            $stats[ $result ]   += 1;
        } else {
            $this->log_debug( 'Unsupported file extension: ' . $extension );
            $this->fail( esc_html__( 'Only ZIP archives or .md files are supported for import.', 'export-posts-to-markdown' ) );
        }

        if ( $this->sync ) {
            $this->sync->push_import( $tmp_path, $name, $stats, $sync_overrides );
        }

        return $stats;
    }

    private function maybe_create_folder_posts( $folders, $indexed ) {
        if ( empty( $folders ) ) {
            return;
        }

        foreach ( $folders as $folder => $true ) {
            if ( isset( $indexed[ $folder ] ) ) {
                continue; // index.md already handled.
            }

            $slug  = sanitize_title( basename( $folder ) );
            $title = ucwords( str_replace( array( '-', '_' ), ' ', basename( $folder ) ) );

            if ( '' === $slug ) {
                continue;
            }

            $existing = get_page_by_path( $slug, OBJECT, 'post' );

            if ( $existing ) {
                $this->log_debug( 'Folder post exists for ' . $folder . ' (slug ' . $slug . '). Skipping creation.' );
                continue;
            }

            $postarr = array(
                'post_title'   => $title,
                'post_status'  => 'draft',
                'post_content' => '',
                'post_name'    => $slug,
                'post_type'    => 'post',
            );

            $inserted_id = wp_insert_post( $postarr, true );

            if ( is_wp_error( $inserted_id ) ) {
                $this->log_debug( 'Failed to create folder post for ' . $folder . ': ' . $inserted_id->get_error_message() );
            } else {
                $this->log_debug( 'Created folder post for ' . $folder . ' as ID ' . $inserted_id . '.' );
            }
        }
    }

    private function import_markdown_post( $markdown, $filename, $media_map ) {
        $parsed  = $this->markdown->parse_front_matter( $markdown );
        $meta    = $this->validate_front_matter_design( $parsed['meta'], $filename );
        $content = $parsed['content'];

        if ( ! empty( $meta['skip_file'] ) && 'yes' === strtolower( (string) $meta['skip_file'] ) ) {
            $this->log_debug( 'Skipping import for ' . $filename . ' due to skip_file flag.' );
            return 'skipped';
        }

        $original_id = $this->extract_post_id_from_meta( $meta );
        $post_id     = $original_id ? absint( $original_id ) : 0;

        $title  = ! empty( $meta['title'] ) ? wp_strip_all_tags( $meta['title'] ) : __( 'Imported Markdown', 'export-posts-to-markdown' );
        $status = ! empty( $meta['post_status'] ) ? sanitize_key( $meta['post_status'] ) : ( ! empty( $meta['status'] ) ? sanitize_key( $meta['status'] ) : 'draft' );
        $slug   = ! empty( $meta['slug'] ) ? sanitize_title( $meta['slug'] ) : '';
        $date   = ! empty( $meta['post_date'] ) ? $meta['post_date'] : ( ! empty( $meta['date'] ) ? $meta['date'] : '' );

        $author_id = $this->resolve_author_from_meta( isset( $meta['author'] ) ? $meta['author'] : '' );
        $excerpt   = isset( $meta['post_excerpt'] ) ? wp_strip_all_tags( $meta['post_excerpt'] ) : ( isset( $meta['excerpt'] ) ? wp_strip_all_tags( $meta['excerpt'] ) : '' );

        $html_content = $this->markdown->markdown_to_html( $content, $media_map );
        $html_content = wp_kses_post( $html_content );

        $postarr = array(
            'post_title'   => $title,
            'post_status'  => $status,
            'post_content' => $html_content,
            'post_type'    => 'post',
        );

        if ( ! empty( $meta['menu_order'] ) ) {
            $postarr['menu_order'] = (int) $meta['menu_order'];
        }

        if ( $excerpt ) {
            $postarr['post_excerpt'] = $excerpt;
        }

        if ( $slug ) {
            $postarr['post_name'] = $slug;
        }

        if ( $date && false !== strtotime( $date ) ) {
            $postarr['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $date ) ) );
            $postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $date ) );
        }

        if ( $author_id ) {
            $postarr['post_author'] = $author_id;
        }

        if ( ! empty( $meta['comment_status'] ) && in_array( $meta['comment_status'], array( 'open', 'closed' ), true ) ) {
            $postarr['comment_status'] = $meta['comment_status'];
        }

        $result_status = 'skipped';

        if ( $post_id && get_post( $post_id ) ) {
            $postarr['ID'] = $post_id;
            $updated_id    = wp_update_post( $postarr, true );

            if ( ! is_wp_error( $updated_id ) ) {
                $this->log_debug( 'Updated post ID ' . $post_id . ' from ' . $filename . '.' );
                $result_status = 'updated';
                $this->assign_terms_from_meta( $post_id, $meta );
                $this->apply_custom_fields( $post_id, $meta );
                $this->apply_folder_path_meta( $post_id, $meta );
                $this->apply_rank_math_meta( $post_id, $meta );
                $this->media->set_featured_image( $post_id, isset( $meta['featured_image'] ) ? $meta['featured_image'] : '', $media_map );
                $this->maybe_apply_page_template( $post_id, $meta );
                $this->maybe_apply_sticky( $post_id, $meta );
            } else {
                $this->log_debug( 'Failed to update post ID ' . $post_id . ': ' . $updated_id->get_error_message() );
            }
        } else {
            $inserted_id = wp_insert_post( $postarr, true );

            if ( ! is_wp_error( $inserted_id ) ) {
                $this->log_debug( 'Created new post ID ' . $inserted_id . ' from ' . $filename . '.' );
                $result_status = 'created';
                if ( $original_id && ! get_post_meta( $inserted_id, '_wpexportmd_original_id', true ) ) {
                    $this->log_debug( 'Original ID ' . $original_id . ' stored in post meta because matching post was not found.' );
                    update_post_meta( $inserted_id, '_wpexportmd_original_id', $original_id );
                }
                $this->assign_terms_from_meta( $inserted_id, $meta );
                $this->apply_custom_fields( $inserted_id, $meta );
                $this->apply_folder_path_meta( $inserted_id, $meta );
                $this->apply_rank_math_meta( $inserted_id, $meta );
                $this->media->set_featured_image( $inserted_id, isset( $meta['featured_image'] ) ? $meta['featured_image'] : '', $media_map );
                $this->maybe_apply_page_template( $inserted_id, $meta );
                $this->maybe_apply_sticky( $inserted_id, $meta );
            } else {
                $this->log_debug( 'Failed to create post from ' . $filename . ': ' . $inserted_id->get_error_message() );
            }
        }

        return $result_status;
    }

    private function assign_terms_from_meta( $post_id, $meta ) {
        if ( ! empty( $meta['categories'] ) && is_array( $meta['categories'] ) ) {
            $category_ids = array();

            foreach ( $meta['categories'] as $category_name ) {
                $category_name = wp_strip_all_tags( $category_name );

                if ( '' === $category_name ) {
                    continue;
                }

                $term = term_exists( $category_name, 'category' );

                if ( ! $term ) {
                    $term = wp_insert_term( $category_name, 'category' );
                }

                if ( ! is_wp_error( $term ) && ! empty( $term['term_id'] ) ) {
                    $category_ids[] = (int) $term['term_id'];
                }
            }

            if ( ! empty( $category_ids ) ) {
                wp_set_post_terms( $post_id, $category_ids, 'category', false );
            }
        }

        if ( ! empty( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
            $tags = array();

            foreach ( $meta['tags'] as $tag_name ) {
                $tag_name = wp_strip_all_tags( $tag_name );

                if ( '' === $tag_name ) {
                    continue;
                }

                $tags[] = $tag_name;
            }

            if ( ! empty( $tags ) ) {
                wp_set_post_terms( $post_id, $tags, 'post_tag', false );
            }
        }

        if ( ! empty( $meta['taxonomy'] ) && is_array( $meta['taxonomy'] ) ) {
            foreach ( $meta['taxonomy'] as $assignment ) {
                $assignment = wp_strip_all_tags( $assignment );

                if ( '' === $assignment || false === strpos( $assignment, ':' ) ) {
                    continue;
                }

                list( $tax, $term_value ) = array_map( 'trim', explode( ':', $assignment, 2 ) );

                if ( '' === $tax || '' === $term_value ) {
                    continue;
                }

                $term = term_exists( $term_value, $tax );

                if ( ! $term ) {
                    $term = wp_insert_term( $term_value, $tax );
                }

                if ( is_wp_error( $term ) ) {
                    $this->log_debug( 'Taxonomy assignment failed for ' . $tax . ': ' . $term->get_error_message() );
                    continue;
                }

                if ( ! empty( $term['term_id'] ) ) {
                    wp_set_post_terms( $post_id, array( (int) $term['term_id'] ), $tax, true );
                }
            }
        }
    }

    private function apply_custom_fields( $post_id, $meta ) {
        if ( empty( $meta['custom_fields'] ) || ! is_array( $meta['custom_fields'] ) ) {
            return;
        }

        foreach ( $meta['custom_fields'] as $field_entry ) {
            if ( false === strpos( $field_entry, ':' ) ) {
                continue;
            }

            list( $key, $value ) = array_map( 'trim', explode( ':', $field_entry, 2 ) );

            if ( '' === $key ) {
                continue;
            }

            update_post_meta( $post_id, $key, $value );
        }
    }

    private function apply_folder_path_meta( $post_id, $meta ) {
        if ( empty( $meta['folder_path'] ) ) {
            return;
        }

        update_post_meta( $post_id, '_wpexportmd_folder_path', $meta['folder_path'] );
    }

    private function apply_rank_math_meta( $post_id, $meta ) {
        $description = '';
        $keywords    = '';

        if ( ! empty( $meta['meta_description'] ) ) {
            $description = $meta['meta_description'];
        } elseif ( ! empty( $meta['metadata'] ) ) {
            $description = $meta['metadata'];
        }

        if ( ! empty( $meta['meta_keywords'] ) ) {
            $keywords = $meta['meta_keywords'];
        } elseif ( ! empty( $meta['keyword'] ) ) {
            $keywords = $meta['keyword'];
        } elseif ( ! empty( $meta['keywords'] ) ) {
            $keywords = $meta['keywords'];
        }

        if ( is_array( $keywords ) ) {
            $clean_keywords = array();
            foreach ( $keywords as $keyword ) {
                $keyword = wp_strip_all_tags( (string) $keyword );
                if ( '' !== $keyword ) {
                    $clean_keywords[] = $keyword;
                }
            }
            $keywords = implode( ', ', $clean_keywords );
        }

        if ( '' !== $description ) {
            update_post_meta( $post_id, 'rank_math_description', $description );
        }

        if ( '' !== $keywords ) {
            update_post_meta( $post_id, 'rank_math_focus_keyword', $keywords );
            update_post_meta( $post_id, 'rank_math_focus_keywords', $keywords );
        }
    }

    private function maybe_apply_page_template( $post_id, $meta ) {
        if ( empty( $meta['page_template'] ) ) {
            return;
        }

        update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $meta['page_template'] ) );
    }

    private function maybe_apply_sticky( $post_id, $meta ) {
        if ( empty( $meta['stick_post'] ) ) {
            return;
        }

        $value = strtolower( (string) $meta['stick_post'] );

        if ( 'yes' === $value ) {
            stick_post( $post_id );
        } elseif ( 'no' === $value ) {
            unstick_post( $post_id );
        }
    }

    private function extract_post_id_from_meta( $meta ) {
        if ( ! empty( $meta['id'] ) && is_numeric( $meta['id'] ) ) {
            return absint( $meta['id'] );
        }

        return 0;
    }

    private function resolve_author_from_meta( $author_value ) {
        $author_value = wp_strip_all_tags( (string) $author_value );

        if ( '' === $author_value ) {
            return get_current_user_id();
        }

        $user = get_user_by( 'login', $author_value );

        if ( $user ) {
            return (int) $user->ID;
        }

        $user = get_user_by( 'slug', sanitize_title( $author_value ) );

        if ( $user ) {
            return (int) $user->ID;
        }

        $found = get_users(
            array(
                'search'         => $author_value,
                'search_columns' => array( 'display_name' ),
                'number'         => 1,
                'fields'         => 'ID',
            )
        );

        if ( ! empty( $found ) ) {
            return (int) $found[0];
        }

        return get_current_user_id();
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

    private function validate_front_matter_design( $meta, $filename ) {
        $meta                = is_array( $meta ) ? $meta : array();
        $validated           = array();
        $allowed_statuses    = array( 'publish', 'draft', 'pending', 'future' );
        $allowed_comment     = array( 'open', 'closed' );
        $allowed_sticky_flag = array( 'yes', 'no' );

        if ( isset( $meta['status'] ) && ! isset( $meta['post_status'] ) ) {
            $meta['post_status'] = $meta['status'];
        }

        if ( isset( $meta['date'] ) && ! isset( $meta['post_date'] ) ) {
            $meta['post_date'] = $meta['date'];
        }

        if ( ! empty( $meta['title'] ) ) {
            $validated['title'] = wp_strip_all_tags( $meta['title'] );
        }

        if ( ! empty( $meta['slug'] ) ) {
            $validated['slug'] = sanitize_title( $meta['slug'] );
        }

        if ( ! empty( $meta['post_status'] ) ) {
            $status = sanitize_key( $meta['post_status'] );
            if ( in_array( $status, $allowed_statuses, true ) ) {
                $validated['post_status'] = $status;
            } else {
                $this->log_debug( 'Invalid post_status in front matter for ' . $filename . ': ' . $meta['post_status'] );
            }
        }

        if ( ! empty( $meta['post_date'] ) ) {
            $date = $meta['post_date'];
            if ( false !== strtotime( $date ) ) {
                $validated['post_date'] = $date;
            } else {
                $this->log_debug( 'Invalid post_date in front matter for ' . $filename . ': ' . $date );
            }
        }

        if ( isset( $meta['menu_order'] ) ) {
            $validated['menu_order'] = (int) $meta['menu_order'];
        }

        if ( ! empty( $meta['author'] ) ) {
            $validated['author'] = wp_strip_all_tags( $meta['author'] );
        }

        if ( ! empty( $meta['post_excerpt'] ) ) {
            $validated['post_excerpt'] = wp_strip_all_tags( $meta['post_excerpt'] );
        } elseif ( ! empty( $meta['excerpt'] ) ) {
            $validated['post_excerpt'] = wp_strip_all_tags( $meta['excerpt'] );
        }

        if ( ! empty( $meta['comment_status'] ) ) {
            $comment_status = sanitize_key( $meta['comment_status'] );
            if ( in_array( $comment_status, $allowed_comment, true ) ) {
                $validated['comment_status'] = $comment_status;
            } else {
                $this->log_debug( 'Invalid comment_status in front matter for ' . $filename . ': ' . $meta['comment_status'] );
            }
        }

        if ( ! empty( $meta['page_template'] ) ) {
            $validated['page_template'] = sanitize_text_field( $meta['page_template'] );
        }

        if ( ! empty( $meta['stick_post'] ) ) {
            $stick = strtolower( (string) $meta['stick_post'] );
            if ( in_array( $stick, $allowed_sticky_flag, true ) ) {
                $validated['stick_post'] = $stick;
            } else {
                $this->log_debug( 'Invalid stick_post in front matter for ' . $filename . ': ' . $meta['stick_post'] );
            }
        }

        foreach ( array( 'categories', 'tags' ) as $list_key ) {
            if ( isset( $meta[ $list_key ] ) ) {
                $values = is_array( $meta[ $list_key ] ) ? $meta[ $list_key ] : array( $meta[ $list_key ] );
                $clean  = array();

                foreach ( $values as $value ) {
                    $value = wp_strip_all_tags( (string) $value );
                    if ( '' !== $value ) {
                        $clean[] = $value;
                    }
                }

                if ( ! empty( $clean ) ) {
                    $validated[ $list_key ] = array_values( array_unique( $clean ) );
                }
            }
        }

        if ( isset( $meta['taxonomy'] ) ) {
            $tax_assignments = is_array( $meta['taxonomy'] ) ? $meta['taxonomy'] : array( $meta['taxonomy'] );
            $clean           = array();

            foreach ( $tax_assignments as $assignment ) {
                $assignment = wp_strip_all_tags( (string) $assignment );
                if ( '' === $assignment ) {
                    continue;
                }

                if ( false === strpos( $assignment, ':' ) ) {
                    $this->log_debug( 'Invalid taxonomy format in front matter for ' . $filename . ': ' . $assignment );
                    continue;
                }

                $clean[] = $assignment;
            }

            if ( ! empty( $clean ) ) {
                $validated['taxonomy'] = $clean;
            }
        }

        if ( isset( $meta['custom_fields'] ) ) {
            $fields = is_array( $meta['custom_fields'] ) ? $meta['custom_fields'] : array( $meta['custom_fields'] );
            $clean  = array();

            foreach ( $fields as $field_entry ) {
                $field_entry = trim( (string) $field_entry );
                if ( '' === $field_entry ) {
                    continue;
                }

                if ( false === strpos( $field_entry, ':' ) ) {
                    $this->log_debug( 'Invalid custom_fields format in front matter for ' . $filename . ': ' . $field_entry );
                    continue;
                }

                $clean[] = $field_entry;
            }

            if ( ! empty( $clean ) ) {
                $validated['custom_fields'] = $clean;
            }
        }

        if ( ! empty( $meta['featured_image'] ) ) {
            $validated['featured_image'] = trim( (string) $meta['featured_image'] );
        }

        if ( ! empty( $meta['folder_path'] ) ) {
            $validated['folder_path'] = trim( (string) $meta['folder_path'] );
        }

        if ( empty( $meta['meta_description'] ) && ! empty( $meta['metadata'] ) ) {
            $meta['meta_description'] = $meta['metadata'];
        }

        if ( empty( $meta['meta_keywords'] ) ) {
            if ( ! empty( $meta['keyword'] ) ) {
                $meta['meta_keywords'] = $meta['keyword'];
            } elseif ( ! empty( $meta['keywords'] ) ) {
                $meta['meta_keywords'] = $meta['keywords'];
            }
        }

        if ( ! empty( $meta['meta_description'] ) ) {
            $validated['meta_description'] = wp_strip_all_tags( $meta['meta_description'] );
        }

        if ( ! empty( $meta['meta_keywords'] ) ) {
            if ( is_array( $meta['meta_keywords'] ) ) {
                $clean_keywords = array();
                foreach ( $meta['meta_keywords'] as $keyword ) {
                    $keyword = wp_strip_all_tags( (string) $keyword );
                    if ( '' !== $keyword ) {
                        $clean_keywords[] = $keyword;
                    }
                }
                $validated['meta_keywords'] = implode( ', ', $clean_keywords );
            } else {
                $validated['meta_keywords'] = wp_strip_all_tags( $meta['meta_keywords'] );
            }
        }

        if ( isset( $meta['skip_file'] ) ) {
            $validated['skip_file'] = $meta['skip_file'];
        }

        if ( ! empty( $meta['id'] ) && is_numeric( $meta['id'] ) ) {
            $validated['id'] = absint( $meta['id'] );
        }

        return $validated;
    }
}
