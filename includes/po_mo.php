<?php
if (!defined('ABSPATH')) exit;

/**
 * Very small PO -> MO compiler fallback and helpers.
 */

function ahx_i18n_compile_mo($po_path, $mo_path) {
    // Prefer msgfmt if available
    if (function_exists('exec')) {
        $msgfmt = trim(shell_exec('which msgfmt 2>/dev/null'));
        if ($msgfmt) {
            $cmd = escapeshellcmd($msgfmt) . ' ' . escapeshellarg($po_path) . ' -o ' . escapeshellarg($mo_path);
            @exec($cmd, $out, $rc);
            if ($rc === 0 && file_exists($mo_path)) return true;
        }
    }

    // Fallback: build a valid GNU MO file in PHP.
    $po = file_get_contents($po_path);
    if ($po === false) return new WP_Error('read_failed', 'Could not read PO file');
    $entries = ahx_i18n_parse_gettext_entries($po);

    $catalog = [];
    foreach ($entries as $entry) {
        $msgid = isset($entry['msgid']) ? (string) $entry['msgid'] : '';
        if ($msgid === null) {
            continue;
        }

        $context = isset($entry['context']) ? (string) $entry['context'] : '';
        $plural = isset($entry['msgid_plural']) ? (string) $entry['msgid_plural'] : '';
        $msgstr = isset($entry['msgstr']) && is_array($entry['msgstr']) ? $entry['msgstr'] : [];

        $original = $msgid;
        if ($context !== '') {
            $original = $context . "\x04" . $original;
        }
        if ($plural !== '') {
            $original .= "\0" . $plural;
        }

        if ($plural !== '') {
            ksort($msgstr);
            $translated_parts = [];
            foreach ($msgstr as $value) {
                $translated_parts[] = (string) $value;
            }
            $translated = implode("\0", $translated_parts);
        } else {
            $translated = (string) ($msgstr[0] ?? '');
        }

        $catalog[$original] = $translated;
    }

    ksort($catalog, SORT_STRING);
    $count = count($catalog);

    $header_size = 28;
    $originals_offset = $header_size;
    $translations_offset = $originals_offset + ($count * 8);
    $strings_offset = $translations_offset + ($count * 8);

    $original_table = '';
    $translation_table = '';
    $original_block = '';
    $translation_block = '';
    $current_original_offset = $strings_offset;
    $current_translation_offset = $strings_offset;

    foreach ($catalog as $original => $translated) {
        $current_translation_offset += strlen($original) + 1;
    }

    foreach ($catalog as $original => $translated) {
        $original_length = strlen($original);
        $translation_length = strlen($translated);

        $original_table .= pack('V', $original_length) . pack('V', $current_original_offset);
        $translation_table .= pack('V', $translation_length) . pack('V', $current_translation_offset);

        $original_block .= $original . "\0";
        $translation_block .= $translated . "\0";

        $current_original_offset += $original_length + 1;
        $current_translation_offset += $translation_length + 1;
    }

    $mo = '';
    $mo .= pack('V', 0x950412de);
    $mo .= pack('V', 0);
    $mo .= pack('V', $count);
    $mo .= pack('V', $originals_offset);
    $mo .= pack('V', $translations_offset);
    $mo .= pack('V', 0);
    $mo .= pack('V', 0);
    $mo .= $original_table;
    $mo .= $translation_table;
    $mo .= $original_block;
    $mo .= $translation_block;

    $res = @file_put_contents($mo_path, $mo);
    if ($res === false) return new WP_Error('write_failed', 'Could not write MO file');
    return true;
}

/**
 * Build POT file from translatable strings found in plugin/theme files.
 */
function ahx_i18n_generate_pot($args = []) {
    $defaults = [
        'scan_plugins' => false,
        'scan_templates' => false,
        'target_type' => '',
        'target_slug' => '',
        'text_domain' => 'ahx_wp_i18n',
        'output_path' => '',
    ];
    $args = wp_parse_args($args, $defaults);

    $text_domain = trim((string) $args['text_domain']);
    if ($text_domain === '') {
        return new WP_Error('missing_text_domain', 'Text domain is required');
    }

    $target_type = (string) $args['target_type'];
    $target_slug = (string) $args['target_slug'];
    if (trim($target_type) !== '' || trim($target_slug) !== '') {
        $target_dir = ahx_i18n_resolve_target_directory($target_type, $target_slug);
        if (is_wp_error($target_dir)) {
            return $target_dir;
        }
        $sources = [$target_dir];
    } else {
        $sources = ahx_i18n_collect_scan_sources(
            (bool) $args['scan_plugins'],
            (bool) $args['scan_templates']
        );
    }
    if (empty($sources)) {
        return new WP_Error('no_sources', 'No scan sources selected');
    }

    $entries = [];
    $scanned_files = 0;
    foreach ($sources as $source) {
        $files = ahx_i18n_list_source_files($source);
        foreach ($files as $file) {
            $scanned_files++;
            ahx_i18n_extract_strings_from_file($file, $text_domain, $entries);
        }
    }

    $pot = ahx_i18n_render_pot($entries, $text_domain);
    $output_path = (string) $args['output_path'];
    if ($output_path === '') {
        $target_dir = reset($sources);
        if (!$target_dir || !is_string($target_dir)) {
            return new WP_Error('invalid_output_target', 'Unable to determine output target');
        }
        $output_path = trailingslashit($target_dir) . 'languages/' . $text_domain . '.pot';
    }

    $output_dir = dirname($output_path);
    if (!is_dir($output_dir)) {
        wp_mkdir_p($output_dir);
    }

    if (@file_put_contents($output_path, $pot) === false) {
        return new WP_Error('write_failed', 'Could not write POT file');
    }

    return [
        'output_path' => $output_path,
        'entry_count' => count($entries),
        'scanned_files' => $scanned_files,
    ];
}

function ahx_i18n_collect_scan_sources($scan_plugins, $scan_templates) {
    $sources = [];
    if ($scan_plugins && defined('WP_PLUGIN_DIR') && is_dir(WP_PLUGIN_DIR)) {
        $sources[] = WP_PLUGIN_DIR;
    }
    if ($scan_templates) {
        $template_dirs = [
            get_template_directory(),
            get_stylesheet_directory(),
        ];
        foreach ($template_dirs as $dir) {
            if ($dir && is_dir($dir)) {
                $sources[] = $dir;
            }
        }
    }
    return array_values(array_unique($sources));
}

function ahx_i18n_resolve_target_directory($target_type, $target_slug) {
    $target_type = trim((string) $target_type);
    $target_slug = trim((string) $target_slug);
    if ($target_type === '' || $target_slug === '') {
        return new WP_Error('missing_target', 'Target type and target slug are required');
    }

    if (strpos($target_slug, '..') !== false) {
        return new WP_Error('invalid_target', 'Invalid target slug');
    }

    if ($target_type === 'plugin') {
        if (!defined('WP_PLUGIN_DIR') || !is_dir(WP_PLUGIN_DIR)) {
            return new WP_Error('plugin_dir_missing', 'Plugin directory not available');
        }
        $candidate = wp_normalize_path(trailingslashit(WP_PLUGIN_DIR) . ltrim(wp_normalize_path($target_slug), '/'));
        if (is_file($candidate)) {
            return dirname($candidate);
        }
        if (is_dir($candidate)) {
            return $candidate;
        }
        return new WP_Error('plugin_not_found', 'Selected plugin path not found');
    }

    if ($target_type === 'template') {
        $theme = wp_get_theme($target_slug);
        if (!$theme || !$theme->exists()) {
            return new WP_Error('template_not_found', 'Selected template not found');
        }
        return $theme->get_stylesheet_directory();
    }

    return new WP_Error('invalid_target_type', 'Unsupported target type');
}

