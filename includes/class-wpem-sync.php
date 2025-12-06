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

    public function push_export_files( $files, $download_name, $filters = array(), $overrides = array() ) {
        if ( empty( $files ) || ! is_array( $files ) ) {
            return;
        }

        $this->push_files_to_github( $files, '', $filters, $overrides );
        $this->push_files_to_drive( $files, '', $overrides );
    }

    public function push_exports( $file_path, $download_name, $filters = array(), $overrides = array() ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            $this->log_debug( 'Sync skipped: export file missing or unreadable.' );
            return;
        }

        $this->push_to_github( $file_path, $download_name, $filters, $overrides );
        $this->push_to_drive( $file_path, $download_name, $overrides );
    }

    public function push_import( $file_path, $download_name, $meta = array(), $overrides = array() ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            $this->log_debug( 'Sync skipped: import file missing or unreadable.' );
            return;
        }

        $this->push_to_github( $file_path, $download_name, array_merge( array( 'context' => 'import' ), $meta ), $overrides );
        $this->push_to_drive( $file_path, $download_name, $overrides );
    }

    private function push_files_to_github( $files, $folder, $filters, $overrides ) {
        if ( ! $this->is_enabled( 'github', $overrides ) ) {
            return;
        }

        if ( empty( $this->options['github_repo'] ) || empty( $this->options['github_token'] ) ) {
            return;
        }

        $repo_raw   = trim( $this->options['github_repo'] );
        $branch     = ! empty( $this->options['github_branch'] ) ? sanitize_text_field( $this->options['github_branch'] ) : 'main';
        $path       = ! empty( $this->options['github_path'] ) ? ltrim( trim( $this->options['github_path'] ), '/' ) . '/' : '';
        $folder     = trim( $folder, '/' );
        $folder_dir = ''; // store files directly under path, no date-named folder

        list( $owner, $repo ) = array_pad( explode( '/', $repo_raw, 2 ), 2, '' );
        if ( '' === $owner || '' === $repo ) {
            $this->log_debug( 'GitHub sync skipped: invalid repo format (expected owner/repo).' );
            return;
        }

        $owner_enc = rawurlencode( $owner );
        $repo_enc  = rawurlencode( $repo );

        foreach ( $files as $file ) {
            if ( empty( $file['name'] ) || ! isset( $file['content'] ) ) {
                continue;
            }

            $content_path = ltrim( $path . $folder_dir . $file['name'], '/' );
            $encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', $content_path ) ) );
            $url          = 'https://api.github.com/repos/' . $owner_enc . '/' . $repo_enc . '/contents/' . $encoded_path;

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
                'content' => base64_encode( (string) $file['content'] ),
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
                $this->log_debug( 'GitHub sync failed for ' . $content_path . ': ' . $put->get_error_message() );
                $this->log_debug( 'GitHub request (PUT) ' . $url . ' branch=' . $branch . ' payload_keys=' . implode( ',', array_keys( $payload ) ) );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $put );
            if ( in_array( $code, array( 200, 201 ), true ) ) {
                $body = json_decode( wp_remote_retrieve_body( $put ), true );
                $this->log_debug( 'GitHub sync ok: ' . $content_path . ' (' . $branch . '), sha=' . ( isset( $body['content']['sha'] ) ? $body['content']['sha'] : 'n/a' ) );
            } else {
                $this->log_debug( 'GitHub sync HTTP ' . $code . ' for ' . $content_path . ' (' . $branch . ')' );
                $this->log_debug( 'GitHub request (PUT) ' . $url . ' branch=' . $branch . ' payload_keys=' . implode( ',', array_keys( $payload ) ) );
                $body = wp_remote_retrieve_body( $put );
                if ( ! empty( $body ) ) {
                    $this->log_debug( 'GitHub response: ' . wp_strip_all_tags( $body ) );
                }
            }
        }
    }

    private function push_files_to_drive( $files, $folder, $overrides ) {
        if ( ! $this->is_enabled( 'drive', $overrides ) ) {
            return;
        }

        $token = $this->get_drive_access_token();

        if ( '' === $token ) {
            return;
        }

        $parent        = ! empty( $this->options['drive_folder_id'] ) ? $this->options['drive_folder_id'] : '';
        $target_parent = $parent; // do not create per-export folder

        foreach ( $files as $file ) {
            if ( empty( $file['name'] ) || ! isset( $file['content'] ) ) {
                continue;
            }

            $this->upload_drive_file( $file['name'], (string) $file['content'], $token, $target_parent );
        }
    }
    private function push_to_github( $file_path, $download_name, $filters, $overrides = array() ) {
        if ( ! $this->is_enabled( 'github', $overrides ) ) {
            return;
        }

        if ( empty( $this->options['github_repo'] ) || empty( $this->options['github_token'] ) ) {
            return;
        }

        $repo_raw     = trim( $this->options['github_repo'] );
        $branch       = ! empty( $this->options['github_branch'] ) ? sanitize_text_field( $this->options['github_branch'] ) : 'main';
        $path         = ! empty( $this->options['github_path'] ) ? ltrim( trim( $this->options['github_path'] ), '/' ) . '/' : '';
        $content_path = ltrim( $path . $download_name, '/' );
        $encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', $content_path ) ) );

        list( $owner, $repo ) = array_pad( explode( '/', $repo_raw, 2 ), 2, '' );
        if ( '' === $owner || '' === $repo ) {
            $this->log_debug( 'GitHub sync skipped: invalid repo format (expected owner/repo).' );
            return;
        }

        $owner_enc = rawurlencode( $owner );
        $repo_enc  = rawurlencode( $repo );
        $url       = 'https://api.github.com/repos/' . $owner_enc . '/' . $repo_enc . '/contents/' . $encoded_path;

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
            $this->log_debug( 'GitHub request (PUT) ' . $url . ' branch=' . $branch . ' payload_keys=' . implode( ',', array_keys( $payload ) ) );
            return;
        }

        $code = wp_remote_retrieve_response_code( $put );
        if ( in_array( $code, array( 200, 201 ), true ) ) {
            $body = json_decode( wp_remote_retrieve_body( $put ), true );
            $this->log_debug( 'GitHub sync ok: ' . $path . $download_name . ' (' . $branch . '), sha=' . ( isset( $body['content']['sha'] ) ? $body['content']['sha'] : 'n/a' ) );
        } else {
            $this->log_debug( 'GitHub sync HTTP ' . $code . ' for ' . $path . $download_name . ' (' . $branch . ')' );
            $this->log_debug( 'GitHub request (PUT) ' . $url . ' branch=' . $branch . ' payload_keys=' . implode( ',', array_keys( $payload ) ) );
            $body = wp_remote_retrieve_body( $put );
            if ( ! empty( $body ) ) {
                $this->log_debug( 'GitHub response: ' . wp_strip_all_tags( $body ) );
            }
        }
    }

    private function push_to_drive( $file_path, $download_name, $overrides = array() ) {
        if ( ! $this->is_enabled( 'drive', $overrides ) ) {
            return;
        }

        $token = $this->get_drive_access_token();

        if ( '' === $token ) {
            return;
        }

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

    private function is_enabled( $key, $overrides ) {
        $flag_key = $key . '_enabled';

        if ( isset( $overrides[ $flag_key ] ) ) {
            return (bool) $overrides[ $flag_key ];
        }

        return ! empty( $this->options[ $flag_key ] );
    }

    private function create_drive_folder( $name, $parent_id ) {
        if ( '' === trim( (string) $name ) ) {
            return '';
        }

        $body = array(
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        );

        if ( '' !== $parent_id ) {
            $body['parents'] = array( $parent_id );
        }

        $response = wp_remote_post(
            'https://www.googleapis.com/drive/v3/files',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->get_drive_access_token(),
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log_debug( 'Drive folder create failed: ' . $response->get_error_message() );
            return '';
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, array( 200, 201 ), true ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            return isset( $data['id'] ) ? $data['id'] : '';
        }

        return '';
    }

    private function upload_drive_file( $name, $content, $token, $parent_id ) {
        $boundary = wp_generate_password( 24, false );

        $metadata = array(
            'name' => $name,
        );

        if ( '' !== $parent_id ) {
            $metadata['parents'] = array( $parent_id );
        }

        $multipart_body  = "--$boundary\r\n";
        $multipart_body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $multipart_body .= wp_json_encode( $metadata ) . "\r\n";
        $multipart_body .= "--$boundary\r\n";
        $multipart_body .= "Content-Type: text/markdown\r\n\r\n";
        $multipart_body .= $content . "\r\n";
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
            $this->log_debug( 'Drive sync failed for ' . $name . ': ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( ! in_array( $code, array( 200, 201 ), true ) ) {
            $this->log_debug( 'Drive sync HTTP ' . $code . ' for ' . $name );
        }
    }

    public function fetch_github_file( $path ) {
        $path = ltrim( trim( (string) $path ), '/' );

        if ( '' === $path ) {
            return new WP_Error( 'wpexportmd_github_path_empty', __( 'GitHub path is empty.', 'export-posts-to-markdown' ) );
        }

        if ( empty( $this->options['github_repo'] ) || empty( $this->options['github_token'] ) ) {
            return new WP_Error( 'wpexportmd_github_config_missing', __( 'GitHub settings are missing.', 'export-posts-to-markdown' ) );
        }

        $repo_raw = trim( $this->options['github_repo'] );
        list( $owner, $repo ) = array_pad( explode( '/', $repo_raw, 2 ), 2, '' );

        if ( '' === $owner || '' === $repo ) {
            return new WP_Error( 'wpexportmd_github_repo_invalid', __( 'GitHub repo must be in owner/repo format.', 'export-posts-to-markdown' ) );
        }

        $branch = ! empty( $this->options['github_branch'] ) ? sanitize_text_field( $this->options['github_branch'] ) : 'main';
        $prefix = ! empty( $this->options['github_path'] ) ? ltrim( trim( $this->options['github_path'] ), '/' ) : '';

        $content_path = $prefix ? $prefix . '/' . $path : $path;
        $encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', $content_path ) ) );
        $url          = 'https://api.github.com/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/contents/' . $encoded_path . '?ref=' . rawurlencode( $branch );

        $response = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'Authorization' => 'token ' . $this->options['github_token'],
                    'User-Agent'    => 'wp-export-markdown',
                    'Accept'        => 'application/vnd.github.raw',
                ),
                'timeout' => 20,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'wpexportmd_github_http', 'GitHub HTTP ' . $code . ' for ' . $content_path );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return new WP_Error( 'wpexportmd_github_empty', __( 'Empty response from GitHub.', 'export-posts-to-markdown' ) );
        }

        $tmp = wp_tempnam( basename( $path ) );
        if ( ! $tmp ) {
            return new WP_Error( 'wpexportmd_tmp_fail', __( 'Could not create temp file for GitHub download.', 'export-posts-to-markdown' ) );
        }

        file_put_contents( $tmp, $body );

        return array(
            'tmp_path' => $tmp,
            'name'     => basename( $path ),
        );
    }

    public function fetch_drive_file( $file_id ) {
        $file_id = trim( (string) $file_id );

        if ( '' === $file_id ) {
            return new WP_Error( 'wpexportmd_drive_id_empty', __( 'Drive file ID is empty.', 'export-posts-to-markdown' ) );
        }

        $token = $this->get_drive_access_token();

        if ( '' === $token ) {
            return new WP_Error( 'wpexportmd_drive_token_missing', __( 'Drive token is missing.', 'export-posts-to-markdown' ) );
        }

        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?alt=media';
        $response = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'wpexportmd_drive_http', 'Drive HTTP ' . $code . ' for file ' . $file_id );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return new WP_Error( 'wpexportmd_drive_empty', __( 'Empty response from Drive.', 'export-posts-to-markdown' ) );
        }

        $headers = wp_remote_retrieve_headers( $response );
        $name    = $file_id;

        if ( isset( $headers['content-disposition'] ) && preg_match( '/filename="?([^";]+)"?/i', $headers['content-disposition'], $m ) ) {
            $name = basename( $m[1] );
        } elseif ( isset( $headers['content-type'] ) ) {
            $ct = strtolower( $headers['content-type'] );
            if ( false !== strpos( $ct, 'zip' ) ) {
                $name .= '.zip';
            } elseif ( false !== strpos( $ct, 'markdown' ) || false !== strpos( $ct, 'text/plain' ) ) {
                $name .= '.md';
            }
        }

        $tmp = wp_tempnam( 'wpexportmd_drive_' );
        if ( ! $tmp ) {
            return new WP_Error( 'wpexportmd_tmp_fail', __( 'Could not create temp file for Drive download.', 'export-posts-to-markdown' ) );
        }

        file_put_contents( $tmp, $body );

        return array(
            'tmp_path' => $tmp,
            'name'     => $name,
        );
    }

    private function get_drive_access_token() {
        if ( ! empty( $this->options['drive_token'] ) ) {
            return $this->options['drive_token'];
        }

        if ( empty( $this->options['drive_client_id'] ) || empty( $this->options['drive_client_secret'] ) || empty( $this->options['drive_refresh_token'] ) ) {
            return '';
        }

        $response = wp_remote_post(
            'https://accounts.google.com/o/oauth2/token',
            array(
                'body'    => array(
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $this->options['drive_client_id'],
                    'client_secret' => $this->options['drive_client_secret'],
                    'refresh_token' => $this->options['drive_refresh_token'],
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log_debug( 'Drive token refresh failed: ' . $response->get_error_message() );
            return '';
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $this->log_debug( 'Drive token refresh HTTP ' . $code );
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            return '';
        }

        $this->options['drive_token'] = $data['access_token'];
        update_option( 'wpexportmd_settings', $this->options );

        return $this->options['drive_token'];
    }
}
