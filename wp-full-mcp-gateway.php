<?php
/**
 * Plugin Name: WP Full MCP Gateway
 * Description: Portable HTTPS MCP gateway for controlled WordPress content/admin operations. Designed for ChatGPT custom connectors and other remote MCP clients.
 * Version: 0.7.22
 * Author: Djavaweb / Dul
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPFMCP_VERSION', '0.7.22');
define('WPFMCP_PLUGIN_FILE', __FILE__);
define('WPFMCP_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WPFMCP_PLUGIN_DIR . 'includes/class-wp-full-mcp-gateway.php';

add_action('plugins_loaded', static function () {
    WP_Full_MCP_Gateway::instance();
});

register_activation_hook(__FILE__, static function () {
    $opts = get_option('wpfmcp_options', []);
    if (empty($opts['secret'])) {
        $opts['secret'] = wp_generate_password(48, false, false);
    }
    $opts = wp_parse_args($opts, [
        'enabled' => 1,
        'service_user_id' => 0,
        'permission_profile' => 'content_editor',
        'allow_plugin_install' => 0,
        'allow_plugin_update' => 0,
        'allow_plugin_activate' => 0,
        'allow_plugin_deactivate' => 0,
        'allow_plugin_delete' => 0,
        'allow_theme_install' => 0,
        'allow_theme_update' => 0,
        'allow_theme_switch' => 0,
        'allow_media_upload' => 1,
        'approval_required_tools' => '',
        'enabled_tool_groups' => 'core
content
media
plugins
themes
options
users
elementor
woocommerce
approvals
backup
settings
diagnostics
audit',
        'backup_before_tools' => 'wp-install-plugin
wp-activate-plugin
wp-update-plugin
wp-deactivate-plugin
wp-trash-post
wp-option-update
wp-install-theme
wp-update-theme
wp-activate-theme
wc-create-product
wc-update-product
wc-delete-product
wc-update-order-status
wc-add-order-note
wc-enable-native-mcp
wp-import-settings',
        'backup_strategy' => 'wpvivid_fallback_db',
        'backup_guard_require_db_fallback' => 1,
        'backup_prune_keep' => 10,
        'enable_elementor_mcp_bridge' => 1,
        'elementor_mcp_route' => '/mcp/elementor-mcp-server',
        'allowed_plugin_slugs' => '',
        'allowed_theme_slugs' => '',
        'log_limit' => 200,
    ]);
    update_option('wpfmcp_options', $opts, false);
    update_option('wpfmcp_version', WPFMCP_VERSION, false);
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function () {
    flush_rewrite_rules();
});