function ahx_i18n_list_source_files($root) {
    $results = [];
    if (!is_dir($root)) {
        return $results;
    }

    $skip_dirs = [
        '.git',
        'node_modules',
        'vendor',
        'dist',
        'build',
    ];
    $allowed_extensions = [
        'php',
        'js',
        'jsx',
        'ts',
        'tsx',
    ];

    $dir_iter = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $dir_iter,
        function ($current) use ($skip_dirs) {
            if ($current->isDir()) {
                return !in_array($current->getFilename(), $skip_dirs, true);
            }
            return true;
        }
    );
    $iter = new RecursiveIteratorIterator($filter);

    foreach ($iter as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions, true)) {
            continue;
        }
        $results[] = $file->getPathname();
    }

    return $results;
}

function ahx_i18n_extract_strings_from_file($file, $text_domain, &$entries) {
    $contents = @file_get_contents($file);
    if ($contents === false || $contents === '') {
        return;
    }

    $patterns = [
        // __('Text', 'domain') and siblings
        '/\\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\\s*\\(\\s*(["\'])(.*?)(?<!\\\\)\\1\\s*,\\s*(["\'])(.*?)(?<!\\\\)\\3/s',
        // _x('Text', 'Context', 'domain') and siblings
        '/\\b(?:_x|_ex|esc_html_x|esc_attr_x)\\s*\\(\\s*(["\'])(.*?)(?<!\\\\)\\1\\s*,\\s*(["\'])(.*?)(?<!\\\\)\\3\\s*,\\s*(["\'])(.*?)(?<!\\\\)\\5/s',
        // _n('One', 'Many', $count, 'domain')
        '/\\b(?:_n|_n_noop)\\s*\\(\\s*(["\'])(.*?)(?<!\\\\)\\1\\s*,\\s*(["\'])(.*?)(?<!\\\\)\\3\\s*,\\s*.+?\\s*,\\s*(["\'])(.*?)(?<!\\\\)\\5/s',
        // _nx('One', 'Many', $count, 'Context', 'domain') and siblings
        '/\\b(?:_nx|_nx_noop)\\s*\\(\\s*(["\'])(.*?)(?<!\\\\)\\1\\s*,\\s*(["\'])(.*?)(?<!\\\\)\\3\\s*,\\s*.+?\\s*,\\s*(["\'])(.*?)(?<!\\\\)\\5\\s*,\\s*(["\'])(.*?)(?<!\\\\)\\7/s',
    ];

    // Singles
    if (preg_match_all($patterns[0], $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $m) {
            if ($m[4][0] !== $text_domain) {
                continue;
            }
            ahx_i18n_add_pot_entry($entries, [
                'msgid' => stripcslashes($m[2][0]),
                'file' => $file,
                'translator_comment' => ahx_i18n_find_translator_comment($contents, (int) $m[0][1]),
            ]);
        }
    }

    // Context strings
    if (preg_match_all($patterns[1], $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $m) {
            if ($m[6][0] !== $text_domain) {
                continue;
            }
            ahx_i18n_add_pot_entry($entries, [
                'msgid' => stripcslashes($m[2][0]),
                'context' => stripcslashes($m[4][0]),
                'file' => $file,
                'translator_comment' => ahx_i18n_find_translator_comment($contents, (int) $m[0][1]),
            ]);
        }
    }

    // Plurals
    if (preg_match_all($patterns[2], $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $m) {
            if ($m[6][0] !== $text_domain) {
                continue;
            }
            ahx_i18n_add_pot_entry($entries, [
                'msgid' => stripcslashes($m[2][0]),
                'msgid_plural' => stripcslashes($m[4][0]),
                'file' => $file,
                'translator_comment' => ahx_i18n_find_translator_comment($contents, (int) $m[0][1]),
            ]);
        }
    }

    // Plurals with context
    if (preg_match_all($patterns[3], $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $m) {
            if ($m[8][0] !== $text_domain) {
                continue;
            }
            ahx_i18n_add_pot_entry($entries, [
                'msgid' => stripcslashes($m[2][0]),
                'msgid_plural' => stripcslashes($m[4][0]),
                'context' => stripcslashes($m[6][0]),
                'file' => $file,
                'translator_comment' => ahx_i18n_find_translator_comment($contents, (int) $m[0][1]),
            ]);
        }
    }
}

function ahx_i18n_find_translator_comment($contents, $offset) {
    $offset = max(0, (int) $offset);
    $before = substr($contents, 0, $offset);
    if ($before === '') {
        return '';
    }

    $tail = substr($before, -2000);
    if ($tail === false || $tail === '') {
        return '';
    }

    if (!preg_match('/((?:\/\*[\s\S]*?\*\/|\/\/[^\n]*|#[^\n]*))\s*$/', $tail, $m)) {
        return '';
    }

    $comment = $m[1];
    if (!preg_match('/translators\s*:/i', $comment)) {
        return [];
    }

    return ahx_i18n_normalize_translator_comment($comment);
}

function ahx_i18n_normalize_translator_comment($comment) {
    $comment = trim((string) $comment);
    if ($comment === '') {
        return [];
    }

    if (strpos($comment, '/*') === 0) {
        $comment = preg_replace('/^\/\*+\s*/', '', $comment);
        $comment = preg_replace('/\s*\*\/$/', '', $comment);
    } elseif (strpos($comment, '//') === 0) {
        $comment = preg_replace('/^\/\/\s*/', '', $comment);
    } elseif (strpos($comment, '#') === 0) {
        $comment = preg_replace('/^#\s*/', '', $comment);
    }

    $lines = preg_split('/\r\n|\n|\r/', $comment);
    $parts = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        $line = preg_replace('/^\*\s?/', '', $line);
        if ($line !== '') {
            $parts[] = $line;
        }
    }

    if (empty($parts)) {
        return [];
    }

    $start = -1;
    $first = '';
    foreach ($parts as $idx => $line) {
        if (preg_match('/translators\s*:\s*(.*)$/i', $line, $m)) {
            $start = $idx;
            $first = trim((string) $m[1]);
            break;
        }
    }

    if ($start === -1) {
        return [];
    }

    $out = [];
    if ($first !== '') {
        $out[] = preg_replace('/\s+/', ' ', $first);
    }

    for ($i = $start + 1; $i < count($parts); $i++) {
        $line = trim((string) $parts[$i]);
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\s+/', ' ', $line);
        $out[] = $line;
    }

    return $out;
}

