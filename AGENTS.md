# AGENTS.md - Posts Markdown

WordPress plugin for exporting posts to Markdown files with YAML front matter and importing them back.

## Project Overview

- **Type**: WordPress Admin Plugin (handles `post` post type)
- **Main Class**: `WP_Posts_Markdown`
- **Helper Classes**: `PWPM_Exporter`, `PWPM_Importer`, `PWPM_Markdown`, `PWPM_Media`, `PWPM_Sync`
- **Text Domain**: `posts-markdown`
- **No Test Suite**: This project has no automated tests

## Build / Lint Commands

```bash
# PHP Syntax Check (all files)
find . -name "*.php" -exec php -l {} \;

# Single file syntax check
php -l includes/class-pwpm-exporter.php

# PHPStan (if installed globally)
phpstan analyse includes/ --level=5

# PHPCS WordPress Coding Standards
phpcs --standard=WordPress includes/

# PHPCS single file
phpcs --standard=WordPress includes/class-pwpm-exporter.php
```

## Code Style Guidelines

### Formatting
- **4 spaces** (no tabs)
- Opening brace on same line: `class Foo {`
- Single space after keywords: `if ( $var ) {`
- WordPress array syntax: `array()` not `[]`
- Trailing comma on multi-line arrays
- No PHPDoc blocks (WordPress legacy style)

### File Structure
```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PWPM_Example {
```

### Strings & Arrays
- Single quotes for plain strings: `'hello'`
- Double quotes with variables: `"Hello $name"`
- Concatenation with spaces: `$a . ' ' . $b`

### Control Structures
- Use `! empty()` over `empty()`
- Yoda conditions for constants: `'yes' === $value`
- Strict comparison `===` / `!==` unless type coercion needed
- Check array keys with `isset()` before access

### Type Casting
```php
(int) $value;    // integers
(bool) $value;   // booleans
(string) $value; // strings
absint( $id );   // non-negative integers (WordPress)
```

## Naming Conventions

| Element | Convention | Example |
|---------|------------|---------|
| Files | lowercase-hyphens | `class-pwpm-markdown.php` |
| Main class | `WP_*` | `WP_Posts_Markdown` |
| Helper classes | `PWPM_*` | `PWPM_Exporter` |
| Methods | snake_case | `render_export_page()` |
| Properties | `$snake_case` | `$debug_log`, `$media_map` |
| Variables | `$snake_case` | `$post_id`, `$category_ids` |
| Options | `postsmd_*` | `postsmd_settings` |
| Post meta | `_postsmd_*` | `_postsmd_exported` |
| Transients | `postsmd_*` | `postsmd_stats_cache` |

## Dependency Injection Pattern

Classes receive callbacks via constructor:

```php
public function __construct( $markdown, $logger, $failer, $sync = null ) {
    $this->markdown = $markdown;
    $this->log      = $logger;  // callable
    $this->fail     = $failer;  // callable
    $this->sync     = $sync;
}

private function log_debug( $message ) {
    if ( is_callable( $this->log ) ) {
        call_user_func( $this->log, $message );
    }
}
```

## Error Handling

### WP_Error Pattern
```php
$result = wp_insert_post( $postarr, true );
if ( is_wp_error( $result ) ) {
    $this->log_debug( 'Failed: ' . $result->get_error_message() );
}
```

### Debug Logging
```php
$this->debug_log[] = array(
    'time'    => gmdate( 'H:i:s' ) . ' UTC',
    'status'  => 'success',  // success, error, warning, info
    'message' => $message,
);
```

### Fail Callback
```php
public function fail_and_die( $message ) {
    $this->log_error( 'Failure: ' . wp_strip_all_tags( $message ) );
    wp_die( $message );
}
```

## Security Guidelines

### Nonce Verification
```php
// Generate
wp_nonce_field( 'postsmd', 'postsmd_nonce' );

// Verify
if ( ! isset( $_POST['postsmd_nonce'] ) || ! wp_verify_nonce( $_POST['postsmd_nonce'], 'postsmd' ) ) {
    $this->fail_and_die( esc_html__( 'Security check failed.', 'posts-markdown' ) );
}
```

### AJAX Handlers
```php
add_action( 'wp_ajax_postsmd_action', array( $this, 'ajax_handler' ) );

public function ajax_handler() {
    check_ajax_referer( 'postsmd_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    wp_send_json_success( array( 'data' => $result ) );
}
```

### Input Sanitization
```php
sanitize_text_field( wp_unslash( $_POST['input'] ) );
sanitize_key( $value );
absint( $id );
esc_url_raw( $url );
```

### Output Escaping
```php
esc_html( $text );      // HTML context
esc_attr( $attr );      // HTML attributes
esc_url( $url );        // URLs
esc_html_e( $text );    // Echo translated
```

## WordPress Patterns

### Admin Post Handlers
```php
add_action( 'admin_post_postsmd', array( $this, 'handle_export' ) );
```

### Options & Transients
```php
get_option( 'postsmd_settings', array() );
set_transient( $key, $data, HOUR_IN_SECONDS );
delete_transient( $key );
```

## Front Matter Schema

```yaml
---
title: "Post Title"
date: 2024-12-01
status: "publish"
slug: "post-slug"
permalink: https://example.com/post-slug
id: 123
author: "Author Name"
categories:
  - Category1
tags:
  - tag1
excerpt: "Post excerpt"
featured_image: _images/image.jpg
menu_order: 0
comment_status: "open"
page_template: "template-name.php"
stick_post: "yes"
folder_path: "subfolder/path"
meta_description: "SEO description"
keywords:
  - keyword1
  - keyword2
taxonomy:
  - "taxonomy_name:term_name"
custom_fields:
  - "key:value"
skip_file: "yes"
---
```

## Key Files

| File | Purpose |
|------|---------|
| `posts-markdown.php` | Main plugin entry point |
| `includes/class-wp-posts-markdown.php` | Admin UI, handlers |
| `includes/class-pwpm-exporter.php` | Export posts to Markdown ZIP |
| `includes/class-pwpm-importer.php` | Import Markdown/ZIP to posts |
| `includes/class-pwpm-markdown.php` | Markdown <-> HTML conversion |
| `includes/class-pwpm-media.php` | Image handling |
| `includes/class-pwpm-sync.php` | GitHub/Drive sync |