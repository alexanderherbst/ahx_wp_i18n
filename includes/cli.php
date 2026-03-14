<?php
if (!defined('WP_CLI') || !WP_CLI) return;

class AHX_I18N_CLI {
    public function make_pot($args, $assoc_args) {
        $scan_plugins = isset($assoc_args['plugins']) ? (bool) $assoc_args['plugins'] : true;
        $scan_templates = isset($assoc_args['templates']) ? (bool) $assoc_args['templates'] : true;
        $target_type = isset($assoc_args['target_type']) ? (string) $assoc_args['target_type'] : '';
        $target_slug = isset($assoc_args['target']) ? (string) $assoc_args['target'] : '';
        $text_domain = isset($assoc_args['domain']) ? (string) $assoc_args['domain'] : 'ahx_wp_i18n';
        $output_path = isset($assoc_args['output']) ? (string) $assoc_args['output'] : '';

        $result = ahx_i18n_generate_pot([
            'scan_plugins' => $scan_plugins,
            'scan_templates' => $scan_templates,
            'target_type' => $target_type,
            'target_slug' => $target_slug,
            'text_domain' => $text_domain,
            'output_path' => $output_path,
        ]);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
            return;
        }

        WP_CLI::success(sprintf(
            'POT created: %s (%d entries from %d files)',
            $result['output_path'],
            (int) $result['entry_count'],
            (int) $result['scanned_files']
        ));
    }

    public function compile_mo($args, $assoc_args) {
        $po = isset($args[0]) ? $args[0] : false;
        if (!$po || !file_exists($po)) {
            WP_CLI::error('PO file not found');
            return;
        }
        $mo = preg_replace('/\.po$/', '.mo', $po);
        $res = ahx_i18n_compile_mo($po, $mo);
        if (is_wp_error($res)) {
            WP_CLI::error($res->get_error_message());
        } else {
            WP_CLI::success('Compiled ' . $mo);
        }
    }
}

WP_CLI::add_command('ahx-i18n', 'AHX_I18N_CLI');
