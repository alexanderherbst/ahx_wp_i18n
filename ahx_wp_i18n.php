<?php
/**
 * Plugin Name: AHX WP i18n
 * Plugin URI:  https://example.com
 * Description: Create/update POT, edit PO and compile MO. WP-CLI support and optional machine-translate.
 * Version:     v1.1.1
 * Author:      Alexander Herbst
 * Text Domain: ahx_wp_i18n
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('AHX_I18N_DIR', plugin_dir_path(__FILE__));
define('AHX_I18N_URL', plugin_dir_url(__FILE__));

require_once AHX_I18N_DIR . 'includes/po_mo.php';
require_once AHX_I18N_DIR . 'includes/admin.php';

function ahx_i18n_load_textdomain() {
    load_plugin_textdomain('ahx_wp_i18n', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'ahx_i18n_load_textdomain');

// WP-CLI commands
if (defined('WP_CLI') && constant('WP_CLI')) {
    require_once AHX_I18N_DIR . 'includes/cli.php';
}

// Activation: ensure plugin languages dir exists
function ahx_i18n_activate() {
    if (!file_exists(WP_CONTENT_DIR . '/languages/ahx_wp_i18n')) {
        wp_mkdir_p(WP_CONTENT_DIR . '/languages/ahx_wp_i18n');
    }
}
register_activation_hook(__FILE__, 'ahx_i18n_activate');

// Capability
function ahx_i18n_add_caps() {
    $role = get_role('administrator');
    if ($role && !$role->has_cap('manage_translations')) {
        $role->add_cap('manage_translations');
    }
}
register_activation_hook(__FILE__, 'ahx_i18n_add_caps');