function ahx_i18n_add_pot_entry(&$entries, $entry) {
    $msgid = isset($entry['msgid']) ? (string) $entry['msgid'] : '';
    if ($msgid === '') {
        return;
    }

    $context = isset($entry['context']) ? (string) $entry['context'] : '';
    $plural = isset($entry['msgid_plural']) ? (string) $entry['msgid_plural'] : '';
    $key = $context . "\x04" . $msgid . "\x00" . $plural;

    if (!isset($entries[$key])) {
        $entries[$key] = [
            'msgid' => $msgid,
            'context' => $context,
            'msgid_plural' => $plural,
            'references' => [],
            'comments' => [],
        ];
    }

    if (!empty($entry['file'])) {
        $ref = wp_normalize_path((string) $entry['file']);
        if (!in_array($ref, $entries[$key]['references'], true)) {
            $entries[$key]['references'][] = $ref;
        }
    }

    if (!empty($entry['translator_comment'])) {
        $comments = is_array($entry['translator_comment']) ? $entry['translator_comment'] : [$entry['translator_comment']];
        foreach ($comments as $comment) {
            $comment = trim((string) $comment);
            if ($comment !== '' && !in_array($comment, $entries[$key]['comments'], true)) {
                $entries[$key]['comments'][] = $comment;
            }
        }
    }
}

function ahx_i18n_render_pot($entries, $text_domain) {
    ksort($entries);

    $out = '';
    $out .= "msgid \"\"\n";
    $out .= "msgstr \"\"\n";
    $out .= '"Project-Id-Version: ' . ahx_i18n_po_escape($text_domain) . "\\n\"\n";
    $out .= '"POT-Creation-Date: ' . gmdate('Y-m-d H:i+0000') . "\\n\"\n";
    $out .= '"MIME-Version: 1.0\\n\"\n';
    $out .= '"Content-Type: text/plain; charset=UTF-8\\n\"\n';
    $out .= '"Content-Transfer-Encoding: 8bit\\n\"\n';
    $out .= "\n";

    foreach ($entries as $entry) {
        if (!empty($entry['comments'])) {
            $is_first_comment_line = true;
            foreach ($entry['comments'] as $comment) {
                $comment = trim(str_replace(["\r", "\n"], ' ', (string) $comment));
                if ($comment !== '') {
                    if ($is_first_comment_line) {
                        $out .= '#. translators: ' . $comment . "\n";
                        $is_first_comment_line = false;
                    } else {
                        $out .= '#. ' . $comment . "\n";
                    }
                }
            }
        }
        if (!empty($entry['references'])) {
            sort($entry['references']);
            $out .= '#: ' . implode(' ', $entry['references']) . "\n";
        }
        if (!empty($entry['context'])) {
            $out .= 'msgctxt "' . ahx_i18n_po_escape($entry['context']) . "\"\n";
        }
        $out .= 'msgid "' . ahx_i18n_po_escape($entry['msgid']) . "\"\n";
        if (!empty($entry['msgid_plural'])) {
            $out .= 'msgid_plural "' . ahx_i18n_po_escape($entry['msgid_plural']) . "\"\n";
            $out .= "msgstr[0] \"\"\n";
            $out .= "msgstr[1] \"\"\n";
        } else {
            $out .= "msgstr \"\"\n";
        }
        $out .= "\n";
    }

    return $out;
}

function ahx_i18n_po_escape($string) {
    $string = (string) $string;
    $string = str_replace("\\", "\\\\", $string);
    $string = str_replace('"', '\\"', $string);
    $string = str_replace("\r", '', $string);
    return str_replace("\n", "\\n", $string);
}

function ahx_i18n_parse_gettext_entries($contents) {
    $lines = preg_split('/\r\n|\n|\r/', (string) $contents);
    $entries = [];
    $entry = ahx_i18n_new_gettext_entry();
    $state = null;

    $flush = static function () use (&$entries, &$entry, &$state) {
        if ($entry['msgid'] !== null) {
            if (empty($entry['msgstr'])) {
                $entry['msgstr'][0] = '';
            }
            ksort($entry['msgstr']);
            $entries[] = $entry;
        }
        $entry = ahx_i18n_new_gettext_entry();
        $state = null;
    };

    foreach ($lines as $line) {
        $line = (string) $line;
        if (trim($line) === '') {
            $flush();
            continue;
        }

        if (preg_match('/^#\.\s*(.+)$/', $line, $m)) {
            $entry['comments'][] = trim((string) $m[1]);
            continue;
        }
        if (preg_match('/^#:\s*(.+)$/', $line, $m)) {
            $refs = preg_split('/\s+/', trim((string) $m[1]));
            foreach ($refs as $ref) {
                if ($ref !== '' && !in_array($ref, $entry['references'], true)) {
                    $entry['references'][] = $ref;
                }
            }
            continue;
        }
        if (preg_match('/^#/', $line)) {
            continue;
        }

        if (preg_match('/^msgctxt\s+"(.*)"$/', $line, $m)) {
            $entry['context'] = stripcslashes($m[1]);
            $state = ['field' => 'context', 'index' => null];
            continue;
        }
        if (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            $entry['msgid'] = stripcslashes($m[1]);
            $state = ['field' => 'msgid', 'index' => null];
            continue;
        }
        if (preg_match('/^msgid_plural\s+"(.*)"$/', $line, $m)) {
            $entry['msgid_plural'] = stripcslashes($m[1]);
            $state = ['field' => 'msgid_plural', 'index' => null];
            continue;
        }
        if (preg_match('/^msgstr\s+"(.*)"$/', $line, $m)) {
            $entry['msgstr'][0] = stripcslashes($m[1]);
            $state = ['field' => 'msgstr', 'index' => 0];
            continue;
        }
        if (preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/', $line, $m)) {
            $idx = (int) $m[1];
            $entry['msgstr'][$idx] = stripcslashes($m[2]);
            $state = ['field' => 'msgstr', 'index' => $idx];
            continue;
        }

        if (preg_match('/^"(.*)"$/', $line, $m) && is_array($state)) {
            $chunk = stripcslashes($m[1]);
            if ($state['field'] === 'context') {
                $entry['context'] .= $chunk;
            } elseif ($state['field'] === 'msgid') {
                $entry['msgid'] .= $chunk;
            } elseif ($state['field'] === 'msgid_plural') {
                $entry['msgid_plural'] .= $chunk;
            } elseif ($state['field'] === 'msgstr') {
                $i = (int) $state['index'];
                if (!isset($entry['msgstr'][$i])) {
                    $entry['msgstr'][$i] = '';
                }
                $entry['msgstr'][$i] .= $chunk;
            }
        }
    }

    $flush();
    return $entries;
}

function ahx_i18n_new_gettext_entry() {
    return [
        'context' => '',
        'msgid' => null,
        'msgid_plural' => '',
        'msgstr' => [],
        'comments' => [],
        'references' => [],
    ];
}

function ahx_i18n_gettext_entry_key($entry) {
    $context = isset($entry['context']) ? (string) $entry['context'] : '';
    $msgid = isset($entry['msgid']) ? (string) $entry['msgid'] : '';
    $plural = isset($entry['msgid_plural']) ? (string) $entry['msgid_plural'] : '';
    return $context . "\x04" . $msgid . "\x00" . $plural;
}

function ahx_i18n_gettext_form_key($entry) {
    $payload = [
        'c' => isset($entry['context']) ? (string) $entry['context'] : '',
        'i' => isset($entry['msgid']) ? (string) $entry['msgid'] : '',
        'p' => isset($entry['msgid_plural']) ? (string) $entry['msgid_plural'] : '',
    ];
    return base64_encode((string) wp_json_encode($payload));
}

