# AGENTS.md - Export Posts to Markdown

WordPress plugin for exporting posts to Markdown files and importing them back with YAML front matter support.

## Project Overview

- **Type**: WordPress Admin Plugin (single post type: `post`)
- **Main Class**: `WP_Export_Posts_To_Markdown`
- **Helper Classes**: `WPEM_Exporter`, `WPEM_Importer`, `WPEM_Markdown`, `WPEM_Media`, `WPEM_Sync`
- **Text Domain**: `export-posts-to-markdown`

## Build / Test Commands

This is a WordPress plugin with **no automated testing or linting** configured.

```bash
# PHP Syntax Check (if PHPCS installed)
php -l export-posts-to-markdown.php
php -l includes/*.php

# PHPStan (if installed globally)
phpstan analyse includes/ --level=5

# PHPCS WordPress Coding Standards (if installed)
phpcs --standard=WordPress includes/
```

**Note**: No PHPUnit tests exist. Test manually by activating the plugin in a WordPress environment.

## Code Style Guidelines

### Indentation & Formatting

- **4 spaces** for indentation (no tabs)
- **No** trailing whitespace
- Opening brace on same line: `class Foo {`
- Control structures: single space after keyword: `if ( $var ) {`
- Array syntax: `array()` not `[]` (WordPress standard)

```php
// Correct
function my_function( $arg1, $arg2 ) {
    if ( ! empty( $arg1 ) ) {
        $items = array();
    }
}

// Incorrect
function my_function($arg1,$arg2){
    if(!empty($arg1)){
        $items = [];
    }
}
```

### File Structure

Every PHP file **must** include ABSPATH guard:

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ... code below
```

### Class Naming

- Main plugin class: `WP_Export_Posts_To_Markdown` (prefix: `WP_`)
- Helper classes: `WPEM_*` (prefix: `WPEM_`)
- One class per file, filename matches class name

### Method Naming

- Use **lowerCamelCase**: `render_export_page()`, `handle_import()`
- Private methods: prefix with `_` or keep lowerCamelCase
- Callback methods: descriptive names like `fail_and_die()`

### Property Naming

- Private/protected: `$_property_name` or `$property_name`
- Public: `$camelCase`

### Variable Naming

- Variables: `$snake_case` or `$camelCase` consistently
- Loop variables: `$i`, `$post`, `$item` (clear from context)
- Arrays: plural names or `_list` suffix: `$items`, `$category_ids`

### Strings

- Use single quotes for plain strings: `'hello world'`
- Use double quotes for strings with variables: `"Hello $name"`
- String concatenation: `.` operator with spaces: `$a . ' ' . $b`

```php
$message = 'Processing file: ' . $filename;
$html   = '<p>' . esc_html( $text ) . '</p>';
```

### Arrays

- WordPress standard: `array()` syntax
- Trailing comma on multi-line arrays (optional but preferred)
- Spaces after commas: `array( 'foo', 'bar' )`

```php
$args = array(
    'post_type'      => 'post',
    'posts_per_page' => -1,
    'orderby'        => 'date',
);
```

### Control Structures

```php
// if/else
if ( $condition ) {
    // code
} elseif ( $other ) {
    // code
} else {
    // code
}

// switch
switch ( $value ) {
    case 'foo':
        do_something();
        break;
    default:
        do_default();
}

// for
for ( $i = 0; $i < $count; $i++ ) {
    // code
}

// foreach
foreach ( $items as $item ) {
    // code
}
```

### Conditionals

- Use `! empty()` over `empty()` for clarity
- Yoda conditions for comparisons with constants: `'yes' === $value`
- Use strict comparison `===` and `!==` unless type coercion needed
- Check array keys with `isset()` before access

```php
if ( ! empty( $options['key'] ) ) {
    $value = $options['key'];
}

if ( isset( $meta['title'] ) ) {
    $title = wp_strip_all_tags( $meta['title'] );
}
```

## Security Guidelines

### Sanitization

Always sanitize user input:

```php
sanitize_text_field( wp_unslash( $_POST['input'] ) );
sanitize_key( $value );
sanitize_title( $slug );
absint( $id );           // for IDs/numbers
esc_url_raw( $url );
wp_strip_all_tags( $text );
```

### Escaping

```php
esc_html( $text );           // HTML context
esc_attr( $attr );           // HTML attributes
esc_url( $url );             // URLs in HTML
esc_html_e( $text );         // Echo translated
esc_url_raw( $url );         // URLs in DB/redirects
```

### Nonce Verification

Required for all form submissions and AJAX:

```php
// Generate nonce in form
wp_nonce_field( 'wpexportmd', 'wpexportmd_nonce' );

// Verify on submission
if ( ! isset( $_POST['wpexportmd_nonce'] ) || ! wp_verify_nonce( $_POST['wpexportmd_nonce'], 'wpexportmd' ) ) {
    $this->fail_and_die( esc_html__( 'Security check failed.', 'text-domain' ) );
}
```

### Capability Checks

```php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Unauthorized.', 'text-domain' ) );
}
```

## Error Handling

### WordPress Error Handling

```php
$result = wp_insert_post( $postarr, true );

if ( is_wp_error( $result ) ) {
    $this->log_debug( 'Error: ' . $result->get_error_message() );
    return;
}

// Success
$post_id = $result;
```

### Fail and Die Pattern

Use callback pattern for centralized error handling:

```php
// In constructor/initialization
$this->fail = array( $this, 'fail_and_die' );

// Usage
if ( ! $condition ) {
    $this->fail( esc_html__( 'Error message.', 'text-domain' ) );
}
```

### Debug Logging

Centralized debug log via transient:

```php
private $debug_log = array();
private $debug_transient_key = 'wpexportmd_last_debug';

public function log_debug( $message ) {
    $message = wp_strip_all_tags( (string) $message );
    if ( '' === $message ) {
        return;
    }
    $this->debug_log[] = '[' . gmdate( 'H:i:s' ) . ' UTC] ' . $message;
}
```

## Naming Conventions Summary

| Element | Convention | Example |
|---------|------------|---------|
| Files | lowercase with hyphens | `class-wpem-markdown.php` |
| Classes | WP_Prefix_Class_Name | `WP_Export_Posts_To_Markdown` |
| Methods | lowerCamelCase | `render_export_page()` |
| Properties | $snake_case or $camelCase | `$debug_log`, `$media_map` |
| Variables | $snake_case | `$post_id`, `$category_ids` |
| Constants | UPPER_SNAKE | `MINUTE_IN_SECONDS` |
| Text domain | lowercase | `export-posts-to-markdown` |

## Front Matter Schema

The plugin uses YAML front matter in Markdown files:

```yaml
---
title: "Post Title"
date: 2024-12-01
status: "publish"
slug: "post-slug"
categories:
  - Category1
  - Category2
tags:
  - tag1
  - tag2
featured_image: _images/image.jpg
---
```

## Key File Locations

- Main plugin file: `export-posts-to-markdown.php`
- Admin UI: `includes/class-wp-export-posts-to-markdown.php`
- Export logic: `includes/class-wpem-exporter.php`
- Import logic: `includes/class-wpem-importer.php`
- Markdown conversion: `includes/class-wpem-markdown.php`
- Media handling: `includes/class-wpem-media.php`
- Cloud sync: `includes/class-wpem-sync.php`
