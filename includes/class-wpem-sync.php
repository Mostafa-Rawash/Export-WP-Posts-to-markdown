<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPEM_Sync {

    private $log;
    private $options;

    public function __construct( $logger, $options = array() ) {
        $this->log     = $logger;
        $this->options = is_array( $options ) ? $options : array();
    }

    public function push_exports( $file_path, $download_name, $filters = array() ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            $this->log_debug( 'Sync skipped: export file missing or unreadable.' );
            return;
        }

        $this->push_to_github( $file_path, $download_name, $filters );
        $this->push_to_drive( $file_path, $download_name );
    }

    public function push_import( $file_path, $download_name, $meta = array() ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            $this->log_debug( 'Sync skipped: import file missing or unreadable.' );
            return;
        }

        $this->push_to_github( $file_path, $download_name, array_merge( array( 'context' => 'import' ), $meta ) );
        $this->push_to_drive( $file_path, $download_name );
    }

    private function push_to_github( $file_path, $download_name, $filters ) {
        if ( empty( $this->options['github_enabled'] ) ) {
            return;
        }

        if ( empty( $this->options['github_repo'] ) || empty( $this->options['github_token'] ) ) {
            return;
        }

        $repo   = trim( $this->options['github_repo'] );
        $branch = ! empty( $this->options['github_branch'] ) ? sanitize_text_field( $this->options['github_branch'] ) : 'main';
        $path   = ! empty( $this->options['github_path'] ) ? ltrim( trim( $this->options['github_path'] ), '/' ) . '/' : '';
        $url    = 'https://api.github.com/repos/' . rawurlencode( $repo ) . '/contents/' . rawurlencode( $path . $download_name );

        $content = file_get_contents( $file_path );
        if ( false === $content ) {
            $this->log_debug( 'GitHub sync skipped: failed reading export file.' );
            return;
        }

        $existing_sha = '';
        $response     = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'Authorization' => 'token ' . $this->options['github_token'],
                    'User-Agent'    => 'wp-export-markdown',
                ),
            )
        );

        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $body['sha'] ) ) {
                $existing_sha = $body['sha'];
            }
        }

        $message_parts = array(
            'Export ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
        );

        if ( ! empty( $filters ) ) {
            $message_parts[] = 'filters: ' . wp_json_encode( $filters );
        }

        $payload = array(
            'message' => implode( ' | ', $message_parts ),
            'content' => base64_encode( $content ),
            'branch'  => $branch,
        );

        if ( $existing_sha ) {
            $payload['sha'] = $existing_sha;
        }

        $put = wp_remote_request(
            $url,
            array(
                'method'  => 'PUT',
                'headers' => array(
                    'Authorization' => 'token ' . $this->options['github_token'],
                    'User-Agent'    => 'wp-export-markdown',
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $payload ),
            )
        );

        if ( is_wp_error( $put ) ) {
            $this->log_debug( 'GitHub sync failed: ' . $put->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $put );
        if ( in_array( $code, array( 200, 201 ), true ) ) {
            $body = json_decode( wp_remote_retrieve_body( $put ), true );
            $this->log_debug( 'GitHub sync ok: ' . $path . $download_name . ' (' . $branch . '), sha=' . ( isset( $body['content']['sha'] ) ? $body['content']['sha'] : 'n/a' ) );
        } else {
            $this->log_debug( 'GitHub sync HTTP ' . $code . ' for ' . $path . $download_name );
        }
    }

    private function push_to_drive( $file_path, $download_name ) {
        if ( empty( $this->options['drive_enabled'] ) ) {
            return;
        }

        if ( empty( $this->options['drive_token'] ) ) {
            return;
        }

        $token    = $this->options['drive_token'];
        $folder   = ! empty( $this->options['drive_folder_id'] ) ? $this->options['drive_folder_id'] : '';
        $boundary = wp_generate_password( 24, false );

        $metadata = array(
            'name' => $download_name,
        );

        if ( '' !== $folder ) {
            $metadata['parents'] = array( $folder );
        }

        $file_data = file_get_contents( $file_path );
        if ( false === $file_data ) {
            $this->log_debug( 'Drive sync skipped: failed reading export file.' );
            return;
        }

        $multipart_body  = "--$boundary\r\n";
        $multipart_body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $multipart_body .= wp_json_encode( $metadata ) . "\r\n";
        $multipart_body .= "--$boundary\r\n";
        $multipart_body .= "Content-Type: application/zip\r\n\r\n";
        $multipart_body .= $file_data . "\r\n";
        $multipart_body .= "--$boundary--";

        $response = wp_remote_post(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'multipart/related; boundary=' . $boundary,
                ),
                'body'    => $multipart_body,
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log_debug( 'Drive sync failed: ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, array( 200, 201 ), true ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $this->log_debug( 'Drive sync ok: file id ' . ( isset( $body['id'] ) ? $body['id'] : 'unknown' ) );
        } else {
            $this->log_debug( 'Drive sync HTTP ' . $code . ' for upload.' );
        }
    }

    private function log_debug( $message ) {
        if ( is_callable( $this->log ) ) {
            call_user_func( $this->log, $message );
        }
    }
}