function ahx_i18n_render_po_from_entries($entries, $text_domain, $locale) {
    $out = '';

    $header_lines = [
        'Project-Id-Version: ' . (string) $text_domain,
        'POT-Creation-Date: ' . gmdate('Y-m-d H:i+0000'),
        'PO-Revision-Date: ' . gmdate('Y-m-d H:i+0000'),
        'Language: ' . (string) $locale,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Generator: AHX WP i18n',
    ];

    $out .= "msgid \"\"\n";
    $out .= "msgstr \"\"\n";
    foreach ($header_lines as $line) {
        $out .= '"' . ahx_i18n_po_escape($line . "\n") . "\"\n";
    }
    $out .= "\n";

    foreach ($entries as $entry) {
        $msgid = isset($entry['msgid']) ? (string) $entry['msgid'] : '';
        if ($msgid === '') {
            continue;
        }

        if (!empty($entry['comments']) && is_array($entry['comments'])) {
            foreach ($entry['comments'] as $comment) {
                $comment = trim((string) $comment);
                if ($comment !== '') {
                    $out .= '#. ' . $comment . "\n";
                }
            }
        }
        if (!empty($entry['references']) && is_array($entry['references'])) {
            $refs = array_values(array_filter(array_map('trim', $entry['references'])));
            if (!empty($refs)) {
                $out .= '#: ' . implode(' ', $refs) . "\n";
            }
        }

        $context = isset($entry['context']) ? (string) $entry['context'] : '';
        if ($context !== '') {
            $out .= 'msgctxt "' . ahx_i18n_po_escape($context) . "\"\n";
        }

        $out .= 'msgid "' . ahx_i18n_po_escape($msgid) . "\"\n";
        $plural = isset($entry['msgid_plural']) ? (string) $entry['msgid_plural'] : '';
        if ($plural !== '') {
            $out .= 'msgid_plural "' . ahx_i18n_po_escape($plural) . "\"\n";
            $str0 = isset($entry['msgstr'][0]) ? (string) $entry['msgstr'][0] : '';
            $str1 = isset($entry['msgstr'][1]) ? (string) $entry['msgstr'][1] : '';
            $out .= 'msgstr[0] "' . ahx_i18n_po_escape($str0) . "\"\n";
            $out .= 'msgstr[1] "' . ahx_i18n_po_escape($str1) . "\"\n";
        } else {
            $str = isset($entry['msgstr'][0]) ? (string) $entry['msgstr'][0] : '';
            $out .= 'msgstr "' . ahx_i18n_po_escape($str) . "\"\n";
        }
        $out .= "\n";
    }

    return $out;
}

function ahx_i18n_build_translation_filename($text_domain, $locale, $extension = 'po') {
    $text_domain = trim((string) $text_domain);
    $locale = trim((string) $locale);
    $extension = strtolower(trim((string) $extension));

    if ($extension === '') {
        $extension = 'po';
    }

    return $text_domain . '-' . $locale . '.' . $extension;
}

function ahx_i18n_is_legacy_translation_basename($basename) {
    $basename = trim((string) $basename);
    if ($basename === '') {
        return false;
    }

    // Legacy naming is locale-only, e.g. en_US.po
    return (bool) preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,})?(?:@[A-Za-z0-9]+)?$/', $basename);
}

function ahx_i18n_find_legacy_po_paths($directory, $locale, $exclude_paths = []) {
    $directory = wp_normalize_path((string) $directory);
    $locale = trim((string) $locale);
    $exclude_paths = is_array($exclude_paths) ? $exclude_paths : [];

    if ($directory === '' || $locale === '' || !is_dir($directory)) {
        return [];
    }

    $normalize_cmp = static function ($path) {
        $path = wp_normalize_path((string) $path);
        if (stripos(PHP_OS, 'WIN') === 0) {
            $path = strtolower($path);
        }
        return $path;
    };

    $exclude_map = [];
    foreach ($exclude_paths as $exclude_path) {
        $exclude_key = $normalize_cmp($exclude_path);
        if ($exclude_key !== '') {
            $exclude_map[$exclude_key] = true;
        }
    }

    $locale_variants = [$locale];
    $locale_variants[] = str_replace('_', '-', $locale);
    $locale_variants[] = str_replace('-', '_', $locale);
    $locale_variants = array_values(array_unique(array_filter(array_map('trim', $locale_variants))));

    $found = [];
    foreach ($locale_variants as $variant) {
        if (!ahx_i18n_is_legacy_translation_basename($variant)) {
            continue;
        }

        $candidate = trailingslashit($directory) . $variant . '.po';
        $candidate = wp_normalize_path($candidate);
        $candidate_key = $normalize_cmp($candidate);
        if (isset($exclude_map[$candidate_key])) {
            continue;
        }
        if (is_file($candidate)) {
            $found[$candidate_key] = $candidate;
        }
    }

    return array_values($found);
}

