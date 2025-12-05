<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPEM_Markdown {

    public function html_to_markdown( $html ) {
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

    public function markdown_to_html( $markdown, $media_map = array() ) {
        $markdown = str_replace( "\r\n", "\n", (string) $markdown );

        $lines     = explode( "\n", $markdown );
        $html      = '';
        $in_list   = false;
        $in_quote  = false;
        $in_code   = false;
        $code_buf  = array();
        $paragraph = array();

        $flush_paragraph = function () use ( &$paragraph, &$html, $media_map ) {
            if ( empty( $paragraph ) ) {
                return;
            }

            $text = implode( ' ', $paragraph );
            $text = $this->apply_inline_markdown( $text, $media_map );
            $html .= '<p>' . $text . '</p>';
            $paragraph = array();
        };

        $close_list = function () use ( &$in_list, &$html ) {
            if ( $in_list ) {
                $html   .= '</ul>';
                $in_list = false;
            }
        };

        $close_quote = function () use ( &$in_quote, &$html ) {
            if ( $in_quote ) {
                $html    .= '</blockquote>';
                $in_quote = false;
            }
        };

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            if ( $in_code ) {
                if ( 0 === strpos( $trimmed, '```' ) ) {
                    $html     .= '<pre><code>' . esc_html( implode( "\n", $code_buf ) ) . '</code></pre>';
                    $code_buf  = array();
                    $in_code   = false;
                    continue;
                }

                $code_buf[] = $line;
                continue;
            }

            if ( 0 === strpos( $trimmed, '```' ) ) {
                $flush_paragraph();
                $close_list();
                $close_quote();
                $in_code = true;
                continue;
            }

            if ( '' === $trimmed ) {
                $flush_paragraph();
                $close_list();
                $close_quote();
                continue;
            }

            if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $matches ) ) {
                $flush_paragraph();
                $close_list();
                $close_quote();
                $level = strlen( $matches[1] );
                $text  = $this->apply_inline_markdown( $matches[2], $media_map );
                $html .= '<h' . $level . '>' . $text . '</h' . $level . '>';
                continue;
            }

            if ( '---' === $trimmed || '***' === $trimmed ) {
                $flush_paragraph();
                $close_list();
                $close_quote();
                $html .= '<hr />';
                continue;
            }

            if ( 0 === strpos( $trimmed, '> ' ) ) {
                $close_list();
                if ( ! $in_quote ) {
                    $flush_paragraph();
                    $html    .= '<blockquote>';
                    $in_quote = true;
                }

                $line_content = ltrim( substr( $trimmed, 1 ) );
                $html        .= $this->apply_inline_markdown( $line_content, $media_map );
                continue;
            }

            if ( preg_match( '/^- (.+)$/', $trimmed, $matches ) ) {
                $flush_paragraph();
                $close_quote();
                if ( ! $in_list ) {
                    $html   .= '<ul>';
                    $in_list = true;
                }

                $html .= '<li>' . $this->apply_inline_markdown( $matches[1], $media_map ) . '</li>';
                continue;
            }

            $paragraph[] = $trimmed;
        }

        if ( $in_code ) {
            $html .= '<pre><code>' . esc_html( implode( "\n", $code_buf ) ) . '</code></pre>';
        }

        $flush_paragraph();
        $close_list();
        $close_quote();

        return $html;
    }

    public function parse_front_matter( $markdown ) {
        $meta    = array();
        $content = $markdown;

        if ( preg_match( '/^---\s*\n(.*?)\n---\s*\n/s', $markdown, $matches ) ) {
            $front_matter = trim( $matches[1] );
            $content      = substr( $markdown, strlen( $matches[0] ) );
            $lines        = preg_split( "/\r\n|\r|\n/", $front_matter );

            foreach ( $lines as $line ) {
                if ( false === strpos( $line, ':' ) ) {
                    continue;
                }

                list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
                $key                 = sanitize_key( $key );
                $value               = trim( $value, " \t\n\r\0\x0B\"" );

                if ( '' === $key ) {
                    continue;
                }

                if ( preg_match( '/^\[(.*)\]$/', $value, $array_matches ) ) {
                    $items      = array_map( 'trim', explode( ',', $array_matches[1] ) );
                    $clean_list = array();

                    foreach ( $items as $item ) {
                        $item = trim( $item, " \t\n\r\0\x0B\"" );
                        if ( '' !== $item ) {
                            $clean_list[] = $item;
                        }
                    }

                    $meta[ $key ] = $clean_list;
                    continue;
                }

                $meta[ $key ] = $value;
            }
        }

        return array(
            'meta'    => $meta,
            'content' => $content,
        );
    }

    public function escape_yaml( $str ) {
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

    public function format_yaml_list( array $items ) {
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

    private function apply_inline_markdown( $text, $media_map = array() ) {
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            function ( $matches ) {
                return '<code>' . esc_html( $matches[1] ) . '</code>';
            },
            $text
        );

        $text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
        $text = preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $text );

        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(\s*("?)([^" \t\)]+)\2(?:\s+"([^"]*)")?\s*\)/',
            function ( $matches ) use ( $media_map ) {
                $alt     = esc_attr( $matches[1] );
                $raw_src = isset( $matches[3] ) ? $matches[3] : '';
                $raw_src = trim( $raw_src, "\"'" );
                $src     = $this->resolve_media_src( $raw_src, $media_map );
                $title   = isset( $matches[4] ) ? trim( $matches[4] ) : '';

                $img = '<img src="' . esc_url( $src ) . '" alt="' . $alt . '"';
                if ( '' !== $title ) {
                    $img .= ' title="' . esc_attr( $title ) . '"';
                }
                $img .= ' />';

                if ( '' !== $title ) {
                    return '<figure class="wpem-image">' . $img . '<figcaption class="wpem-caption">' . esc_html( $title ) . '</figcaption></figure>';
                }

                return $img;
            },
            $text
        );

        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ( $matches ) {
                $label = esc_html( $matches[1] );
                $href  = esc_url( $matches[2] );

                return '<a href="' . $href . '">' . $label . '</a>';
            },
            $text
        );

        return $text;
    }

    private function resolve_media_src( $src, $media_map ) {
        if ( empty( $media_map ) ) {
            return $src;
        }

        $normalized = $this->normalize_media_path( $src );

        if ( $normalized && isset( $media_map[ $normalized ] ) && ! empty( $media_map[ $normalized ]['url'] ) ) {
            return $media_map[ $normalized ]['url'];
        }

        $with_slash = '/' . ltrim( $normalized, '/' );

        if ( $normalized && isset( $media_map[ $with_slash ] ) && ! empty( $media_map[ $with_slash ]['url'] ) ) {
            return $media_map[ $with_slash ]['url'];
        }

        return $src;
    }

    private function normalize_media_path( $path ) {
        $path = str_replace( '\\', '/', (string) $path );
        $path = ltrim( $path, '/' );

        $pos = strpos( $path, '_images/' );

        if ( false === $pos ) {
            return '';
        }

        return substr( $path, $pos );
    }
}
