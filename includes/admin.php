<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'ahx_i18n_admin_menu');

function ahx_i18n_admin_menu() {
    add_management_page(
        __('AHX Translations', 'ahx_wp_i18n'),
        __('AHX Translations', 'ahx_wp_i18n'),
        'manage_options',
        'ahx-i18n',
        'ahx_i18n_admin_page'
    );
}

function ahx_i18n_find_github_repo_row_by_dir($dir_path) {
    global $wpdb;
    $table = $wpdb->prefix . 'ahx_wp_github';
    $dir_path = wp_normalize_path((string) $dir_path);
    if ($dir_path === '') {
        return null;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT id, dir_path, type, name FROM $table WHERE dir_path = %s", $dir_path));
    if ($row) {
        return $row;
    }

    $rows = $wpdb->get_results("SELECT id, dir_path, type, name FROM $table");
    if (!is_array($rows)) {
        return null;
    }

    $normalize = static function ($path) {
        $path = wp_normalize_path((string) $path);
        $path = rtrim($path, '/');
        if (stripos(PHP_OS, 'WIN') === 0) {
            $path = strtolower($path);
        }
        return $path;
    };

    $wanted = $normalize($dir_path);
    foreach ($rows as $candidate) {
        if ($normalize($candidate->dir_path) === $wanted) {
            return $candidate;
        }
    }

    return null;
}

function ahx_i18n_find_language_directories($roots) {
    $roots = is_array($roots) ? $roots : [];
    $dirs = [];
    $skip = ['.git', 'node_modules', 'vendor', 'dist', 'build'];

    foreach ($roots as $root) {
        $root = (string) $root;
        if ($root === '' || !is_dir($root)) {
            continue;
        }

        $root = wp_normalize_path($root);
        $dirs[$root] = $root;

        $iter = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function ($current) use ($skip) {
                    if (!$current->isDir()) {
                        return true;
                    }
                    return !in_array($current->getFilename(), $skip, true);
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iter as $entry) {
            if (!$entry->isDir()) {
                continue;
            }
            if (strtolower($entry->getFilename()) === 'languages') {
                $path = wp_normalize_path($entry->getPathname());
                $dirs[$path] = $path;
            }
        }
    }

    ksort($dirs);
    return array_values($dirs);
}

function ahx_i18n_collect_translation_files($roots, $extensions) {
    $extensions = is_array($extensions) ? $extensions : [];
    $extensions = array_values(array_unique(array_map(function ($ext) {
        return strtolower(trim((string) $ext));
    }, $extensions)));

    $lang_dirs = ahx_i18n_find_language_directories($roots);
    $files = [];
    foreach ($lang_dirs as $dir) {
        foreach ($extensions as $ext) {
            if ($ext === '') {
                continue;
            }
            $matches = glob(trailingslashit($dir) . '*.' . $ext);
            if (!$matches) {
                continue;
            }
            foreach ($matches as $m) {
                if (is_file($m)) {
                    $p = wp_normalize_path($m);
                    $files[$p] = $p;
                }
            }
        }
    }

    ksort($files);
    return array_values($files);
}

function ahx_i18n_get_standard_locale_options() {
    $locales = [
        'af', 'ar', 'az', 'be_BY', 'bg_BG', 'bn_BD', 'bs_BA', 'ca', 'cs_CZ', 'cy',
        'da_DK', 'de_DE', 'de_CH', 'de_AT', 'el', 'en_US', 'en_GB', 'en_AU', 'en_CA',
        'en_NZ', 'es_ES', 'es_MX', 'et', 'eu', 'fa_IR', 'fi', 'fr_FR', 'fr_CA', 'ga',
        'gl_ES', 'he_IL', 'hi_IN', 'hr', 'hu_HU', 'hy', 'id_ID', 'is_IS', 'it_IT',
        'ja', 'ka_GE', 'kk', 'km', 'ko_KR', 'lt_LT', 'lv', 'mk_MK', 'mn', 'ms_MY',
        'nb_NO', 'nl_NL', 'nn_NO', 'pl_PL', 'pt_PT', 'pt_BR', 'ro_RO', 'ru_RU', 'sk_SK',
        'sl_SI', 'sq', 'sr_RS', 'sv_SE', 'sw', 'ta_IN', 'th', 'tr_TR', 'uk', 'ur',
        'uz_UZ', 'vi', 'zh_CN', 'zh_TW', 'zh_HK'
    ];

    if (function_exists('get_available_languages')) {
        $installed = get_available_languages();
        if (is_array($installed) && !empty($installed)) {
            $locales = array_merge($locales, $installed);
        }
    }

    if (function_exists('wp_get_available_translations')) {
        $translations = wp_get_available_translations();
        if (is_array($translations) && !empty($translations)) {
            $locales = array_merge($locales, array_keys($translations));
        }
    }

    $locales[] = get_locale();
    $locales = array_values(array_unique(array_filter(array_map('trim', $locales))));
    sort($locales, SORT_NATURAL | SORT_FLAG_CASE);
    return $locales;
}

function ahx_i18n_guess_text_domain_from_pot_path($pot_path) {
    $pot_path = (string) $pot_path;
    if ($pot_path === '') {
        return '';
    }

    if (is_file($pot_path) && is_readable($pot_path)) {
        $contents = @file_get_contents($pot_path);
        if ($contents !== false) {
            if (preg_match('/"Project-Id-Version:\s*([^\\n\"]+)/', $contents, $m)) {
                $candidate = trim((string) $m[1]);
                // POT header lines often end with escaped "\\n" inside quotes.
                $candidate = preg_replace('/\\\\n$/', '', $candidate);
                $candidate = trim((string) $candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
    }

    $base = pathinfo($pot_path, PATHINFO_FILENAME);
    return trim((string) $base);
}

function ahx_i18n_guess_text_domain_from_target($target_type, $plugin_target, $template_target, $plugins, $themes) {
    $target_type = (string) $target_type;
    if ($target_type === 'template') {
        if (isset($themes[$template_target])) {
            $candidate = trim((string) $themes[$template_target]->get('TextDomain'));
            return $candidate !== '' ? $candidate : (string) $template_target;
        }
        return (string) $template_target;
    }

    if (isset($plugins[$plugin_target])) {
        $candidate = trim((string) ($plugins[$plugin_target]['TextDomain'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
        $slug = dirname((string) $plugin_target);
        if ($slug !== '' && $slug !== '.') {
            return $slug;
        }
        return pathinfo((string) $plugin_target, PATHINFO_FILENAME);
    }

    return 'ahx_wp_i18n';
}

function ahx_i18n_get_selected_target($plugins, $themes) {
    $target_type = sanitize_key(wp_unslash($_GET['target_type'] ?? ''));
    $target_slug = sanitize_text_field(wp_unslash($_GET['target_slug'] ?? ''));
    if ($target_type === '' || $target_slug === '') {
        return null;
    }

    if ($target_type === 'plugin') {
        if (!isset($plugins[$target_slug])) {
            return new WP_Error('invalid_plugin_target', 'Ausgewaehltes Plugin nicht gefunden.');
        }
        $target_dir = ahx_i18n_resolve_target_directory('plugin', $target_slug);
        if (is_wp_error($target_dir)) {
            return $target_dir;
        }
        $name = !empty($plugins[$target_slug]['Name']) ? (string) $plugins[$target_slug]['Name'] : (string) $target_slug;
        return [
            'type' => 'plugin',
            'slug' => $target_slug,
            'name' => $name,
            'dir' => $target_dir,
        ];
    }

    if ($target_type === 'template') {
        if (!isset($themes[$target_slug])) {
            return new WP_Error('invalid_template_target', 'Ausgewaehltes Theme nicht gefunden.');
        }
        $target_dir = ahx_i18n_resolve_target_directory('template', $target_slug);
        if (is_wp_error($target_dir)) {
            return $target_dir;
        }
        $name = (string) $themes[$target_slug]->get('Name');
        if ($name === '') {
            $name = (string) $target_slug;
        }
        return [
            'type' => 'template',
            'slug' => $target_slug,
            'name' => $name,
            'dir' => $target_dir,
        ];
    }

    return new WP_Error('invalid_target_type', 'Ungueltiger Zieltyp.');
}

function ahx_i18n_render_landing_page($plugins, $themes) {
    $search = trim((string) sanitize_text_field(wp_unslash($_GET['q'] ?? '')));
    $search_lc = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
    $only_active_plugins = !empty($_GET['only_active_plugins']);
    $only_child_themes = !empty($_GET['only_child_themes']);

    $active_plugins = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $network_active = array_keys((array) get_site_option('active_sitewide_plugins', []));
        $active_plugins = array_values(array_unique(array_merge($active_plugins, $network_active)));
    }
    $active_map = array_fill_keys($active_plugins, true);

    echo '<form method="get" style="margin:10px 0 16px 0; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">';
    echo '<input type="hidden" name="page" value="ahx-i18n">';
    echo '<label>' . esc_html__('Suche', 'ahx_wp_i18n') . '<br><input type="text" name="q" class="regular-text" value="' . esc_attr($search) . '" placeholder="Name, Domain, Pfad"></label>';
    echo '<label><input type="checkbox" name="only_active_plugins" value="1" ' . checked($only_active_plugins, true, false) . '> ' . esc_html__('Nur aktivierte Plugins', 'ahx_wp_i18n') . '</label>';
    echo '<label><input type="checkbox" name="only_child_themes" value="1" ' . checked($only_child_themes, true, false) . '> ' . esc_html__('Nur Child-Themes', 'ahx_wp_i18n') . '</label>';
    echo '<button class="button button-primary" type="submit">' . esc_html__('Filtern', 'ahx_wp_i18n') . '</button>';
    echo '<a class="button" href="' . esc_url(admin_url('tools.php?page=ahx-i18n')) . '">' . esc_html__('Zuruecksetzen', 'ahx_wp_i18n') . '</a>';
    echo '</form>';

    $plugin_rows_html = '';
    $plugin_rows = 0;
    foreach ($plugins as $plugin_file => $plugin_data) {
        $name = !empty($plugin_data['Name']) ? (string) $plugin_data['Name'] : (string) $plugin_file;
        $domain = trim((string) ($plugin_data['TextDomain'] ?? ''));
        if ($domain === '') {
            $domain = dirname((string) $plugin_file);
            if ($domain === '.' || $domain === '') {
                $domain = pathinfo((string) $plugin_file, PATHINFO_FILENAME);
            }
        }

        if ($only_active_plugins && empty($active_map[$plugin_file])) {
            continue;
        }

        if ($search_lc !== '') {
            $haystack = $name . ' ' . $domain . ' ' . $plugin_file;
            $haystack_lc = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);
            if (strpos($haystack_lc, $search_lc) === false) {
                continue;
            }
        }

        $detail_url = add_query_arg([
            'page' => 'ahx-i18n',
            'target_type' => 'plugin',
            'target_slug' => $plugin_file,
        ], admin_url('tools.php'));
        $plugin_rows_html .= '<tr>';
        $plugin_rows_html .= '<td>' . esc_html($name) . '</td>';
        $plugin_rows_html .= '<td><code>' . esc_html($domain) . '</code></td>';
        $plugin_rows_html .= '<td><code>' . esc_html($plugin_file) . '</code></td>';
        $plugin_rows_html .= '<td><a class="button button-primary" href="' . esc_url($detail_url) . '">' . esc_html__('Details', 'ahx_wp_i18n') . '</a></td>';
        $plugin_rows_html .= '</tr>';
        $plugin_rows++;
    }

    echo '<h2>' . esc_html__('Plugins', 'ahx_wp_i18n') . ' <span class="description">(' . intval($plugin_rows) . ')</span></h2>';
    echo '<table class="widefat striped fixed" style="margin-bottom:20px;"><thead><tr><th>Name</th><th>Text Domain</th><th>Pfad</th><th style="width:120px;">Aktion</th></tr></thead><tbody>';
    if ($plugin_rows === 0) {
        echo '<tr><td colspan="4">' . esc_html__('Keine Plugins fuer die aktuellen Filter gefunden.', 'ahx_wp_i18n') . '</td></tr>';
    } else {
        echo $plugin_rows_html;
    }
    echo '</tbody></table>';

    $theme_rows_html = '';
    $theme_rows = 0;
    foreach ($themes as $theme_slug => $theme) {
        $name = (string) $theme->get('Name');
        if ($name === '') {
            $name = (string) $theme_slug;
        }
        $domain = trim((string) $theme->get('TextDomain'));
        if ($domain === '') {
            $domain = (string) $theme_slug;
        }

        if ($only_child_themes && !$theme->parent()) {
            continue;
        }

        if ($search_lc !== '') {
            $haystack = $name . ' ' . $domain . ' ' . $theme_slug;
            $haystack_lc = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);
            if (strpos($haystack_lc, $search_lc) === false) {
                continue;
            }
        }

        $detail_url = add_query_arg([
            'page' => 'ahx-i18n',
            'target_type' => 'template',
            'target_slug' => $theme_slug,
        ], admin_url('tools.php'));
        $theme_rows_html .= '<tr>';
        $theme_rows_html .= '<td>' . esc_html($name) . '</td>';
        $theme_rows_html .= '<td><code>' . esc_html($domain) . '</code></td>';
        $theme_rows_html .= '<td><code>' . esc_html($theme_slug) . '</code></td>';
        $theme_rows_html .= '<td><a class="button button-primary" href="' . esc_url($detail_url) . '">' . esc_html__('Details', 'ahx_wp_i18n') . '</a></td>';
        $theme_rows_html .= '</tr>';
        $theme_rows++;
    }

    echo '<h2>' . esc_html__('Themes', 'ahx_wp_i18n') . ' <span class="description">(' . intval($theme_rows) . ')</span></h2>';
    echo '<table class="widefat striped fixed"><thead><tr><th>Name</th><th>Text Domain</th><th>Slug</th><th style="width:120px;">Aktion</th></tr></thead><tbody>';
    if ($theme_rows === 0) {
        echo '<tr><td colspan="4">' . esc_html__('Keine Themes fuer die aktuellen Filter gefunden.', 'ahx_wp_i18n') . '</td></tr>';
    } else {
        echo $theme_rows_html;
    }
    echo '</tbody></table>';
}

function ahx_i18n_extract_locale_from_po_path($po_path) {
    $locale = pathinfo((string) $po_path, PATHINFO_FILENAME);
    $locale = trim((string) $locale);
    if ($locale === '') {
        return 'unknown';
    }
    return $locale;
}

function ahx_i18n_translation_is_filled($entry) {
    $plural = (string) ($entry['msgid_plural'] ?? '');
    $str0 = trim((string) ($entry['msgstr'][0] ?? ''));
    $str1 = trim((string) ($entry['msgstr'][1] ?? ''));

    if ($plural !== '') {
        return $str0 !== '' && $str1 !== '';
    }
    return $str0 !== '';
}

function ahx_i18n_get_maintenance_grade($percent, $translated, $total) {
    if ($total <= 0) {
        return 'Keine Eintraege';
    }
    if ($translated <= 0) {
        return 'Nicht gepflegt';
    }
    if ($percent >= 95) {
        return 'Sehr gut';
    }
    if ($percent >= 80) {
        return 'Gut';
    }
    if ($percent >= 50) {
        return 'Mittel';
    }
    return 'Niedrig';
}

function ahx_i18n_grade_color($grade) {
    switch ($grade) {
        case 'Sehr gut': return '#46b450';
        case 'Gut':      return '#6ab04c';
        case 'Mittel':   return '#f0a500';
        case 'Niedrig':  return '#dc3232';
        case 'Nicht gepflegt': return '#dc3232';
        default:         return '#999999'; // Keine Eintraege
    }
}

function ahx_i18n_build_language_stats($po_files) {
    $po_files = is_array($po_files) ? $po_files : [];
    $rows = [];

    foreach ($po_files as $po_file) {
        $contents = @file_get_contents((string) $po_file);
        if ($contents === false) {
            continue;
        }

        $entries = ahx_i18n_parse_gettext_entries($contents);
        $total = 0;
        $translated = 0;
        foreach ($entries as $entry) {
            $msgid = (string) ($entry['msgid'] ?? '');
            if ($msgid === '') {
                continue;
            }
            $total++;
            if (ahx_i18n_translation_is_filled($entry)) {
                $translated++;
            }
        }

        $percent = ($total > 0) ? round(($translated / $total) * 100, 1) : 0;
        $rows[] = [
            'locale' => ahx_i18n_extract_locale_from_po_path($po_file),
            'path' => (string) $po_file,
            'translated' => $translated,
            'total' => $total,
            'percent' => $percent,
            'grade' => ahx_i18n_get_maintenance_grade($percent, $translated, $total),
            'has_mo' => is_file(preg_replace('/\.po$/', '.mo', (string) $po_file)),
        ];
    }

    usort($rows, function ($a, $b) {
        return strcmp((string) $a['locale'], (string) $b['locale']);
    });

    return [
        'language_count' => count($rows),
        'rows' => $rows,
    ];
}

function ahx_i18n_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung', 'ahx_wp_i18n'));
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $pot_target_type = 'plugin';
    $pot_plugin_target = '';
    $pot_template_target = '';
    $pot_text_domain = 'ahx_wp_i18n';
    $plain_candidates = [];
    $post_convert_commit_notice = '';
    $po_editor_data = null;
    $po_editor_path = '';
    $po_editor_search = '';
    $po_create_pot_path = '';
    $po_create_locale = 'de_DE';
    $locale_options = ahx_i18n_get_standard_locale_options();

    $plugins = get_plugins();
    $themes = wp_get_themes();

    echo '<div class="wrap"><h1>' . esc_html__('AHX i18n', 'ahx_wp_i18n') . '</h1>';

    $selected_target = ahx_i18n_get_selected_target($plugins, $themes);
    if ($selected_target === null) {
        ahx_i18n_render_landing_page($plugins, $themes);
        echo '</div>';
        return;
    }
    if (is_wp_error($selected_target)) {
        echo '<div class="notice notice-error"><p>' . esc_html($selected_target->get_error_message()) . '</p></div>';
        ahx_i18n_render_landing_page($plugins, $themes);
        echo '</div>';
        return;
    }

    $pot_target_type = (string) $selected_target['type'];
    if ($pot_target_type === 'plugin') {
        $pot_plugin_target = (string) $selected_target['slug'];
    } else {
        $pot_template_target = (string) $selected_target['slug'];
    }

    // Initial default follows selected source target.
    $pot_text_domain = ahx_i18n_guess_text_domain_from_target(
        $pot_target_type,
        $pot_plugin_target,
        $pot_template_target,
        $plugins,
        $themes
    );

    // Discover translation files from selected target only.
    $translation_roots = [
        (string) $selected_target['dir'],
    ];

    echo '<p><strong>' . esc_html__('Ausgewaehlt:', 'ahx_wp_i18n') . '</strong> ' . esc_html($selected_target['name']) . ' (' . esc_html($pot_target_type) . ')</p>';

    // Handle actions
    if (isset($_POST['ahx_i18n_compile']) && check_admin_referer('ahx_i18n_compile', 'ahx_i18n_compile_nonce')) {
        $po = sanitize_text_field(wp_unslash($_POST['ahx_i18n_po_path']));
        $mo = preg_replace('/\.po$/', '.mo', $po);
        $result = ahx_i18n_compile_mo($po, $mo);
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>' . esc_html__('MO erzeugt.', 'ahx_wp_i18n') . '</p></div>';
        }
    }

    if (isset($_POST['ahx_i18n_make_pot']) && check_admin_referer('ahx_i18n_make_pot', 'ahx_i18n_make_pot_nonce')) {
        $pot_target_type = sanitize_key(wp_unslash($_POST['ahx_i18n_target_type']));
        $pot_plugin_target = sanitize_text_field(wp_unslash($_POST['ahx_i18n_plugin_target']));
        $pot_template_target = sanitize_text_field(wp_unslash($_POST['ahx_i18n_template_target']));
        $pot_text_domain = sanitize_text_field(wp_unslash($_POST['ahx_i18n_text_domain']));

        $target_slug = ($pot_target_type === 'template') ? $pot_template_target : $pot_plugin_target;

        // If no domain was entered manually, fall back to the selected target's text domain.
        if ($pot_text_domain === '') {
            if ($pot_target_type === 'template' && isset($themes[$pot_template_target])) {
                $candidate_domain = trim((string) $themes[$pot_template_target]->get('TextDomain'));
                $pot_text_domain = ($candidate_domain !== '') ? $candidate_domain : $pot_template_target;
            } elseif ($pot_target_type === 'plugin' && isset($plugins[$pot_plugin_target])) {
                $candidate_domain = trim((string) $plugins[$pot_plugin_target]['TextDomain']);
                if ($candidate_domain === '') {
                    $candidate_domain = dirname($pot_plugin_target);
                }
                $pot_text_domain = ($candidate_domain !== '' && $candidate_domain !== '.') ? $candidate_domain : 'ahx_wp_i18n';
            }
        }

        $result = ahx_i18n_generate_pot([
            'target_type' => $pot_target_type,
            'target_slug' => $target_slug,
            'text_domain' => $pot_text_domain,
        ]);

        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            $entry_count = (int) $result['entry_count'];
            $file_count = (int) $result['scanned_files'];
            $entry_label = sprintf(
                _n('%d Eintrag', '%d Eintraege', $entry_count, 'ahx_wp_i18n'),
                $entry_count
            );
            $file_label = sprintf(
                _n('%d Datei', '%d Dateien', $file_count, 'ahx_wp_i18n'),
                $file_count
            );
            $message = sprintf(
                /* translators: 1: POT path, 2: entry label with count, 3: file label with count */
                __('POT erzeugt: %1$s (%2$s aus %3$s).', 'ahx_wp_i18n'),
                $result['output_path'],
                $entry_label,
                $file_label
            );
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
    }

    if (isset($_POST['ahx_i18n_create_po']) && check_admin_referer('ahx_i18n_create_po', 'ahx_i18n_create_po_nonce')) {
        $po_create_pot_path = sanitize_text_field(wp_unslash($_POST['ahx_i18n_create_pot_path'] ?? ''));
        $po_create_locale = sanitize_text_field(wp_unslash($_POST['ahx_i18n_create_locale'] ?? 'de_DE'));
        $pot_text_domain = sanitize_text_field(wp_unslash($_POST['ahx_i18n_create_text_domain'] ?? $pot_text_domain));
        if ($pot_text_domain === '') {
            $pot_text_domain = ahx_i18n_guess_text_domain_from_pot_path($po_create_pot_path);
        }

        $create_result = ahx_i18n_create_po_from_pot($po_create_pot_path, $po_create_locale, $pot_text_domain);
        if (is_wp_error($create_result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($create_result->get_error_message()) . '</p></div>';
        } else {
            $po_editor_path = (string) $create_result['po_path'];
            if (!empty($create_result['merged'])) {
                $message = sprintf(
                    /* translators: 1: PO path, 2: total entries, 3: new entries */
                    __('PO aktualisiert: %1$s (%2$d Eintraege gesamt, %3$d neu hinzugefuegt).', 'ahx_wp_i18n'),
                    $po_editor_path,
                    (int) $create_result['entry_count'],
                    (int) $create_result['new_count']
                );
            } else {
                $message = sprintf(
                    /* translators: 1: PO path, 2: number of entries */
                    __('PO erzeugt: %1$s (%2$d Eintraege).', 'ahx_wp_i18n'),
                    $po_editor_path,
                    (int) $create_result['entry_count']
                );
            }
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
    }

    if (isset($_POST['ahx_i18n_open_po'])) {
        $open_nonce = sanitize_text_field(wp_unslash($_POST['ahx_i18n_open_po_nonce'] ?? ''));
        if ($open_nonce === '' || !wp_verify_nonce($open_nonce, 'ahx_i18n_open_po')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Ungueltiger Nonce.', 'ahx_wp_i18n') . '</p></div>';
        } else {
            $po_editor_path = sanitize_text_field(wp_unslash($_POST['ahx_i18n_open_po_path'] ?? $_POST['ahx_i18n_po_path'] ?? ''));
            $po_editor_search = sanitize_text_field(wp_unslash($_POST['ahx_i18n_po_search'] ?? ''));
        }
    }

    if (isset($_POST['ahx_i18n_save_po']) && check_admin_referer('ahx_i18n_save_po', 'ahx_i18n_save_po_nonce')) {
        $po_editor_path = sanitize_text_field(wp_unslash($_POST['ahx_i18n_po_path'] ?? ''));
        $po_editor_search = sanitize_text_field(wp_unslash($_POST['ahx_i18n_po_search'] ?? ''));
        $po_locale = sanitize_text_field(wp_unslash($_POST['ahx_i18n_po_locale'] ?? 'de_DE'));
        $po_domain = sanitize_text_field(wp_unslash($_POST['ahx_i18n_po_domain'] ?? $pot_text_domain));
        $translations0 = isset($_POST['ahx_i18n_msgstr_0']) ? (array) wp_unslash($_POST['ahx_i18n_msgstr_0']) : [];
        $translations1 = isset($_POST['ahx_i18n_msgstr_1']) ? (array) wp_unslash($_POST['ahx_i18n_msgstr_1']) : [];

        $save_result = ahx_i18n_save_po_translations($po_editor_path, $po_domain, $po_locale, $translations0, $translations1);
        if (is_wp_error($save_result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($save_result->get_error_message()) . '</p></div>';
        } else {
            $message = sprintf(
                /* translators: 1: number of saved entries, 2: PO path */
                __('PO gespeichert: %1$d Eintraege in %2$s', 'ahx_wp_i18n'),
                (int) $save_result['updated'],
                (string) $save_result['po_path']
            );
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
    }

    if (isset($_POST['ahx_i18n_scan_plain_text']) && check_admin_referer('ahx_i18n_scan_plain_text', 'ahx_i18n_scan_plain_text_nonce')) {
        $pot_target_type = sanitize_key(wp_unslash($_POST['ahx_i18n_target_type']));
        $pot_plugin_target = sanitize_text_field(wp_unslash($_POST['ahx_i18n_plugin_target']));
        $pot_template_target = sanitize_text_field(wp_unslash($_POST['ahx_i18n_template_target']));
        $pot_text_domain = sanitize_text_field(wp_unslash($_POST['ahx_i18n_text_domain']));

        $target_slug = ($pot_target_type === 'template') ? $pot_template_target : $pot_plugin_target;
        $scan_result = ahx_i18n_scan_plain_text_candidates($pot_target_type, $target_slug, $pot_text_domain, 250);

        if (is_wp_error($scan_result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($scan_result->get_error_message()) . '</p></div>';
        } else {
            $plain_candidates = isset($scan_result['candidates']) && is_array($scan_result['candidates']) ? $scan_result['candidates'] : [];
            $scan_message = sprintf(
                /* translators: %d: number of found plain text fragments */
                __('Gefundene Textfragmente: %d', 'ahx_wp_i18n'),
                count($plain_candidates)
            );
            echo '<div class="notice notice-info"><p>' . esc_html($scan_message) . '</p></div>';
        }
    }

    if (isset($_POST['ahx_i18n_convert_plain_text']) && check_admin_referer('ahx_i18n_convert_plain_text', 'ahx_i18n_convert_plain_text_nonce')) {
        $pot_target_type = sanitize_key(wp_unslash($_POST['ahx_i18n_target_type']));
        $pot_plugin_target = sanitize_text_field(wp_unslash($_POST['ahx_i18n_plugin_target']));
        $pot_template_target = sanitize_text_field(wp_unslash($_POST['ahx_i18n_template_target']));
        $pot_text_domain = sanitize_text_field(wp_unslash($_POST['ahx_i18n_text_domain']));
        $selected = isset($_POST['ahx_i18n_plain_candidates']) ? (array) wp_unslash($_POST['ahx_i18n_plain_candidates']) : [];

        $target_slug = ($pot_target_type === 'template') ? $pot_template_target : $pot_plugin_target;
        $convert_result = ahx_i18n_apply_plain_text_conversions($pot_target_type, $target_slug, $selected);

        if (is_wp_error($convert_result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($convert_result->get_error_message()) . '</p></div>';
        } else {
            $convert_message = sprintf(
                /* translators: 1: applied replacements, 2: updated files, 3: skipped replacements */
                __('Konvertiert: %1$d, Dateien aktualisiert: %2$d, Uebersprungen: %3$d', 'ahx_wp_i18n'),
                (int) $convert_result['applied'],
                (int) $convert_result['updated_files'],
                (int) $convert_result['skipped']
            );
            echo '<div class="notice notice-success"><p>' . esc_html($convert_message) . '</p></div>';

            if ((int) $convert_result['applied'] > 0) {
                $pot_result = ahx_i18n_generate_pot([
                    'target_type' => $pot_target_type,
                    'target_slug' => $target_slug,
                    'text_domain' => $pot_text_domain,
                ]);
                if (is_wp_error($pot_result)) {
                    echo '<div class="notice notice-error"><p>' . esc_html($pot_result->get_error_message()) . '</p></div>';
                } else {
                    $entry_count = (int) $pot_result['entry_count'];
                    $file_count = (int) $pot_result['scanned_files'];
                    $entry_label = sprintf(
                        _n('%d Eintrag', '%d Eintraege', $entry_count, 'ahx_wp_i18n'),
                        $entry_count
                    );
                    $file_label = sprintf(
                        _n('%d Datei', '%d Dateien', $file_count, 'ahx_wp_i18n'),
                        $file_count
                    );
                    $message = sprintf(
                        /* translators: 1: POT path, 2: entry label with count, 3: file label with count */
                        __('POT aktualisiert: %1$s (%2$s aus %3$s).', 'ahx_wp_i18n'),
                        $pot_result['output_path'],
                        $entry_label,
                        $file_label
                    );
                    echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
                }

                $github_plugin_file = WP_PLUGIN_DIR . '/ahx_wp_github/ahx_wp_github.php';
                if (file_exists($github_plugin_file)) {
                    $target_dir = ahx_i18n_resolve_target_directory($pot_target_type, $target_slug);
                    if (!is_wp_error($target_dir)) {
                        $repo_row = ahx_i18n_find_github_repo_row_by_dir($target_dir);
                        if ($repo_row && !empty($repo_row->dir_path)) {
                            $repo_dir_for_url = wp_normalize_path((string) $repo_row->dir_path);
                            $commit_url = add_query_arg([
                                'page' => 'ahx-wp-github',
                                'repo_changes' => '1',
                                'dir' => $repo_dir_for_url,
                                'prefill_commit_message' => 'i18n-Korrekturen',
                                'prefill_version_bump' => 'patch',
                            ], admin_url('admin.php'));

                            $post_convert_commit_notice = '<div class="notice notice-warning"><p>'
                                . esc_html__('Es wurden i18n-Korrekturen übernommen. Bitte einen Commit der Änderungen durchführen.', 'ahx_wp_i18n')
                                . ' <a class="button button-primary" href="' . esc_url($commit_url) . '" target="_blank" rel="noopener noreferrer">'
                                . esc_html__('Zur Commit-Seite', 'ahx_wp_i18n')
                                . '</a></p></div>';
                        }
                    }
                }
            }
        }
    }

    if ($post_convert_commit_notice !== '') {
        echo $post_convert_commit_notice;
    }

    // Scan for PO/POT files
    $po_files = ahx_i18n_collect_translation_files($translation_roots, ['po']);
    $pot_files = ahx_i18n_collect_translation_files($translation_roots, ['pot']);
    $language_stats = ahx_i18n_build_language_stats($po_files);

    if ($po_editor_path !== '') {
        $po_editor_data = ahx_i18n_get_po_editor_data($po_editor_path, $po_editor_search, 300);
        if (is_wp_error($po_editor_data)) {
            echo '<div class="notice notice-error"><p>' . esc_html($po_editor_data->get_error_message()) . '</p></div>';
            $po_editor_data = null;
        }
    }

    echo '<h2>' . esc_html__('Sprachstatus', 'ahx_wp_i18n') . '</h2>';
    echo '<p><strong>' . esc_html__('Erfasste Sprachen:', 'ahx_wp_i18n') . '</strong> ' . intval($language_stats['language_count']) . '</p>';
    if (!empty($language_stats['rows'])) {
        echo '<table class="widefat striped fixed" style="margin-bottom:20px;"><thead><tr><th style="width:120px;">Sprache</th><th style="width:220px;">Pflegegrad</th><th style="width:180px;">Uebersetzung</th><th style="width:80px;">MO</th><th>Datei</th></tr></thead><tbody>';
        foreach ($language_stats['rows'] as $row) {
            $coverage = intval($row['translated']) . '/' . intval($row['total']) . ' (' . esc_html((string) $row['percent']) . '%)';
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $row['locale']) . '</code></td>';
            $grade_color = ahx_i18n_grade_color((string) $row['grade']);
            echo '<td><strong style="color:' . esc_attr($grade_color) . '">' . esc_html((string) $row['grade']) . '</strong></td>';
            echo '<td>' . $coverage . '</td>';
            echo '<td>' . ($row['has_mo'] ? esc_html__('Ja', 'ahx_wp_i18n') : esc_html__('Nein', 'ahx_wp_i18n')) . '</td>';
            echo '<td><code>' . esc_html((string) $row['path']) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__('Es wurden noch keine PO-Dateien fuer dieses Ziel gefunden.', 'ahx_wp_i18n') . '</p>';
    }

    echo '<h2>' . esc_html__('POT-Datei erzeugen', 'ahx_wp_i18n') . '</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    echo wp_nonce_field('ahx_i18n_make_pot', 'ahx_i18n_make_pot_nonce', true, false);
    echo '<input type="hidden" name="ahx_i18n_target_type" value="' . esc_attr($pot_target_type) . '">';
    echo '<input type="hidden" name="ahx_i18n_plugin_target" value="' . esc_attr($pot_plugin_target) . '">';
    echo '<input type="hidden" name="ahx_i18n_template_target" value="' . esc_attr($pot_template_target) . '">';
    echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">';
    echo '<span class="description" style="padding-bottom:6px;">' . esc_html__('Ziel ist fest auf den ausgewaehlten Eintrag gesetzt.', 'ahx_wp_i18n') . '</span>';
    echo '<label style="min-width:220px;">' . esc_html__('Text Domain', 'ahx_wp_i18n') . '<br><input id="ahx_i18n_text_domain" type="text" name="ahx_i18n_text_domain" class="regular-text" value="' . esc_attr($pot_text_domain) . '"></label>';

    echo '<button class="button" type="submit" name="ahx_i18n_scan_plain_text" value="1" style="height:32px;">' . esc_html__('Textfragmente scannen', 'ahx_wp_i18n') . '</button>';
    echo '<button class="button button-primary" type="submit" name="ahx_i18n_make_pot" value="1" style="height:32px;">' . esc_html__('POT erzeugen', 'ahx_wp_i18n') . '</button>';
    echo '</div>';
    echo wp_nonce_field('ahx_i18n_scan_plain_text', 'ahx_i18n_scan_plain_text_nonce', true, false);
    echo '<p class="description">' . esc_html__('Die POT-Datei wird automatisch unter languages/<text-domain>.pot im gewaehlten Plugin oder Theme gespeichert.', 'ahx_wp_i18n') . '</p>';
    echo '<p class="description" style="margin-top:-6px;">' . esc_html__('Erkannt aus Header:', 'ahx_wp_i18n') . ' <code>' . esc_html($pot_text_domain) . '</code></p>';
    echo '</form>';

    if (!empty($plain_candidates)) {
        echo '<h3>' . esc_html__('Gefundene normale Textfragmente', 'ahx_wp_i18n') . '</h3>';
        echo '<form method="post" style="margin-bottom:20px;">';
        echo wp_nonce_field('ahx_i18n_convert_plain_text', 'ahx_i18n_convert_plain_text_nonce', true, false);
        echo '<input type="hidden" name="ahx_i18n_target_type" value="' . esc_attr($pot_target_type) . '">';
        echo '<input type="hidden" name="ahx_i18n_plugin_target" value="' . esc_attr($pot_plugin_target) . '">';
        echo '<input type="hidden" name="ahx_i18n_template_target" value="' . esc_attr($pot_template_target) . '">';
        echo '<input type="hidden" name="ahx_i18n_text_domain" value="' . esc_attr($pot_text_domain) . '">';
        echo '<table class="widefat fixed striped"><thead><tr><th style="width:40px;">' . esc_html__('Auswahl', 'ahx_wp_i18n') . '</th><th style="width:70px;">' . esc_html__('Typ', 'ahx_wp_i18n') . '</th><th style="width:320px;">' . esc_html__('Datei', 'ahx_wp_i18n') . '</th><th>' . esc_html__('Textfragment', 'ahx_wp_i18n') . '</th></tr></thead><tbody>';
        foreach ($plain_candidates as $candidate) {
            $loc = $candidate['file'] . ':' . (int) $candidate['line'];
            echo '<tr>';
            echo '<td><input type="checkbox" name="ahx_i18n_plain_candidates[]" value="' . esc_attr($candidate['id']) . '"></td>';
            echo '<td>' . esc_html(strtoupper((string) $candidate['kind'])) . '</td>';
            echo '<td><code>' . esc_html($loc) . '</code></td>';
            echo '<td>' . esc_html((string) $candidate['preview']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button class="button button-primary" type="submit" name="ahx_i18n_convert_plain_text" value="1">' . esc_html__('Ausgewaehlte konvertieren und POT aktualisieren', 'ahx_wp_i18n') . '</button></p>';
        echo '</form>';
    }

    echo '<h2>' . esc_html__('PO-Datei erstellen', 'ahx_wp_i18n') . '</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    echo wp_nonce_field('ahx_i18n_create_po', 'ahx_i18n_create_po_nonce', true, false);
    echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">';
    echo '<label style="min-width:420px;">POT<br><select id="ahx_i18n_create_pot_path" name="ahx_i18n_create_pot_path" class="regular-text" style="width:420px;">';
    foreach ($pot_files as $pot_file) {
        $selected = ($po_create_pot_path === '' && basename($pot_file) === ($pot_text_domain . '.pot')) || ($po_create_pot_path === $pot_file);
        $pot_domain = ahx_i18n_guess_text_domain_from_pot_path($pot_file);
        echo '<option value="' . esc_attr($pot_file) . '" data-text-domain="' . esc_attr($pot_domain) . '" ' . selected($selected, true, false) . '>' . esc_html($pot_file) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Locale<br><select name="ahx_i18n_create_locale" class="regular-text">';
    foreach ($locale_options as $locale_option) {
        echo '<option value="' . esc_attr($locale_option) . '" ' . selected($po_create_locale, $locale_option, false) . '>' . esc_html($locale_option) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Text Domain<br><input id="ahx_i18n_create_text_domain" type="text" name="ahx_i18n_create_text_domain" class="regular-text" value="' . esc_attr($pot_text_domain) . '"></label>';
    echo '<button class="button button-primary" type="submit" name="ahx_i18n_create_po" value="1" style="height:32px;">' . esc_html__('PO aus POT erstellen', 'ahx_wp_i18n') . '</button>';
    echo '</div>';
    if (empty($pot_files)) {
        echo '<p class="description">' . esc_html__('Keine POT-Datei gefunden. Bitte zuerst eine POT-Datei erzeugen.', 'ahx_wp_i18n') . '</p>';
    }
    echo '<script>(function(){var pot=document.getElementById("ahx_i18n_create_pot_path");var domain=document.getElementById("ahx_i18n_create_text_domain");if(!pot||!domain){return;}function selectedDomain(){if(pot.selectedIndex<0){return "";}var opt=pot.options[pot.selectedIndex];return opt&&opt.dataset?opt.dataset.textDomain||"":"";}function sync(){var d=selectedDomain();if(d){domain.value=d;}}pot.addEventListener("change",sync);})();</script>';
    echo '</form>';

    if (is_array($po_editor_data) && !empty($po_editor_data['entries'])) {
        $editor_entries = $po_editor_data['entries'];
        $editor_path = (string) $po_editor_data['po_path'];
        $editor_locale = pathinfo($editor_path, PATHINFO_FILENAME);
        if ($editor_locale === '') {
            $editor_locale = 'de_DE';
        }
        echo '<h2>' . esc_html__('PO bearbeiten', 'ahx_wp_i18n') . '</h2>';
        echo '<p><code>' . esc_html($editor_path) . '</code></p>';
        echo '<form method="post">';
        echo wp_nonce_field('ahx_i18n_save_po', 'ahx_i18n_save_po_nonce', true, false);
        echo wp_nonce_field('ahx_i18n_open_po', 'ahx_i18n_open_po_nonce', true, false);
        echo '<input type="hidden" name="ahx_i18n_po_path" value="' . esc_attr($editor_path) . '">';
        echo '<input type="hidden" name="ahx_i18n_open_po_path" value="' . esc_attr($editor_path) . '">';
        echo '<input type="hidden" name="ahx_i18n_po_locale" value="' . esc_attr($editor_locale) . '">';
        echo '<input type="hidden" name="ahx_i18n_po_domain" value="' . esc_attr($pot_text_domain) . '">';
        echo '<p><label>' . esc_html__('Suche', 'ahx_wp_i18n') . ' <input type="text" name="ahx_i18n_po_search" value="' . esc_attr($po_editor_search) . '" class="regular-text"></label> ';
        echo '<button class="button" type="submit" name="ahx_i18n_open_po" value="1">' . esc_html__('Neu laden', 'ahx_wp_i18n') . '</button></p>';
        echo '<table class="widefat striped fixed"><thead><tr><th style="width:45%;">Original</th><th style="width:55%;">Uebersetzung</th></tr></thead><tbody>';
        foreach ($editor_entries as $item) {
            echo '<tr>';
            echo '<td>';
            if ($item['context'] !== '') {
                echo '<p><strong>' . esc_html__('Kontext:', 'ahx_wp_i18n') . '</strong> ' . esc_html($item['context']) . '</p>';
            }
            echo '<p><code>' . esc_html($item['msgid']) . '</code></p>';
            if ($item['msgid_plural'] !== '') {
                echo '<p><strong>' . esc_html__('Plural:', 'ahx_wp_i18n') . '</strong> <code>' . esc_html($item['msgid_plural']) . '</code></p>';
            }
            echo '</td>';
            echo '<td>';
            echo '<textarea name="ahx_i18n_msgstr_0[' . esc_attr($item['key']) . ']" rows="2" style="width:100%;">' . esc_textarea($item['msgstr_0']) . '</textarea>';
            if ($item['msgid_plural'] !== '') {
                echo '<label style="display:block; margin-top:6px;">Plural 2</label>';
                echo '<textarea name="ahx_i18n_msgstr_1[' . esc_attr($item['key']) . ']" rows="2" style="width:100%;">' . esc_textarea($item['msgstr_1']) . '</textarea>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button class="button button-primary" type="submit" name="ahx_i18n_save_po" value="1">' . esc_html__('PO speichern', 'ahx_wp_i18n') . '</button></p>';
        echo '</form>';
    }

    echo '<h2>' . esc_html__('Gefundene PO-Dateien', 'ahx_wp_i18n') . '</h2>';
    if (empty($po_files)) {
        echo '<p>' . esc_html__('Keine PO-Dateien gefunden.', 'ahx_wp_i18n') . '</p>';
    } else {
        echo '<table class="widefat fixed"><thead><tr><th>Path</th><th>Aktion</th></tr></thead><tbody>';
        foreach ($po_files as $p) {
            echo '<tr><td>' . esc_html($p) . '</td><td>';
            echo '<form method="post" style="display:inline;">';
            echo wp_nonce_field('ahx_i18n_compile', 'ahx_i18n_compile_nonce', true, false);
            echo '<input type="hidden" name="ahx_i18n_po_path" value="' . esc_attr($p) . '">';
            echo '<button class="button button-primary" name="ahx_i18n_compile" type="submit" value="1">' . esc_html__('Compile MO', 'ahx_wp_i18n') . '</button>';
            echo '</form>';
            echo ' <form method="post" style="display:inline; margin-left:6px;">';
            echo wp_nonce_field('ahx_i18n_open_po', 'ahx_i18n_open_po_nonce', true, false);
            echo '<input type="hidden" name="ahx_i18n_open_po_path" value="' . esc_attr($p) . '">';
            echo '<input type="hidden" name="ahx_i18n_po_search" value="">';
            echo '<button class="button" name="ahx_i18n_open_po" type="submit" value="1">' . esc_html__('Bearbeiten', 'ahx_wp_i18n') . '</button>';
            echo '</form>';
            echo ' <a class="button" href="' . esc_url(admin_url('tools.php?page=ahx-i18n&download=' . rawurlencode($p))) . '">' . esc_html__('Download PO', 'ahx_wp_i18n') . '</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // Download handler
    if (isset($_GET['download'])) {
        $file = rawurldecode($_GET['download']);
        if (file_exists($file)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            echo file_get_contents($file);
            exit;
        }
    }

    echo '<p style="margin-top:20px;"><a class="button" href="' . esc_url(admin_url('tools.php?page=ahx-i18n')) . '">' . esc_html__('Zurueck', 'ahx_wp_i18n') . '</a></p>';

    echo '</div>';
}