function ahx_i18n_create_po_from_pot($pot_path, $locale, $text_domain, $po_path = '') {
    $pot_path = (string) $pot_path;
    if (!is_file($pot_path)) {
        return new WP_Error('pot_missing', 'POT file not found');
    }

    $locale = trim((string) $locale);
    if ($locale === '' || !preg_match('/^[A-Za-z0-9_\-@.]+$/', $locale)) {
        return new WP_Error('invalid_locale', 'Invalid locale');
    }

    $text_domain = trim((string) $text_domain);
    if ($text_domain === '') {
        return new WP_Error('invalid_text_domain', 'Text domain is required');
    }

    if ($po_path === '') {
        $filename = ahx_i18n_build_translation_filename($text_domain, $locale, 'po');
        $po_path = trailingslashit(dirname($pot_path)) . $filename;
    }

    $pot_contents = @file_get_contents($pot_path);
    if ($pot_contents === false) {
        return new WP_Error('pot_read_failed', 'Could not read POT file');
    }

    $pot_entries = ahx_i18n_parse_gettext_entries($pot_contents);

    // Load existing translations from canonical and legacy-named PO files.
    $existing_map = [];
    $legacy_po_paths = ahx_i18n_find_legacy_po_paths(dirname($po_path), $locale, [$po_path]);
    $source_po_paths = array_merge([$po_path], $legacy_po_paths);
    $used_legacy_po_paths = [];

    foreach ($source_po_paths as $source_po_path) {
        if (!is_file($source_po_path)) {
            continue;
        }

        $existing_contents = @file_get_contents($source_po_path);
        if ($existing_contents === false) {
            continue;
        }

        $is_legacy_source = ($source_po_path !== $po_path);
        if ($is_legacy_source) {
            $used_legacy_po_paths[] = wp_normalize_path((string) $source_po_path);
        }

        foreach (ahx_i18n_parse_gettext_entries($existing_contents) as $ex) {
            $key = ahx_i18n_gettext_entry_key($ex);
            if (!isset($existing_map[$key])) {
                $existing_map[$key] = $ex;
                continue;
            }

            $current_msgstr = isset($existing_map[$key]['msgstr']) && is_array($existing_map[$key]['msgstr'])
                ? $existing_map[$key]['msgstr']
                : [];
            $incoming_msgstr = isset($ex['msgstr']) && is_array($ex['msgstr'])
                ? $ex['msgstr']
                : [];

            foreach ($incoming_msgstr as $idx => $value) {
                $idx = (int) $idx;
                $incoming_value = trim((string) $value);
                $current_value = trim((string) ($current_msgstr[$idx] ?? ''));
                if ($current_value === '' && $incoming_value !== '') {
                    $current_msgstr[$idx] = (string) $value;
                }
            }

            $existing_map[$key]['msgstr'] = $current_msgstr;
        }
    }

    $po_entries = [];
    $new_count = 0;
    foreach ($pot_entries as $entry) {
        $msgid = isset($entry['msgid']) ? (string) $entry['msgid'] : '';
        if ($msgid === '') {
            continue;
        }
        $key = ahx_i18n_gettext_entry_key($entry);
        if (isset($existing_map[$key])) {
            // Keep existing translation, but refresh references/comments from POT
            $merged = $existing_map[$key];
            $merged['references'] = $entry['references'];
            $merged['comments']   = $entry['comments'];
            $po_entries[] = $merged;
        } else {
            $entry['msgstr'] = [0 => ''];
            if (!empty($entry['msgid_plural'])) {
                $entry['msgstr'][1] = '';
            }
            $po_entries[] = $entry;
            $new_count++;
        }
    }

    $po_contents = ahx_i18n_render_po_from_entries($po_entries, $text_domain, $locale);
    $dir = dirname($po_path);
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    if (@file_put_contents($po_path, $po_contents) === false) {
        return new WP_Error('po_write_failed', 'Could not write PO file');
    }

    $migrated_legacy_po_paths = [];
    $migrated_legacy_mo_paths = [];
    $target_mo_path = preg_replace('/\.po$/i', '.mo', $po_path);

    foreach ($used_legacy_po_paths as $legacy_po_path) {
        $legacy_po_path = wp_normalize_path((string) $legacy_po_path);
        if ($legacy_po_path === '' || $legacy_po_path === wp_normalize_path($po_path)) {
            continue;
        }

        if (is_file($legacy_po_path) && @unlink($legacy_po_path)) {
            $migrated_legacy_po_paths[] = $legacy_po_path;
        }

        $legacy_mo_path = preg_replace('/\.po$/i', '.mo', $legacy_po_path);
        if (!is_string($legacy_mo_path) || $legacy_mo_path === '' || !is_file($legacy_mo_path)) {
            continue;
        }

        if (is_string($target_mo_path) && $target_mo_path !== '' && !is_file($target_mo_path)) {
            if (@rename($legacy_mo_path, $target_mo_path)) {
                $migrated_legacy_mo_paths[] = wp_normalize_path($legacy_mo_path);
            }
        } else {
            if (@unlink($legacy_mo_path)) {
                $migrated_legacy_mo_paths[] = wp_normalize_path($legacy_mo_path);
            }
        }
    }

    return [
        'po_path' => $po_path,
        'entry_count' => count($po_entries),
        'new_count' => $new_count,
        'merged' => !empty($existing_map),
        'migrated_legacy' => !empty($migrated_legacy_po_paths) || !empty($migrated_legacy_mo_paths),
        'migrated_legacy_po_paths' => array_values(array_unique($migrated_legacy_po_paths)),
        'migrated_legacy_mo_paths' => array_values(array_unique($migrated_legacy_mo_paths)),
    ];
}

function ahx_i18n_get_po_editor_data($po_path, $search = '', $limit = 300) {
    $po_path = (string) $po_path;
    if (!is_file($po_path)) {
        return new WP_Error('po_missing', 'PO file not found');
    }

    $contents = @file_get_contents($po_path);
    if ($contents === false) {
        return new WP_Error('po_read_failed', 'Could not read PO file');
    }

    $entries = ahx_i18n_parse_gettext_entries($contents);
    $search = trim((string) $search);
    $limit = max(1, (int) $limit);
    $items = [];
    foreach ($entries as $entry) {
        $msgid = isset($entry['msgid']) ? (string) $entry['msgid'] : '';
        if ($msgid === '') {
            continue;
        }

        $needle = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
        if ($needle !== '') {
            $haystack_raw = $msgid . ' ' . (string)($entry['msgstr'][0] ?? '') . ' ' . (string)($entry['context'] ?? '');
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack_raw) : strtolower($haystack_raw);
            $pos = function_exists('mb_strpos') ? mb_strpos($haystack, $needle) : strpos($haystack, $needle);
            if ($pos === false) {
                continue;
            }
        }

        $items[] = [
            'key' => ahx_i18n_gettext_form_key($entry),
            'msgid' => $msgid,
            'context' => (string) ($entry['context'] ?? ''),
            'msgid_plural' => (string) ($entry['msgid_plural'] ?? ''),
            'msgstr_0' => (string) ($entry['msgstr'][0] ?? ''),
            'msgstr_1' => (string) ($entry['msgstr'][1] ?? ''),
        ];

        if (count($items) >= $limit) {
            break;
        }
    }

    return [
        'po_path' => $po_path,
        'entries' => $items,
        'total_entries' => count($items),
    ];
}

function ahx_i18n_save_po_translations($po_path, $text_domain, $locale, $translations0, $translations1 = []) {
    $po_path = (string) $po_path;
    if (!is_file($po_path)) {
        return new WP_Error('po_missing', 'PO file not found');
    }

    $contents = @file_get_contents($po_path);
    if ($contents === false) {
        return new WP_Error('po_read_failed', 'Could not read PO file');
    }

    $entries = ahx_i18n_parse_gettext_entries($contents);
    $translations0 = is_array($translations0) ? $translations0 : [];
    $translations1 = is_array($translations1) ? $translations1 : [];

    $updated = 0;
    foreach ($entries as &$entry) {
        $msgid = isset($entry['msgid']) ? (string) $entry['msgid'] : '';
        if ($msgid === '') {
            continue;
        }

        $key = ahx_i18n_gettext_form_key($entry);
        if (array_key_exists($key, $translations0)) {
            $entry['msgstr'][0] = trim((string) $translations0[$key]);
            $updated++;
        }

        if (!empty($entry['msgid_plural']) && array_key_exists($key, $translations1)) {
            $entry['msgstr'][1] = trim((string) $translations1[$key]);
        }
    }
    unset($entry);

    $text_domain = trim((string) $text_domain);
    $locale = trim((string) $locale);
    $source_basename = pathinfo($po_path, PATHINFO_FILENAME);
    $source_extension = pathinfo($po_path, PATHINFO_EXTENSION);

    if ($locale === '' || strtolower($locale) === 'unknown') {
        if (preg_match('/-([A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,})?(?:@[A-Za-z0-9]+)?)$/', (string) $source_basename, $m)) {
            $locale = (string) $m[1];
        } else {
            $locale = (string) $source_basename;
        }
    }

    if ($text_domain === '') {
        if (preg_match('/^(.+)-[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,})?(?:@[A-Za-z0-9]+)?$/', (string) $source_basename, $m)) {
            $text_domain = trim((string) $m[1]);
        }
    }

    $target_po_path = $po_path;
    $migrated = false;
    if ($text_domain !== '' && ahx_i18n_is_legacy_translation_basename($source_basename)) {
        $canonical_filename = ahx_i18n_build_translation_filename($text_domain, $locale, 'po');
        $target_po_path = trailingslashit(dirname($po_path)) . $canonical_filename;
        $migrated = ($target_po_path !== $po_path);
    }

    $new_contents = ahx_i18n_render_po_from_entries($entries, (string) $text_domain, (string) $locale);
    if (@file_put_contents($target_po_path, $new_contents) === false) {
        return new WP_Error('po_write_failed', 'Could not save PO file');
    }

    if ($migrated && is_file($po_path)) {
        @unlink($po_path);
    }

    $source_mo_path = preg_replace('/\.po$/i', '.mo', $po_path);
    $target_mo_path = preg_replace('/\.po$/i', '.mo', $target_po_path);
    if ($migrated && $source_mo_path && $target_mo_path && is_string($source_mo_path) && is_string($target_mo_path) && is_file($source_mo_path)) {
        @rename($source_mo_path, $target_mo_path);
    }

    return [
        'updated' => $updated,
        'po_path' => $target_po_path,
        'migrated' => $migrated,
        'old_po_path' => $po_path,
    ];
}

