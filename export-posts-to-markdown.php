<?php
/**
 * Plugin Name: Export Posts to Markdown
 * Description: Download all published posts as .md in a ZIP and import Markdown back into WordPress.
 * Version: 1.0.0
 * Author: You
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-wp-export-posts-to-markdown.php';

new WP_Export_Posts_To_Markdown();

