<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPEM_Media {

    private $log;

    public function __construct( $logger ) {
        $this->log = $logger;
    }

    public function prepare_zip_media_map( ZipArchive $zip ) {
        $map         = array();
        $allowed_ext = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' );

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = $zip->getNameIndex( $i );

            if ( ! $entry_name ) {
                continue;
            }

            $normalized = $this->normalize_media_path( $entry_name );

            if ( '' === $normalized ) {
                continue;
            }

            $extension = strtolower( pathinfo( $normalized, PATHINFO_EXTENSION ) );

            if ( ! in_array( $extension, $allowed_ext, true ) ) {
                continue;
            }

            $existing = $this->find_existing_attachment_by_source( $normalized );

            if ( $existing ) {
                $map[ $normalized ]       = $existing;
                $map[ '/' . $normalized ] = $existing;
                continue;
            }

            $content = $zip->getFromIndex( $i );

            if ( false === $content ) {
                $this->log_debug( 'Failed to read media file ' . $entry_name . ' from ZIP.' );
                continue;
            }

            $upload = wp_upload_bits( basename( $normalized ), null, $content );

            if ( ! empty( $upload['error'] ) ) {
                $this->log_debug( 'Upload failed for ' . $entry_name . ': ' . $upload['error'] );
                continue;
            }

            $filetype = wp_check_filetype( $upload['file'] );

            $attachment_id = wp_insert_attachment(
                array(
                    'post_mime_type' => $filetype['type'],
                    'post_title'     => sanitize_file_name( basename( $normalized ) ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ),
                $upload['file']
            );

            if ( is_wp_error( $attachment_id ) ) {
                $this->log_debug( 'Attachment insert failed for ' . $entry_name . ': ' . $attachment_id->get_error_message() );
                continue;
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
            wp_update_attachment_metadata( $attachment_id, $attach_data );
            update_post_meta( $attachment_id, '_wpexportmd_source_path', $normalized );

            $attachment = array(
                'id'  => $attachment_id,
                'url' => wp_get_attachment_url( $attachment_id ),
            );

            $map[ $normalized ]       = $attachment;
            $map[ '/' . $normalized ] = $attachment;
        }

        return $map;
    }

    public function set_featured_image( $post_id, $source, $media_map = array() ) {
        $source = trim( (string) $source );

        if ( '' === $source ) {
            return;
        }

        if ( parse_url( $source, PHP_URL_SCHEME ) ) {
            $this->log_debug( 'Remote featured_image URLs are not supported: ' . $source );
            return;
        }

        $normalized = $this->normalize_media_path( $source );

        if ( '' === $normalized ) {
            $this->log_debug( 'featured_image not under _images/: ' . $source );
            return;
        }

        $attachment = $this->find_existing_attachment_by_source( $normalized );

        if ( ! $attachment && isset( $media_map[ $normalized ] ) ) {
            $attachment = $media_map[ $normalized ];
        }

        if ( $attachment && ! empty( $attachment['id'] ) ) {
            set_post_thumbnail( $post_id, (int) $attachment['id'] );
        } else {
            $map_hint = '';
            if ( ! empty( $media_map ) ) {
                $keys     = array_keys( $media_map );
                $map_hint = ' (media_map keys: ' . implode( ', ', array_slice( $keys, 0, 5 ) ) . ')';
            }
            $this->log_debug( 'Could not resolve featured_image for ' . $source . '; normalized=' . $normalized . $map_hint );
        }
    }

    public function normalize_media_path( $path ) {
        $path = str_replace( '\\', '/', (string) $path );
        $path = ltrim( $path, '/' );

        $pos = strpos( $path, '_images/' );

        if ( false === $pos ) {
            return '';
        }

        return substr( $path, $pos );
    }

    public function find_existing_attachment_by_source( $normalized_path ) {
        $existing = get_posts(
            array(
                'post_type'   => 'attachment',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_key'    => '_wpexportmd_source_path',
                'meta_value'  => $normalized_path,
            )
        );

        if ( empty( $existing ) ) {
            return null;
        }

        $id = (int) $existing[0];

        return array(
            'id'  => $id,
            'url' => wp_get_attachment_url( $id ),
        );
    }

    private function log_debug( $message ) {
        if ( is_callable( $this->log ) ) {
            call_user_func( $this->log, $message );
        }
    }
}