function ahx_i18n_scan_plain_text_candidates($target_type, $target_slug, $text_domain, $limit = 250) {
    $target_dir = ahx_i18n_resolve_target_directory($target_type, $target_slug);
    if (is_wp_error($target_dir)) {
        return $target_dir;
    }

    $limit = max(1, (int) $limit);
    $candidates = [];
    $files = ahx_i18n_list_source_files($target_dir);

    foreach ($files as $file) {
        if (count($candidates) >= $limit) {
            break;
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
            continue;
        }

        $contents = @file_get_contents($file);
        if ($contents === false || $contents === '') {
            continue;
        }

        $remaining = $limit - count($candidates);
        $found = ahx_i18n_find_plain_text_in_file($file, $contents, $text_domain, $remaining);
        if (!empty($found)) {
            $candidates = array_merge($candidates, $found);
        }
    }

    return [
        'target_dir' => $target_dir,
        'candidates' => $candidates,
    ];
}

function ahx_i18n_find_plain_text_in_file($file, $contents, $text_domain, $limit) {
    $results = [];
    $limit = max(1, (int) $limit);

    // Case 1: plain echo/print strings in PHP blocks.
    if (preg_match_all('/\b(echo|print)\s+(["\'])([^"\'\r\n]{2,}?)\2\s*;/', $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $m) {
            if (count($results) >= $limit) {
                return $results;
            }

            $full = $m[0][0];
            $start = (int) $m[0][1];
            if (ahx_i18n_is_offset_in_php_comment($contents, $start)) {
                continue;
            }
            $keyword = strtolower((string) $m[1][0]);
            $text = trim((string) $m[3][0]);
            if (!ahx_i18n_is_plain_text_candidate($text)) {
                continue;
            }

            $affixes = ahx_i18n_split_translatable_text_affixes($text);
            $text_for_translation = (string) $affixes['text'];
            if ($text_for_translation === '') {
                continue;
            }

            $translation_expr = 'esc_html__(' . ahx_i18n_php_quote($text_for_translation) . ', ' . ahx_i18n_php_quote($text_domain) . ')';
            $replacement_expr = ahx_i18n_build_concat_expression_with_affixes($translation_expr, (string) $affixes['prefix'], (string) $affixes['suffix']);

            $replacement = ($keyword === 'print' ? 'print ' : 'echo ') . $replacement_expr . ';';
            $results[] = ahx_i18n_build_plain_candidate($file, $contents, $start, $start + strlen($full), $full, $replacement, $text_for_translation, 'php');
        }
    }

    if (count($results) >= $limit) {
        return $results;
    }

    // Case 1b: multiple HTML text nodes inside one quoted echo/print string.
    $echo_string_patterns = [
        [
            'pattern' => '/\b(echo|print)\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;/u',
            'quote' => "'",
        ],
        [
            'pattern' => '/\b(echo|print)\s+"((?:\\\\.|[^"\\\\])*)"\s*;/u',
            'quote' => '"',
        ],
    ];

    foreach ($echo_string_patterns as $echo_string_pattern) {
        if (count($results) >= $limit) {
            break;
        }

        if (!preg_match_all($echo_string_pattern['pattern'], $contents, $echo_string_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($echo_string_matches as $match) {
            if (count($results) >= $limit) {
                break;
            }

            $string_body = (string) $match[2][0];
            $body_offset = (int) $match[2][1];
            $quote = $echo_string_pattern['quote'];

            if (strpos($string_body, '<') === false || strpos($string_body, '>') === false) {
                continue;
            }

            if (!preg_match_all('/>([^<\r\n][^<]{1,200})</', $string_body, $text_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($text_matches as $text_match) {
                if (count($results) >= $limit) {
                    break;
                }

                $raw = (string) $text_match[1][0];
                $raw_offset = $body_offset + (int) $text_match[1][1];
                if (ahx_i18n_is_offset_in_php_comment($contents, $raw_offset)) {
                    continue;
                }
                $raw_local_offset = (int) $text_match[1][1];
                $before_raw = substr($string_body, 0, $raw_local_offset);
                $tag_name = ahx_i18n_extract_trailing_open_tag_name($before_raw);
                if (ahx_i18n_is_non_translatable_html_context($tag_name)) {
                    continue;
                }
                $trimmed = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!ahx_i18n_is_plain_text_candidate($trimmed)) {
                    continue;
                }

                $affixes = ahx_i18n_split_translatable_text_affixes($trimmed);
                $text_for_translation = (string) $affixes['text'];
                if ($text_for_translation === '') {
                    continue;
                }

                $leading = (string) preg_replace('/^(\s*).*$/s', '$1', $raw);
                $trailing = (string) preg_replace('/^.*?(\s*)$/s', '$1', $raw);

                $replacement_parts = [];
                if ((string) $affixes['prefix'] !== '') {
                    $replacement_parts[] = $quote . ahx_i18n_escape_php_string_for_quote((string) $affixes['prefix'], $quote) . $quote;
                }
                $replacement_parts[] = 'esc_html__('
                    . ahx_i18n_php_quote($text_for_translation)
                    . ', '
                    . ahx_i18n_php_quote($text_domain)
                    . ')';
                if ((string) $affixes['suffix'] !== '') {
                    $replacement_parts[] = $quote . ahx_i18n_escape_php_string_for_quote((string) $affixes['suffix'], $quote) . $quote;
                }
                $replacement = $quote . ' . ' . implode(' . ', $replacement_parts) . ' . ' . $quote;

                if ($leading !== '' || $trailing !== '') {
                    $replacement = $leading . $replacement . $trailing;
                }

                $results[] = ahx_i18n_build_plain_candidate(
                    $file,
                    $contents,
                    $raw_offset,
                    $raw_offset + strlen($raw),
                    $raw,
                    $replacement,
                    $text_for_translation,
                    'html-echo-multi'
                );
            }
        }
    }

    // Case 3: HTML-wrapped text in an echo/print string, e.g. echo '<p>Text</p>';
    $html_echo_cases = [
        [
            'pattern' => '/\b(echo|print)\s+\'((?:[^\'<\r\n]*)(?:<[a-z][a-z0-9]*(?:\s[^\'>]*)?>))([^<>\'"]{2,}?)((?:<\/[a-z][a-z0-9]*>)(?:[^\'\r\n]*))\'\s*;/i',
            'q'       => "'",
        ],
        [
            'pattern' => '/\b(echo|print)\s+"((?:[^"<\r\n]*)(?:<[a-z][a-z0-9]*(?:\s[^">]*)?>))([^<>\'"]{2,}?)((?:<\/[a-z][a-z0-9]*>)(?:[^"\r\n]*))"\s*;/i',
            'q'       => '"',
        ],
    ];
    foreach ($html_echo_cases as $html_echo_case) {
        if (count($results) >= $limit) {
            break;
        }
        if (!preg_match_all($html_echo_case['pattern'], $contents, $html_echo_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($html_echo_matches as $m) {
            if (count($results) >= $limit) {
                break;
            }
            $full    = $m[0][0];
            $start   = (int) $m[0][1];
            if (ahx_i18n_is_offset_in_php_comment($contents, $start)) {
                continue;
            }
            $keyword = strtolower((string) $m[1][0]);
            $before  = (string) $m[2][0];
            $text    = trim((string) $m[3][0]);
            $after   = (string) $m[4][0];
            $tag_name = ahx_i18n_extract_trailing_open_tag_name($before);
            if (ahx_i18n_is_non_translatable_html_context($tag_name)) {
                continue;
            }
            if (!ahx_i18n_is_plain_text_candidate($text)) {
                continue;
            }

            $affixes = ahx_i18n_split_translatable_text_affixes($text);
            $text_for_translation = (string) $affixes['text'];
            if ($text_for_translation === '') {
                continue;
            }

            $q           = $html_echo_case['q'];
            $keyword_str = ($keyword === 'print') ? 'print ' : 'echo ';
            $translation_expr = 'esc_html__(' . ahx_i18n_php_quote($text_for_translation) . ', ' . ahx_i18n_php_quote($text_domain) . ')';
            $wrapped_expr = ahx_i18n_build_concat_expression_with_affixes($translation_expr, (string) $affixes['prefix'], (string) $affixes['suffix']);
            $replacement = $keyword_str . $q . $before . $q . ' . ' . $wrapped_expr . ' . ' . $q . $after . $q . ';';
            $results[]   = ahx_i18n_build_plain_candidate($file, $contents, $start, $start + strlen($full), $full, $replacement, $text_for_translation, 'html-echo');
        }
    }

    if (count($results) >= $limit) {
        return $results;
    }

    // Case 2: plain HTML text between tags outside PHP blocks.
    $segments = preg_split('/(<\?(?:php|=)[\s\S]*?\?>)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);
    if (!is_array($segments)) {
        return $results;
    }

    foreach ($segments as $segment) {
        if (count($results) >= $limit) {
            break;
        }
        $seg_text = (string) $segment[0];
        $seg_offset = (int) $segment[1];
        if ($seg_text === '' || strpos($seg_text, '<?') === 0) {
            continue;
        }

        if (!preg_match_all('/>([^<\r\n][^<]{1,200})</', $seg_text, $text_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($text_matches as $tm) {
            if (count($results) >= $limit) {
                break;
            }

            $raw = (string) $tm[1][0];
            $raw_start = $seg_offset + (int) $tm[1][1];
            $raw_local_offset = (int) $tm[1][1];
            $before_raw = substr($seg_text, 0, $raw_local_offset);
            $tag_name = ahx_i18n_extract_trailing_open_tag_name($before_raw);
            if (ahx_i18n_is_non_translatable_html_context($tag_name)) {
                continue;
            }
            $trimmed = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!ahx_i18n_is_plain_text_candidate($trimmed)) {
                continue;
            }

            $affixes = ahx_i18n_split_translatable_text_affixes($trimmed);
            $text_for_translation = (string) $affixes['text'];
            if ($text_for_translation === '') {
                continue;
            }

            $leading = (string) preg_replace('/^(\s*).*$/s', '$1', $raw);
            $trailing = (string) preg_replace('/^.*?(\s*)$/s', '$1', $raw);
            $replacement = $leading
                . (string) $affixes['prefix']
                . '<?php echo esc_html__(' . ahx_i18n_php_quote($text_for_translation) . ', ' . ahx_i18n_php_quote($text_domain) . '); ?>'
                . (string) $affixes['suffix']
                . $trailing;
            $results[] = ahx_i18n_build_plain_candidate($file, $contents, $raw_start, $raw_start + strlen($raw), $raw, $replacement, $text_for_translation, 'html');
        }
    }

    return $results;
}

function ahx_i18n_split_translatable_text_affixes($text) {
    $text = (string) $text;
    if ($text === '') {
        return ['prefix' => '', 'text' => '', 'suffix' => ''];
    }

    $prefix = '';
    $suffix = '';
    $core = $text;

    if (preg_match('/^([\s\p{S}\p{C}]+)(.*)$/u', $core, $m)) {
        $prefix = (string) $m[1];
        $core = (string) $m[2];
    }

    if ($core !== '' && preg_match('/^(.*?)([\s\p{S}\p{C}]+)$/u', $core, $m)) {
        $core = (string) $m[1];
        $suffix = (string) $m[2];
    }

    if (trim($core) === '') {
        return ['prefix' => '', 'text' => trim($text), 'suffix' => ''];
    }

    return [
        'prefix' => $prefix,
        'text' => $core,
        'suffix' => $suffix,
    ];
}

function ahx_i18n_build_concat_expression_with_affixes($core_expression, $prefix, $suffix) {
    $parts = [];
    if ((string) $prefix !== '') {
        $parts[] = ahx_i18n_php_quote((string) $prefix);
    }
    $parts[] = (string) $core_expression;
    if ((string) $suffix !== '') {
        $parts[] = ahx_i18n_php_quote((string) $suffix);
    }

    return implode(' . ', $parts);
}

function ahx_i18n_escape_php_string_for_quote($value, $quote) {
    $value = (string) $value;
    $value = str_replace('\\', '\\\\', $value);

    if ($quote === '"') {
        $value = str_replace('"', '\\"', $value);
        $value = str_replace('$', '\\$', $value);
        return $value;
    }

    return str_replace("'", "\\'", $value);
}

function ahx_i18n_is_plain_text_candidate($text) {
    $text = trim((string) $text);
    if ($text === '' || strlen($text) < 2) {
        return false;
    }
    if (ahx_i18n_looks_like_php_expression_fragment($text)) {
        return false;
    }
    if (!preg_match('/[A-Za-z]/', $text)) {
        return false;
    }
    if (preg_match('/^[-_.,:;!?()\[\]{}0-9\s]+$/', $text)) {
        return false;
    }
    if (strpos($text, '__(') !== false || strpos($text, '_e(') !== false) {
        return false;
    }
    // reject strings that already contain HTML tags (handled by Case 3)
    if (preg_match('/<[a-z][a-z0-9]*[\s>\/]|<\/[a-z]/', $text)) {
        return false;
    }
    return true;
}

function ahx_i18n_looks_like_php_expression_fragment($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return false;
    }

    // Typical concatenation fragment from echoed HTML: ' . expr . '
    if (preg_match('/^["\']\s*\.\s*(.+?)\s*\.\s*["\']$/s', $text, $m)) {
        $inner = trim((string) $m[1]);
        if ($inner === '') {
            return false;
        }

        // Ignore symbol-only fragments (e.g. emoji such as ✅ or ❌).
        $inner_without_symbols = trim((string) preg_replace('/[\p{S}\p{C}]+/u', '', $inner));
        if ($inner_without_symbols === '') {
            return false;
        }

        if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*|->|::|\[[^\]]*\]|\b[A-Za-z_][A-Za-z0-9_]*\s*\(/', $inner_without_symbols)) {
            return true;
        }

        if (preg_match('/[+\-*\/%?:=]/', $inner_without_symbols)) {
            return true;
        }

        return false;
    }

    // Variables, object access, array access, or function-like calls in fragments are not translatable text.
    if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*|->|::|\[[^\]]*\]|\b[A-Za-z_][A-Za-z0-9_]*\s*\(/', $text)) {
        return true;
    }

    return false;
}

function ahx_i18n_extract_trailing_open_tag_name($html_prefix) {
    $html_prefix = (string) $html_prefix;
    if ($html_prefix === '') {
        return '';
    }

    if (!preg_match('/<([a-z][a-z0-9]*)\b[^>]*>\s*$/i', $html_prefix, $m)) {
        return '';
    }

    return strtolower((string) $m[1]);
}

function ahx_i18n_is_non_translatable_html_context($tag_name) {
    $tag_name = strtolower(trim((string) $tag_name));
    if ($tag_name === '') {
        return false;
    }

    return in_array($tag_name, ['style', 'script'], true);
}

function ahx_i18n_is_offset_in_php_comment($contents, $offset) {
    static $cache = [];

    $contents = (string) $contents;
    $offset = max(0, (int) $offset);
    $key = md5($contents);

    if (!isset($cache[$key])) {
        $ranges = [];
        $cursor = 0;
        $tokens = token_get_all($contents);
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $token_id = (int) $token[0];
                $token_text = (string) $token[1];
                $length = strlen($token_text);
                if ($token_id === T_COMMENT || $token_id === T_DOC_COMMENT) {
                    $ranges[] = [
                        'start' => $cursor,
                        'end' => $cursor + $length,
                    ];
                }
                $cursor += $length;
            } else {
                $cursor += strlen((string) $token);
            }
        }
        $cache[$key] = $ranges;
    }

    foreach ($cache[$key] as $range) {
        if ($offset >= $range['start'] && $offset < $range['end']) {
            return true;
        }
    }

    return false;
}

function ahx_i18n_build_plain_candidate($file, $contents, $start, $end, $replace_from, $replace_to, $preview, $kind) {
    $safe_start = max(0, (int) $start);
    $safe_end = max($safe_start, (int) $end);
    $line = substr_count(substr($contents, 0, $safe_start), "\n") + 1;

    $line_start = strrpos(substr($contents, 0, $safe_start), "\n");
    $line_start = ($line_start === false) ? 0 : ($line_start + 1);
    $line_end = strpos($contents, "\n", $safe_end);
    if ($line_end === false) {
        $line_end = strlen($contents);
    }

    $line_original = substr($contents, $line_start, max(0, $line_end - $line_start));
    $relative_start = max(0, $safe_start - $line_start);
    $relative_end = max($relative_start, $safe_end - $line_start);
    $line_converted = substr($line_original, 0, $relative_start)
        . (string) $replace_to
        . substr($line_original, $relative_end);

    $line_original = rtrim((string) $line_original, "\r\n");
    $line_converted = rtrim((string) $line_converted, "\r\n");

    $payload = [
        'file' => wp_normalize_path($file),
        'start' => $safe_start,
        'end' => $safe_end,
        'replace_from' => (string) $replace_from,
        'replace_to' => (string) $replace_to,
        'preview' => (string) $preview,
        'kind' => (string) $kind,
        'line' => (int) $line,
    ];

    return [
        'id' => base64_encode((string) wp_json_encode($payload)),
        'file' => $payload['file'],
        'line' => $payload['line'],
        'preview' => $payload['preview'],
        'kind' => $payload['kind'],
        'line_original' => $line_original,
        'line_converted' => $line_converted,
    ];
}

function ahx_i18n_apply_plain_text_conversions($target_type, $target_slug, $selected_ids) {
    $target_dir = ahx_i18n_resolve_target_directory($target_type, $target_slug);
    if (is_wp_error($target_dir)) {
        return $target_dir;
    }
    $target_dir = untrailingslashit(wp_normalize_path($target_dir));
    $target_dir_check = function_exists('mb_strtolower') ? mb_strtolower($target_dir) : strtolower($target_dir);

    $selected_ids = is_array($selected_ids) ? $selected_ids : [];
    if (empty($selected_ids)) {
        return new WP_Error('no_selection', 'No text fragments selected');
    }

    $changes = [];
    foreach ($selected_ids as $id) {
        $decoded = json_decode(base64_decode((string) $id, true), true);
        if (!is_array($decoded)) {
            continue;
        }

        $file = isset($decoded['file']) ? wp_normalize_path((string) $decoded['file']) : '';
        $start = isset($decoded['start']) ? (int) $decoded['start'] : -1;
        $end = isset($decoded['end']) ? (int) $decoded['end'] : -1;
        $from = isset($decoded['replace_from']) ? (string) $decoded['replace_from'] : '';
        $to = isset($decoded['replace_to']) ? (string) $decoded['replace_to'] : '';

        if ($file === '' || $start < 0 || $end <= $start || $from === '' || $to === '') {
            continue;
        }
        $file_check = function_exists('mb_strtolower') ? mb_strtolower($file) : strtolower($file);
        if (strpos($file_check, $target_dir_check . '/') !== 0 && $file_check !== $target_dir_check) {
            continue;
        }

        if (!isset($changes[$file])) {
            $changes[$file] = [];
        }
        $changes[$file][] = [
            'start' => $start,
            'end' => $end,
            'from' => $from,
            'to' => $to,
        ];
    }

    $applied = 0;
    $skipped = 0;
    $updated_files = 0;

    foreach ($changes as $file => $file_changes) {
        if (!file_exists($file) || !is_readable($file) || !is_writable($file)) {
            $skipped += count($file_changes);
            continue;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            $skipped += count($file_changes);
            continue;
        }

        usort($file_changes, function ($a, $b) {
            return $b['start'] <=> $a['start'];
        });

        $file_applied = 0;
        foreach ($file_changes as $change) {
            $len = $change['end'] - $change['start'];
            $current = substr($contents, $change['start'], $len);
            if ($current !== $change['from']) {
                $skipped++;
                continue;
            }

            $contents = substr($contents, 0, $change['start']) . $change['to'] . substr($contents, $change['end']);
            $file_applied++;
            $applied++;
        }

        if ($file_applied > 0) {
            if (@file_put_contents($file, $contents) === false) {
                $skipped += $file_applied;
                $applied -= $file_applied;
            } else {
                $updated_files++;
            }
        }
    }

    return [
        'applied' => $applied,
        'skipped' => $skipped,
        'updated_files' => $updated_files,
    ];
}

function ahx_i18n_php_quote($value) {
    $value = (string) $value;
    $value = str_replace("\\", "\\\\", $value);
    $value = str_replace("'", "\\'", $value);
    return "'" . $value . "'";
}
