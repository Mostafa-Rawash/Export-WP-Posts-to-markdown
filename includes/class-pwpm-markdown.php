<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PWPM_Markdown {

    public function strip_gutenberg_blocks( $content ) {
        $content = preg_replace( '/<!--\s*wp:[a-zA-Z0-9_-]+(\s+[^>]*)?\s*-->/', '', $content );
        $content = preg_replace( '/<!--\s*\/wp:[a-zA-Z0-9_-]+\s*-->/', '', $content );
        $content = preg_replace( '/<!--\s*wp:[a-zA-Z0-9_-]+\s*\{\s*[^}]*\}\s*-->/', '', $content );
        return $content;
    }

    public function unwrap_simple_p_tags( $content ) {
        $lines = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
        $result = '';
        $in_block = false;

        foreach ( $lines as $part ) {
            if ( preg_match( '/<(script|style|div|table|ul|ol|blockquote|pre|figure)(?:\s[^>]*)?>/i', $part ) ) {
                $in_block = true;
            }
            if ( preg_match( '/<\/(script|style|div|table|ul|ol|blockquote|pre|figure)>/i', $part ) ) {
                $in_block = false;
            }

            if ( $in_block ) {
                $result .= $part;
                continue;
            }

            if ( preg_match( '/^<p(?:\s[^>]*)?>((?:<[^>]+>)*)(.*?)(<\/p>)$/is', $part, $matches ) ) {
                $inner = trim( $matches[2] );
                if ( '' === $inner ) {
                    continue;
                }

                if ( preg_match( '/^(#{1,6}\s+)/', $inner ) ||
                     preg_match( '/^(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`)/', $inner ) ||
                     preg_match( '/^([-*]\s+)/', $inner ) ||
                     preg_match( '/^(\d+\.\s+)/', $inner ) ||
                     preg_match( '/^(`{3})/', $inner ) ||
                     preg_match( '/^>\s+/', $inner ) ) {
                    $result .= $inner . "\n";
                } else {
                    $result .= $part;
                }
            } else {
                $result .= $part;
            }
        }

        return $result;
    }

    public function markdown_to_wp_blocks( $content, $media_map = array() ) {
        $content = str_replace( "\r\n", "\n", (string) $content );
        $content = $this->convert_obsidian_images( $content );

        $lines = explode( "\n", $content );
        $result = '';
        $in_html_block = false;
        $html_buffer = '';
        $html_nesting = 0;
        $html_block_tags = array( 'div', 'table', 'pre', 'blockquote', 'ul', 'ol', 'script', 'style', 'figure', 'header', 'footer', 'nav', 'article', 'section', 'aside', 'main', 'form' );
        $list_items = array();
        $in_list = false;
        $table_rows = array();
        $in_table = false;
        $is_table_header = false;
        $in_code = false;
        $code_buffer = '';
        $code_lang = '';

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            if ( $in_code ) {
                if ( preg_match( '/^```$/', $trimmed ) || preg_match( '/^```\s*$/', $trimmed ) ) {
                    $code_content = rtrim( $code_buffer );
                    $result .= "<!-- wp:code -->\n";
                    $result .= '<pre class="wp-block-code"><code';
                    if ( '' !== $code_lang ) {
                        $result .= ' class="language-' . esc_attr( $code_lang ) . '"';
                    }
                    $result .= '>' . esc_html( $code_content ) . '</code></pre>';
                    $result .= "\n<!-- /wp:code -->\n";
                    $in_code = false;
                    $code_buffer = '';
                    $code_lang = '';
                } else {
                    $code_buffer .= $line . "\n";
                }
                continue;
            }

            if ( preg_match( '/^```(\w*)$/', $trimmed, $matches ) ) {
                if ( $in_list ) {
                    $result .= $this->finish_list_block( $list_items );
                    $list_items = array();
                    $in_list = false;
                }
                if ( $in_table ) {
                    $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                    $table_rows = array();
                    $in_table = false;
                    $is_table_header = false;
                }
                $in_code = true;
                $code_lang = isset( $matches[1] ) ? $matches[1] : '';
                $code_buffer = '';
                continue;
            }

            $open_pattern = '/^<(' . implode( '|', $html_block_tags ) . ')(?:\s[^>]*)?>/is';

            if ( ! $in_html_block && preg_match( $open_pattern, $trimmed ) ) {
                if ( $in_list ) {
                    $result .= $this->finish_list_block( $list_items );
                    $list_items = array();
                    $in_list = false;
                }
                if ( $in_table ) {
                    $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                    $table_rows = array();
                    $in_table = false;
                    $is_table_header = false;
                }
                $in_html_block = true;
                $html_buffer = $line . "\n";

                $open_count = preg_match_all( '/<(' . implode( '|', $html_block_tags ) . ')(?:\s[^>]*)?>/i', $line );
                $close_count = preg_match_all( '/<\/(' . implode( '|', $html_block_tags ) . ')\s*>/i', $line );
                $html_nesting = $open_count - $close_count;

                if ( $html_nesting <= 0 ) {
                    $result .= "<!-- wp:html -->\n" . $html_buffer . "<!-- /wp:html -->\n";
                    $html_buffer = '';
                    $in_html_block = false;
                    $html_nesting = 0;
                }
                continue;
            }

            if ( $in_html_block ) {
                $html_buffer .= $line . "\n";

                $open_count = preg_match_all( '/<(' . implode( '|', $html_block_tags ) . ')(?:\s[^>]*)?>/i', $line );
                $close_count = preg_match_all( '/<\/(' . implode( '|', $html_block_tags ) . ')\s*>/i', $line );
                $html_nesting = $html_nesting + $open_count - $close_count;

                if ( $html_nesting <= 0 ) {
                    $result .= "<!-- wp:html -->\n" . $html_buffer . "<!-- /wp:html -->\n";
                    $html_buffer = '';
                    $in_html_block = false;
                    $html_nesting = 0;
                }
                continue;
            }

            if ( '' === $trimmed ) {
                if ( $in_list ) {
                    $result .= $this->finish_list_block( $list_items );
                    $list_items = array();
                    $in_list = false;
                }
                if ( $in_table ) {
                    $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                    $table_rows = array();
                    $in_table = false;
                    $is_table_header = false;
                }
                continue;
            }

            if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $matches ) ) {
                if ( $in_list ) {
                    $result .= $this->finish_list_block( $list_items );
                    $list_items = array();
                    $in_list = false;
                }
                if ( $in_table ) {
                    $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                    $table_rows = array();
                    $in_table = false;
                    $is_table_header = false;
                }
                $level = strlen( $matches[1] );
                $text = $this->apply_inline_markdown( $matches[2], $media_map );
                $result .= "<!-- wp:heading -->\n<h{$level}>{$text}</h{$level}>\n<!-- /wp:heading -->\n";
                continue;
            }

            if ( preg_match( '/^[-*]\s+(.*)$/', $trimmed, $matches ) ) {
                if ( $in_table ) {
                    $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                    $table_rows = array();
                    $in_table = false;
                    $is_table_header = false;
                }
                $in_list = true;
                $list_items[] = array( 'type' => 'ul', 'text' => $matches[1] );
                continue;
            }

            if ( preg_match( '/^\d+\.\s+(.*)$/', $trimmed, $matches ) ) {
                if ( $in_table ) {
                    $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                    $table_rows = array();
                    $in_table = false;
                    $is_table_header = false;
                }
                $in_list = true;
                $list_items[] = array( 'type' => 'ol', 'text' => $matches[1] );
                continue;
            }

            if ( preg_match( '/^>\s+(.*)$/', $trimmed, $matches ) ) {
                if ( $in_list ) {
                    $result .= $this->finish_list_block( $list_items );
                    $list_items = array();
                    $in_list = false;
                }
                if ( $in_table ) {
                    $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                    $table_rows = array();
                    $in_table = false;
                    $is_table_header = false;
                }
                $text = $this->apply_inline_markdown( $matches[1], $media_map );
                $result .= "<!-- wp:quote -->\n<blockquote><p>{$text}</p></blockquote>\n<!-- /wp:quote -->\n";
                continue;
            }

            if ( preg_match( '/^```(\w*)$/', $trimmed, $matches ) ) {
                if ( $in_list ) {
                    $result .= $this->finish_list_block( $list_items );
                    $list_items = array();
                    $in_list = false;
                }
                if ( $in_table ) {
                    $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                    $table_rows = array();
                    $in_table = false;
                    $is_table_header = false;
                }
                continue;
            }

            if ( preg_match( '/^\|.*\|$/', $trimmed ) ) {
                if ( $in_list ) {
                    $result .= $this->finish_list_block( $list_items );
                    $list_items = array();
                    $in_list = false;
                }
                $in_table = true;
                if ( preg_match( '/^\|\s*[-:]+\s*(\|\s*[-:]+\s*)*\|$/', $trimmed ) ) {
                    $is_table_header = true;
                } else {
                    $table_rows[] = $trimmed;
                }
                continue;
            }

            if ( $in_table ) {
                $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
                $table_rows = array();
                $in_table = false;
                $is_table_header = false;
            }

            if ( $in_list ) {
                $result .= $this->finish_list_block( $list_items );
                $list_items = array();
                $in_list = false;
            }

            $text = $this->apply_inline_markdown( $line, $media_map );
            $result .= "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n";
        }

        if ( $in_list ) {
            $result .= $this->finish_list_block( $list_items );
        }

        if ( $in_table ) {
            $result .= $this->convert_table_to_html( $table_rows, $is_table_header );
        }

        if ( $in_code && '' !== $code_buffer ) {
            $code_content = rtrim( $code_buffer );
            $result .= "<!-- wp:code -->\n";
            $result .= '<pre class="wp-block-code"><code';
            if ( '' !== $code_lang ) {
                $result .= ' class="language-' . esc_attr( $code_lang ) . '"';
            }
            $result .= '>' . esc_html( $code_content ) . '</code></pre>';
            $result .= "\n<!-- /wp:code -->\n";
        }

        if ( $in_html_block && '' !== $html_buffer ) {
            $result .= "<!-- wp:html -->\n" . $html_buffer . "<!-- /wp:html -->\n";
        }

        return $result;
    }

    private function convert_table_to_html( $rows, $is_header ) {
        if ( empty( $rows ) ) {
            return '';
        }

        $html = "<!-- wp:table -->\n<table>\n";

        if ( $is_header && isset( $rows[0] ) ) {
            $html .= "<thead>\n<tr>\n";
            $cells = $this->parse_table_row( $rows[0] );
            foreach ( $cells as $cell ) {
                $cell = $this->apply_inline_markdown( trim( $cell ) );
                $html .= '<th>' . $cell . '</th>' . "\n";
            }
            $html .= "</tr>\n</thead>\n";
            array_shift( $rows );
        }

        $html .= "<tbody>\n";
        foreach ( $rows as $row ) {
            $html .= "<tr>\n";
            $cells = $this->parse_table_row( $row );
            foreach ( $cells as $cell ) {
                $cell = $this->apply_inline_markdown( trim( $cell ) );
                $html .= '<td>' . $cell . '</td>' . "\n";
            }
            $html .= "</tr>\n";
        }
        $html .= "</tbody>\n</table>\n<!-- /wp:table -->\n";

        return $html;
    }

    private function parse_table_row( $row ) {
        $row = trim( $row );
        $row = trim( $row, '|' );
        $cells = explode( '|', $row );
        return array_map( 'trim', $cells );
    }

    private function finish_list_block( $list_items ) {
        if ( empty( $list_items ) ) {
            return '';
        }

        $first_type = $list_items[0]['type'];
        $is_ordered = ( 'ol' === $first_type );
        $tag = $is_ordered ? 'ol' : 'ul';
        $ordered_attr = $is_ordered ? ' {"ordered":true}' : '';
        $items = '';

        foreach ( $list_items as $item ) {
            $text = $this->apply_inline_markdown( $item['text'] );
            $items .= "<!-- wp:list-item -->\n";
            $items .= '<li>' . $text . '</li>' . "\n";
            $items .= "<!-- /wp:list-item -->\n";
        }

        return "<!-- wp:list{$ordered_attr} -->\n<{$tag} class=\"wp-block-list\">\n{$items}</{$tag}>\n<!-- /wp:list -->\n";
    }

    public function mixed_content_to_html( $content, $media_map = array() ) {
        $html_tags = 'div|table|ul|ol|pre|blockquote|script|style|figure|header|footer|nav|article|section|aside|main|form|hr';

        $pattern = '/(<(' . $html_tags . ')(?:\s[^>]*)?>(?:.*?)<\/\2>|<' . $html_tags . '(?:\s[^>]*)?\/?>)/is';
        $parts = preg_split( $pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

        $result = '';

        foreach ( $parts as $part ) {
            $trimmed = trim( $part );

            if ( '' === $trimmed ) {
                continue;
            }

            if ( preg_match( '/^<(' . $html_tags . ')(?:\s[^>]*)?>/is', $trimmed ) ||
                 preg_match( '/^<\/(div|table|ul|ol|pre|blockquote|script|style|figure|header|footer|nav|article|section|aside|main)>/is', $trimmed ) ||
                 preg_match( '/^<(' . $html_tags . ')(?:\s[^>]*)?\/?>$/is', $trimmed ) ) {
                $result .= "\n" . $part . "\n";
                continue;
            }

            if ( preg_match( '/<(' . $html_tags . ')(?:\s[^>]*)?>(?:.|\n)*?<\/\1>/is', $part ) ) {
                $result .= "\n" . $part . "\n";
                continue;
            }

            $converted = $this->convert_single_line_markdown( $part, $media_map );
            $result .= '<p>' . $converted . '</p>' . "\n";
        }

        return $result;
    }

    private function convert_single_line_markdown( $text, $media_map = array() ) {
        $text = $this->apply_inline_markdown( $text, $media_map );
        $text = preg_replace( '/<br\s*\/?>\s*$/i', '', $text );
        return $text;
    }

    public function preserve_wp_html_blocks( $content ) {
        $html_blocks = array();
        $index = 0;

        $content = preg_replace_callback(
            '/<!--\s*wp:html\s*-->(.*?)<!--\s*\/wp:html\s*-->/is',
            function ( $matches ) use ( &$html_blocks, &$index ) {
                $placeholder = '[[PWPM_HTML_' . $index . ']]';
                $html_blocks[ $placeholder ] = trim( $matches[1] );
                $index++;
                return $placeholder;
            },
            $content
        );

        $content = preg_replace_callback(
            '/<!--\s*wp:code\s*-->.*?<pre[^>]*><code[^>]*?(?:class="language-([^"]+)")?[^>]*>(.*?)<\/code><\/pre>.*?<!--\s*\/wp:code\s*-->/is',
            function ( $matches ) use ( &$html_blocks, &$index ) {
                $placeholder = '[[PWPM_CODE_' . $index . ']]';
                $lang = ! empty( $matches[1] ) ? $matches[1] : '';
                $code = isset( $matches[2] ) ? $matches[2] : '';
                $code = html_entity_decode( $code, ENT_QUOTES, 'UTF-8' );
                $html_blocks[ $placeholder ] = "\n```" . $lang . "\n" . trim( $code, "\r\n" ) . "\n```\n";
                $index++;
                return $placeholder;
            },
            $content
        );

        $content = preg_replace_callback(
            '/<!--\s*wp:freeform\s*-->(.*?)<!--\s*\/wp:freeform\s*-->/is',
            function ( $matches ) use ( &$html_blocks, &$index ) {
                $placeholder = '[[PWPM_FREEFORM_' . $index . ']]';
                $html_blocks[ $placeholder ] = trim( $matches[1] );
                $index++;
                return $placeholder;
            },
            $content
        );

        return array(
            'content' => $content,
            'blocks'  => $html_blocks
        );
    }

    public function restore_wp_html_blocks( $content, $blocks ) {
        foreach ( $blocks as $placeholder => $html ) {
            $content = str_replace( $placeholder, $html, $content );
        }
        return $content;
    }

    public function html_to_markdown( $html ) {
        $html = preg_replace_callback(
            '/<table[^>]*>.*?<\/table>/is',
            function ( $matches ) {
                return $this->table_html_to_markdown( $matches[0] );
            },
            $html
        );

        $html = preg_replace_callback(
            '/<pre[^>]*><code[^>]*?(?:class="language-([^"]+)")?[^>]*>(.*?)<\/code><\/pre>/is',
            function ( $matches ) {
                $lang = ! empty( $matches[1] ) ? $matches[1] : '';
                $code = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );
                $code = trim( $code, "\r\n" );

                return "\n```" . $lang . "\n" . $code . "\n```\n\n";
            },
            $html
        );

        $html = preg_replace_callback(
            '/<code[^>]*>(.*?)<\/code>/is',
            function ( $matches ) {
                $code = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );

                return '`' . trim( $code ) . '`';
            },
            $html
        );

        $html = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/is', "# $1\n\n", $html );
        $html = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', "## $1\n\n", $html );
        $html = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', "### $1\n\n", $html );
        $html = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/is', "#### $1\n\n", $html );

        $html = preg_replace( '/<blockquote[^>]*>(.*?)<\/blockquote>/is', "> $1\n\n", $html );

        $html = preg_replace_callback(
            '/<(ul|ol)[^>]*>(.*?)<\/\1>/is',
            function ( $matches ) {
                $is_ordered = ( 'ol' === strtolower( $matches[1] ) );
                $content = $matches[2];
                $items = array();

                if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $content, $li_matches ) ) {
                    foreach ( $li_matches[1] as $li_content ) {
                        $items[] = $li_content;
                    }
                }

                if ( empty( $items ) ) {
                    return '';
                }

                $result = "\n";
                $counter = 1;

                foreach ( $items as $item ) {
                    $item = $this->convert_inline_html_to_markdown( $item );
                    $item = trim( wp_strip_all_tags( $item ) );
                    $item = html_entity_decode( $item, ENT_QUOTES, 'UTF-8' );

                    if ( $is_ordered ) {
                        $result .= $counter . '. ' . $item . "\n";
                        $counter++;
                    } else {
                        $result .= '- ' . $item . "\n";
                    }
                }

                return $result . "\n";
            },
            $html
        );

        $html = preg_replace_callback(
            '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            function ( $matches ) {
                $label = trim( $matches[2] );

                return '[' . $label . '](' . esc_url_raw( $matches[1] ) . ')';
            },
            $html
        );

        $html = preg_replace( '/<(strong|b)>(.*?)<\/\1>/is', "**$2**", $html );
        $html = preg_replace( '/<(em|i)>(.*?)<\/\1>/is', "*$2*", $html );

        $html = preg_replace_callback(
            '/<img[^>]*(?:src=["\']([^"\']+)["\']|alt=["\']([^"\']*)["\']|title=["\']([^"\']*)["\'])+[^>]*>/is',
            function ( $matches ) {
                $full = $matches[0];
                $src = '';
                $alt = '';
                $title = '';

                if ( preg_match( '/src=["\']([^"\']+)["\']/', $full, $m ) ) {
                    $src = esc_url_raw( $m[1] );
                }
                if ( preg_match( '/alt=["\']([^"\']*)["\']/', $full, $m ) ) {
                    $alt = trim( $m[1] );
                }
                if ( preg_match( '/title=["\']([^"\']*)["\']/', $full, $m ) ) {
                    $title = trim( $m[1] );
                }

                if ( '' !== $title ) {
                    return '![' . $alt . '](' . $src . ' "' . $title . '")';
                }
                return '![' . $alt . '](' . $src . ')';
            },
            $html
        );

        $html = preg_replace( '/<hr\s*\/?\>/i', "\n---\n", $html );
        $html = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html );
        $html = preg_replace( '/<br\s*\/?\>/i', "  \n", $html );

        $text = wp_strip_all_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/(\S)(\n-\s)/', "$1\n\n- ", $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        return trim( $text );
    }

    private function convert_inline_html_to_markdown( $html ) {
        $html = preg_replace( '/<(strong|b)>(.*?)<\/\1>/is', "**$2**", $html );
        $html = preg_replace( '/<(em|i)>(.*?)<\/\1>/is', "*$2*", $html );
        $html = preg_replace_callback(
            '/<code[^>]*>(.*?)<\/code>/is',
            function ( $matches ) {
                return '`' . trim( $matches[1] ) . '`';
            },
            $html
        );
        $html = preg_replace_callback(
            '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            function ( $matches ) {
                return '[' . trim( $matches[2] ) . '](' . $matches[1] . ')';
            },
            $html
        );

        return $html;
    }

    public function markdown_to_html( $markdown, $media_map = array() ) {
        $markdown = str_replace( "\r\n", "\n", (string) $markdown );
        $markdown = $this->convert_obsidian_images( $markdown );

        $lines     = explode( "\n", $markdown );
        $html      = '';
        $in_list   = false;
        $list_type = '';
        $in_quote  = false;
        $in_code   = false;
        $code_buf  = array();
        $paragraph = array();
        $line_count = count( $lines );

        $flush_paragraph = function () use ( &$paragraph, &$html, $media_map ) {
            if ( empty( $paragraph ) ) {
                return;
            }

            $text = implode( ' ', $paragraph );
            $text = $this->apply_inline_markdown( $text, $media_map );
            $html .= '<p>' . $text . '</p>';
            $paragraph = array();
        };

        $close_list = function () use ( &$in_list, &$html, &$list_type ) {
            if ( $in_list ) {
                $html   .= ( 'ol' === $list_type ) ? '</ol>' : '</ul>';
                $in_list = false;
                $list_type = '';
            }
        };

        $close_quote = function () use ( &$in_quote, &$html ) {
            if ( $in_quote ) {
                $html    .= '</blockquote>';
                $in_quote = false;
            }
        };

        for ( $i = 0; $i < $line_count; $i++ ) {
            $line    = $lines[ $i ];
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

            if ( $this->is_table_header( $trimmed, $lines, $i ) ) {
                $flush_paragraph();
                $close_list();
                $close_quote();

                $table = $this->build_table_html( $lines, $i, $media_map );
                $html .= $table['html'];
                $i     = $table['end'];
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

            if ( preg_match( '/^[-*] (.+)$/', $trimmed, $matches ) ) {
                $flush_paragraph();
                $close_quote();
                if ( ! $in_list || 'ul' !== $list_type ) {
                    $close_list();
                    $html      .= '<ul>';
                    $in_list    = true;
                    $list_type  = 'ul';
                }

                $html .= '<li>' . $this->apply_inline_markdown( $matches[1], $media_map ) . '</li>';
                continue;
            }

            if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $matches ) ) {
                $flush_paragraph();
                $close_quote();
                if ( ! $in_list || 'ol' !== $list_type ) {
                    $close_list();
                    $html      .= '<ol>';
                    $in_list    = true;
                    $list_type  = 'ol';
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

    private function convert_obsidian_images( $markdown ) {
        return preg_replace_callback(
            '/!\[\[([^\]]+)\]\]/',
            function ( $matches ) {
                $target = trim( $matches[1] );
                $alt    = '';

                if ( false !== strpos( $target, '|' ) ) {
                    list( $target, $alt ) = array_map( 'trim', explode( '|', $target, 2 ) );

                    // Obsidian also uses the pipe for display size (|300 or |300x200), not alt text.
                    if ( preg_match( '/^\d+(x\d+)?$/', $alt ) ) {
                        $alt = '';
                    }
                }

                $alt = str_replace( array( '[', ']' ), '', $alt );

                if ( '' === $target ) {
                    return $matches[0];
                }

                if ( 0 === strpos( $target, '_images/' ) || 0 === strpos( $target, '/_images/' ) ) {
                    return '![' . $alt . '](' . ltrim( $target, '/' ) . ')';
                }

                return '![' . $alt . '](_images/' . $target . ')';
            },
            $markdown
        );
    }

    private function is_table_header( $line, $lines, $index ) {
        if ( false === strpos( $line, '|' ) ) {
            return false;
        }

        $next = isset( $lines[ $index + 1 ] ) ? trim( $lines[ $index + 1 ] ) : '';

        if ( '' === $next ) {
            return false;
        }

        if ( preg_match( '/^\s*\|?(?:\s*:?-{3,}:?\s*\|)+\s*:?-{3,}:?\s*\|?\s*$/', $next ) ) {
            return true;
        }

        if ( false !== strpos( $next, '|' ) ) {
            return true;
        }

        return false;
    }

    private function build_table_html( $lines, $start_index, $media_map ) {
        $header_line = $lines[ $start_index ];
        $next_line   = isset( $lines[ $start_index + 1 ] ) ? trim( $lines[ $start_index + 1 ] ) : '';
        $has_separator = '' !== $next_line && (bool) preg_match( '/^\s*\|?(?:\s*:?-{3,}:?\s*\|)+\s*:?-{3,}:?\s*\|?\s*$/', $next_line );
        $separator_idx = $has_separator ? $start_index + 1 : $start_index;
        $rows          = array();
        $end           = $separator_idx;

        if ( $has_separator ) {
            for ( $i = $separator_idx + 1; $i < count( $lines ); $i++ ) {
                $row_line = trim( $lines[ $i ] );

                if ( '' === $row_line ) {
                    $end = $i - 1;
                    break;
                }

                if ( false === strpos( $row_line, '|' ) ) {
                    $end = $i - 1;
                    break;
                }

                $rows[] = $row_line;
                $end    = $i;
            }
        } else {
            for ( $i = $start_index + 1; $i < count( $lines ); $i++ ) {
                $row_line = trim( $lines[ $i ] );

                if ( '' === $row_line ) {
                    continue;
                }

                if ( false === strpos( $row_line, '|' ) ) {
                    $end = $i - 1;
                    break;
                }

                $rows[] = $row_line;
                $end    = $i;
            }
        }

        $header_cells = $this->split_table_row( $header_line );
        $thead_cells  = array();
        foreach ( $header_cells as $cell ) {
            $thead_cells[] = '<th>' . $this->apply_inline_markdown( $cell, $media_map ) . '</th>';
        }

        $body_rows = array();
        foreach ( $rows as $row_line ) {
            $cells = $this->split_table_row( $row_line );
            $cells_html = array();
            foreach ( $cells as $cell ) {
                $cells_html[] = '<td>' . $this->apply_inline_markdown( $cell, $media_map ) . '</td>';
            }
            $body_rows[] = '<tr>' . implode( '', $cells_html ) . '</tr>';
        }

        $html  = '<table><thead><tr>' . implode( '', $thead_cells ) . '</tr></thead>';
        $html .= '<tbody>' . implode( '', $body_rows ) . '</tbody></table>';

        return array(
            'html' => $html,
            'end'  => $end,
        );
    }

    private function split_table_row( $line ) {
        $line = trim( $line );
        $line = trim( $line, '|' );
        $parts = array_map( 'trim', explode( '|', $line ) );

        return $parts;
    }

    private function table_html_to_markdown( $table_html ) {
        if ( ! preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/is', $table_html, $row_matches ) ) {
            return '';
        }

        $rows = array();
        foreach ( $row_matches[1] as $row_html ) {
            if ( ! preg_match_all( '/<(th|td)[^>]*>(.*?)<\/\1>/is', $row_html, $cell_matches ) ) {
                continue;
            }

            $cells = array();
            foreach ( $cell_matches[2] as $cell_html ) {
                $cell_text = wp_strip_all_tags( $cell_html );
                $cell_text = html_entity_decode( $cell_text, ENT_QUOTES, 'UTF-8' );
                $cell_text = trim( preg_replace( '/\s+/', ' ', $cell_text ) );
                $cells[] = $cell_text;
            }

            if ( ! empty( $cells ) ) {
                $rows[] = $cells;
            }
        }

        if ( empty( $rows ) ) {
            return '';
        }

        $header = array_shift( $rows );
        $header_line = '| ' . implode( ' | ', $header ) . ' |';
        $separator   = array();
        foreach ( $header as $cell ) {
            $separator[] = '---';
        }
        $separator_line = '| ' . implode( ' | ', $separator ) . ' |';

        $body_lines = array();
        foreach ( $rows as $row ) {
            $body_lines[] = '| ' . implode( ' | ', $row ) . ' |';
        }

        $lines = array_merge( array( $header_line, $separator_line ), $body_lines );

        return "\n" . implode( "\n", $lines ) . "\n\n";
    }

    public function parse_front_matter( $markdown ) {
        $meta    = array();
        $content = $markdown;

        $markdown = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $markdown );

        if ( preg_match( '/^\s*---\s*\r?\n(.*?)\r?\n---\s*\r?\n/s', $markdown, $matches ) ) {
            $front_matter = trim( $matches[1] );
            $content      = substr( $markdown, strlen( $matches[0] ) );
            $lines        = preg_split( "/\r\n|\r|\n/", $front_matter );
            $current_key  = '';

            foreach ( $lines as $line ) {
                if ( preg_match( '/^\s*-\s*(.+)$/', $line, $list_matches ) ) {
                    if ( '' !== $current_key ) {
                        if ( ! isset( $meta[ $current_key ] ) || ! is_array( $meta[ $current_key ] ) ) {
                            $meta[ $current_key ] = array();
                        }
                        $meta[ $current_key ][] = trim( $list_matches[1], " \t\n\r\0\x0B\"" );
                    }
                    continue;
                }

                if ( false === strpos( $line, ':' ) ) {
                    $current_key = '';
                    continue;
                }

                list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
                $key                 = sanitize_key( $key );
                $value               = trim( $value, " \t\n\r\0\x0B\"" );

                if ( '' === $key ) {
                    $current_key = '';
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
                    $current_key  = $key;
                    continue;
                }

                $meta[ $key ] = $value;
                $current_key  = ( '' === $value ) ? $key : '';
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

    public function format_yaml_block_list( array $items, $indent = 2 ) {
        $items = array_filter( array_map( 'wp_strip_all_tags', $items ) );
        $items = array_unique( array_map( 'trim', $items ) );

        if ( empty( $items ) ) {
            return array();
        }

        $lines  = array();
        $prefix = str_repeat( ' ', (int) $indent );

        foreach ( $items as $item ) {
            $lines[] = $prefix . '- "' . $this->escape_yaml( $item ) . '"';
        }

        return $lines;
    }

    private function apply_inline_markdown( $text, $media_map = array() ) {
        $text = preg_replace( '/\*{4,}(?=\S)/', '**', $text );
        $text = preg_replace( '/(?<=\S)\*{4,}/', '**', $text );

        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
            function ( $matches ) use ( $media_map ) {
                $alt     = esc_attr( $matches[1] );
                $raw_src = trim( $matches[2], "\"'" );
                $src     = $this->resolve_media_src( $raw_src, $media_map );
                $title   = isset( $matches[3] ) ? trim( $matches[3] ) : '';

                $img = '<img src="' . esc_url( $src ) . '" alt="' . $alt . '" style="max-width: 100%; height: auto;"';
                if ( '' !== $title ) {
                    $img .= ' title="' . esc_attr( $title ) . '"';
                }
                $img .= ' />';

                if ( '' !== $title ) {
                    return '<figure class="pwpm-image">' . $img . '<figcaption class="pwpm-caption">' . esc_html( $title ) . '</figcaption></figure>';
                }

                return $img;
            },
            $text
        );

        $text = preg_replace_callback(
            '/<[^>]+>|\[([^\]]+)\]\(([^)]+)\)|`([^`]+)`|\*\*((?:(?!\*\*).)+)\*\*|\*([^*]+)\*/',
            function ( $matches ) use ( $media_map ) {
                if ( ! empty( $matches[0] ) && '<' === $matches[0][0] ) {
                    return $matches[0];
                }

                if ( ! empty( $matches[1] ) && ! empty( $matches[2] ) ) {
                    $label = $this->format_link_label( $matches[1] );
                    $href  = esc_url( $matches[2] );
                    return '<a href="' . $href . '">' . $label . '</a>';
                }

                if ( ! empty( $matches[3] ) ) {
                    return '<code>' . esc_html( $matches[3] ) . '</code>';
                }

                if ( ! empty( $matches[4] ) ) {
                    $content = $this->apply_inline_markdown( $matches[4], $media_map );
                    return '<strong>' . $content . '</strong>';
                }

                if ( ! empty( $matches[5] ) ) {
                    $content = $this->apply_inline_markdown( $matches[5], $media_map );
                    return '<em>' . $content . '</em>';
                }

                return $matches[0];
            },
            $text
        );

        return $text;
    }

    private function format_link_label( $label ) {
        $label = esc_html( $label );

        $label = preg_replace_callback(
            '/`([^`]+)`/',
            function ( $matches ) {
                return '<code>' . esc_html( $matches[1] ) . '</code>';
            },
            $label
        );

        $label = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $label );
        $label = preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $label );

        return $label;
    }

    private function resolve_media_src( $src, $media_map ) {
        if ( empty( $media_map ) ) {
            return $src;
        }

        $src_key = str_replace( '\\', '/', (string) $src );
        $src_key = ltrim( $src_key, '/' );

        if ( $src_key && isset( $media_map[ $src_key ] ) && ! empty( $media_map[ $src_key ]['url'] ) ) {
            return $media_map[ $src_key ]['url'];
        }

        if ( $src_key && isset( $media_map[ '/' . $src_key ] ) && ! empty( $media_map[ '/' . $src_key ]['url'] ) ) {
            return $media_map[ '/' . $src_key ]['url'];
        }

        $normalized = $this->normalize_media_path( $src );

        if ( $normalized && isset( $media_map[ $normalized ] ) && ! empty( $media_map[ $normalized ]['url'] ) ) {
            return $media_map[ $normalized ]['url'];
        }

        $with_slash = '/' . ltrim( $normalized, '/' );

        if ( $normalized && isset( $media_map[ $with_slash ] ) && ! empty( $media_map[ $with_slash ]['url'] ) ) {
            return $media_map[ $with_slash ]['url'];
        }

        if ( '' === $normalized && $src_key ) {
            $basename = basename( $src_key );
            $fallback = '_images/' . $basename;
            if ( isset( $media_map[ $fallback ] ) && ! empty( $media_map[ $fallback ]['url'] ) ) {
                return $media_map[ $fallback ]['url'];
            }
            if ( isset( $media_map[ '/' . $fallback ] ) && ! empty( $media_map[ '/' . $fallback ]['url'] ) ) {
                return $media_map[ '/' . $fallback ]['url'];
            }
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
