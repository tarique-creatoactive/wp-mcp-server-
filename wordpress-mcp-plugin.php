<?php
/**
 * Plugin Name: WordPress MCP Server
 * Plugin URI: https://github.com/tarique-creatoactive/wp-mcp-server-
 * Description: Exposes your WordPress site as an MCP (Model Context Protocol) server so AI clients like Cursor can connect and interact with your content.
 * Version: 1.0.0
 * Author: Tarique Khan
 * License: GPL v2 or later
 * Text Domain: wp-mcp-server
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_MCP_VERSION', '1.0.0');
define('WP_MCP_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WP_MCP_PLUGIN_DIR . 'includes/class-wp-mcp-rest.php';
require_once WP_MCP_PLUGIN_DIR . 'includes/class-wp-mcp-tools.php';
require_once WP_MCP_PLUGIN_DIR . 'includes/class-wp-mcp-admin.php';

function wp_mcp_ensure_token() {
    $token = get_option('wp_mcp_bearer_token', '');
    if (empty($token)) {
        $token = wp_generate_password(48, true, true);
        update_option('wp_mcp_bearer_token', $token);
    }
    return $token;
}

class WP_MCP_Server {
    public static function init() {
        WP_MCP_REST::init();
        WP_MCP_Admin::init();
    }
}
add_action('plugins_loaded', ['WP_MCP_Server', 'init']);

