# AGENTS.md - Posts Markdown

WordPress plugin for exporting posts to Markdown files with YAML front matter and importing them back.

## Project Overview

- **Type**: WordPress Admin Plugin (handles `post` post type)
- **Main Class**: `WP_Posts_Markdown`
- **Helper Classes**: `PWPM_Exporter`, `PWPM_Importer`, `PWPM_Markdown`, `PWPM_Media`, `PWPM_Sync`
- **Text Domain**: `posts-markdown`

## Build / Lint / Test Commands

**No automated testing configured.** WordPress plugin tested manually in WP environment.

```bash
# PHP Syntax Check (all files)
find . -name "*.php" -exec php -l {} \;

# PHPStan (if installed globally)
phpstan analyse includes/ --level=5

# PHPCS WordPress Coding Standards
phpcs --standard=WordPress includes/

# Single file check
php -l includes/class-pwpm-exporter.php
```

## Code Style Guidelines

### Indentation & Formatting
- **4 spaces** (no tabs)
- Opening brace on same line: `class Foo {`
- Single space after keywords: `if ( $var ) {`
- WordPress array syntax: `array()` not `[]`

### File Structure (ABSPATH Guard Required)
```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PWPM_Example {
```

### Class Naming
| Type | Pattern | Example |
|------|---------|---------|
| Main class | `WP_*` | `WP_Posts_Markdown` |
| Helper classes | `PWPM_*` | `PWPM_Exporter` |
| Filename | lowercase-hyphenated | `class-pwpm-exporter.php` |

### Method Naming
- Public: `lowerCamelCase` (`render_export_page()`)
- Private: `_lowerCamelCase` or `lowerCamelCase`
- Callbacks: descriptive names (`fail_and_die()`)

### Property Naming
- Private/protected: `$_property_name` or `$property_name`
- Public: `$camelCase`

### Variable Naming
- `$snake_case` or `$camelCase` (be consistent)
- Loop: `$i`, `$post`, `$item`
- Arrays: plural or `_list` suffix (`$items`, `$category_ids`)

### Strings
- Single quotes for plain strings: `'hello'`
- Double quotes for strings with variables: `"Hello $name"`
- Concatenation with spaces: `$a . ' ' . $b`

```php
$message = 'Processing file: ' . $filename;
```

### Arrays
- Use `array()` syntax (WordPress standard)
- Trailing comma on multi-line arrays
- Spaces after commas

```php
$args = array(
    'post_type'      => 'post',
    'posts_per_page' => -1,
);
```

### Control Structures
```php
if ( $condition ) {
    // code
} elseif ( $other ) {
    // code
} else {
    // code
}

foreach ( $items as $item ) {
    // code
}
```

### Conditionals
- Use `! empty()` over `empty()`
- Yoda conditions for constants: `'yes' === $value`
- Strict comparison `===` / `!==` unless type coercion needed
- Check array keys with `isset()` before access

```php
if ( ! empty( $options['key'] ) ) {
    $value = $options['key'];
}
```

## Security Guidelines

### Sanitization
```php
sanitize_text_field( wp_unslash( $_POST['input'] ) );
sanitize_key( $value );
sanitize_title( $slug );
absint( $id );
esc_url_raw( $url );
```

### Escaping
```php
esc_html( $text );      // HTML context
esc_attr( $attr );      // HTML attributes
esc_url( $url );        // URLs in HTML
esc_html_e( $text );    // Echo translated
```

### Nonce Verification (Required for all forms/AJAX)
```php
// Generate
wp_nonce_field( 'postsmd', 'postsmd_nonce' );

// Verify
if ( ! isset( $_POST['postsmd_nonce'] ) || ! wp_verify_nonce( $_POST['postsmd_nonce'], 'postsmd' ) ) {
    $this->fail_and_die( esc_html__( 'Security check failed.', 'posts-markdown' ) );
}
```

### Capability Checks
```php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Unauthorized.', 'posts-markdown' ) );
}
```

## Error Handling

### Fail Callback Pattern
```php
// Constructor sets callback
$this->fail = array( $this, 'fail_and_die' );

// Usage
if ( ! $condition ) {
    $this->fail( esc_html__( 'Error message.', 'posts-markdown' ) );
}
```

### Debug Logging
```php
private $debug_log = array();

public function log_debug( $message ) {
    $message = wp_strip_all_tags( (string) $message );
    if ( '' === $message ) {
        return;
    }
    $this->debug_log[] = '[' . gmdate( 'H:i:s' ) . ' UTC] ' . $message;
}
```

## Front Matter Schema
```yaml
---
title: "Post Title"
date: 2024-12-01
status: "publish"
slug: "post-slug"
categories:
  - Category1
tags:
  - tag1
featured_image: _images/image.jpg
---
```

## Key Files
| File | Purpose |
|------|---------|
| `posts-markdown.php` | Main plugin file (entry point) |
| `includes/class-wp-posts-markdown.php` | Admin UI, menu, handlers |
| `includes/class-pwpm-exporter.php` | Export posts to Markdown ZIP |
| `includes/class-pwpm-importer.php` | Import Markdown/ZIP to posts |
| `includes/class-pwpm-markdown.php` | Markdown <-> HTML conversion |
| `includes/class-pwpm-media.php` | Image handling in ZIPs |
| `includes/class-pwpm-sync.php` | GitHub/Drive cloud sync |

## Naming Conventions Summary
| Element | Convention | Example |
|---------|------------|---------|
| Files | lowercase-hyphens | `class-pwpm-markdown.php` |
| Classes | WP_Prefix_Class_Name | `WP_Posts_Markdown` |
| Methods | lowerCamelCase | `render_export_page()` |
| Properties | $snake_case | `$debug_log`, `$media_map` |
| Variables | $snake_case | `$post_id`, `$category_ids` |
| Text domain | lowercase | `posts-markdown` |
| Options | prefix with `postsmd_` | `postsmd_settings` |
| Post meta | prefix with `_postsmd_` | `_postsmd_exported` |
