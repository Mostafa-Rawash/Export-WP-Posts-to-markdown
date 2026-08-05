<?php
/**
 * Plugin Name: Posts Markdown
 * Description: Export WordPress posts to Markdown files and import them back with YAML front matter support.
 * Version: 1.1.0
 * Author: You
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-wp-posts-markdown.php';

new WP_Posts_Markdown();
