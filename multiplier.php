<?php

/**
 * Plugin Name: Multiplier2
 * Description: Adds database tables and endpoints for use with the Multiplier React app and the WordPress REST API, with nonce protection and Patreon login/tier awareness (via the Patreon WordPress plugin).
 * Author: Doug Hirlinger
 * Author URI: https://doughirlinger.com
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ------------------------------------------------------------
 * Enqueue Frontend Assets + Localize REST Nonce
 * ------------------------------------------------------------ */
add_action('wp_enqueue_scripts', function () {
    $js  = plugin_dir_path(__FILE__) . 'multiplier.js';
    $css = plugin_dir_path(__FILE__) . 'multiplier.css';

    // JS
    wp_enqueue_script(
        'multiplier',
        plugin_dir_url(__FILE__) . 'multiplier.js',
        [],
        file_exists($js) ? filemtime($js) : null,
        true
    );

    // CSS
    wp_enqueue_style(
        'multiplier-css',
        plugin_dir_url(__FILE__) . 'multiplier.css',
        [],
        file_exists($css) ? filemtime($css) : null
    );

    // Localize REST root + nonce for frontend usage
    wp_localize_script('multiplier', 'MultiplierAPI', [
        'restUrl' => esc_url_raw(rest_url()),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);
});

/* ------------------------------------------------------------
 * Database Setup (Activation)
 * ------------------------------------------------------------ */
register_activation_hook(__FILE__, 'multiplier_setup_table');
register_activation_hook(__FILE__, 'multiplier_install_data');

function multiplier_setup_table()
{
    global $wpdb;

    $index_array_table = $wpdb->prefix . 'multiplier_index_array';
    $freq_array_table  = $wpdb->prefix . 'multiplier_freq_array';
    $preset_table      = $wpdb->prefix . 'multiplier_preset';
    $charset_collate   = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE $index_array_table (
            array_id mediumint(9) NOT NULL AUTO_INCREMENT,
            array_name VARCHAR(50) NOT NULL,
            index_array VARCHAR(25) NOT NULL,
            user_id smallint(9) NOT NULL,
            PRIMARY KEY  (array_id),
            KEY  (user_id)
        ) $charset_collate;

        CREATE TABLE $freq_array_table (
            array_id mediumint(9) NOT NULL AUTO_INCREMENT,
            array_name VARCHAR(50) NOT NULL,
            base_freq DOUBLE,
            multiplier DOUBLE,
            user_id smallint(9) NOT NULL,
            PRIMARY KEY  (array_id),
            KEY  (user_id)
        ) $charset_collate;

        CREATE TABLE $preset_table (
            preset_id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(25),
            tempo INT NOT NULL,
            waveshape VARCHAR(25) NOT NULL,
            duration DOUBLE NOT NULL,
            lowpass_freq INT NOT NULL,
            lowpass_q INT NOT NULL,
            index_array_id mediumint(9)  NOT NULL,
            freq_array_id mediumint(9)  NOT NULL,
            multiplier_min DOUBLE NOT NULL,
            multiplier_max DOUBLE NOT NULL,
            multiplier_step DOUBLE NOT NULL,
            base_min DOUBLE NOT NULL,
            base_max DOUBLE NOT NULL,
            base_step DOUBLE NOT NULL,
            user_id smallint(9) NOT NULL,
            PRIMARY KEY (preset_id),
            KEY  index_array_id (index_array_id),
            KEY  freq_array_id (freq_array_id),
            KEY  user_id (user_id)
        ) $charset_collate;
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Add FKs only once
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'fk_preset_index'", $preset_table));
    if (!$exists) {
        $wpdb->query("ALTER TABLE $preset_table ADD CONSTRAINT fk_preset_index FOREIGN KEY (index_array_id) REFERENCES $index_array_table(array_id)");
    }
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'fk_preset_freq'", $preset_table));
    if (!$exists) {
        $wpdb->query("ALTER TABLE $preset_table ADD CONSTRAINT fk_preset_freq FOREIGN KEY (freq_array_id) REFERENCES $freq_array_table(array_id)");
    }
}

function multiplier_install_data()
{
    global $wpdb;

    $freq_array_table = $wpdb->prefix . 'multiplier_freq_array';

    $existing = $wpdb->get_var("SELECT array_id FROM $freq_array_table WHERE array_id = 1");
    if (!$existing) {
        $wpdb->insert(
            $freq_array_table,
            [
                'array_id'   => 1,
                'base_freq'  => 110,
                'multiplier' => 2,
                'array_name' => 'DEFAULT',
                'user_id'    => 1,
            ],
            ['%d', '%f', '%f', '%s', '%d']
        );
    }
}

/* ------------------------------------------------------------
 * REST Helpers: Nonce + Current User
 * ------------------------------------------------------------ */
function multiplier_verify_nonce_permission($request)
{
    $nonce = $request->get_header('X-WP-Nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error('rest_forbidden', __('Invalid or missing nonce'), ['status' => 401]);
    }
    return true;
}

function multiplier_current_user_id()
{
    $u = wp_get_current_user();
    return $u && $u->ID ? (int) $u->ID : 0;
}

/* ------------------------------------------------------------
 * REST Routes
 * ------------------------------------------------------------ */
add_action('rest_api_init', function () {
    // Login status (requires nonce)
    register_rest_route('multiplier-api/v1', '/login-status', [
        'methods'  => 'GET',
        'callback' => 'multiplier_get_login_status',
        'permission_callback' => 'multiplier_verify_nonce_permission',
    ]);

    // Freq arrays
    register_rest_route('multiplier-api/v1', '/freq-arrays', [
        'methods'  => 'GET',
        'callback' => 'multiplier_get_freq_arrays',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('multiplier-api/v1', '/freq-arrays', [
        'methods'  => 'POST',
        'callback' => 'multiplier_create_freq_array',
        'permission_callback' => 'multiplier_verify_nonce_permission',
    ]);
    register_rest_route('multiplier-api/v1', '/freq-arrays/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => 'multiplier_get_freq_array',
        'permission_callback' => '__return_true',
    ]);

    // Index arrays
    register_rest_route('multiplier-api/v1', '/index-arrays', [
        'methods'  => 'POST',
        'callback' => 'multiplier_create_index_array',
        'permission_callback' => 'multiplier_verify_nonce_permission',
    ]);
    register_rest_route('multiplier-api/v1', '/index-arrays/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => 'multiplier_get_index_array',
        'permission_callback' => '__return_true',
    ]);

    // Presets
    register_rest_route('multiplier-api/v1', '/presets', [
        'methods'  => 'POST',
        'callback' => 'multiplier_create_preset',
        'permission_callback' => 'multiplier_verify_nonce_permission',
    ]);
    register_rest_route('multiplier-api/v1', '/presets/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => 'multiplier_get_presets',
        'permission_callback' => '__return_true',
    ]);
});

/* ------------------------------------------------------------
 * Login Status Callback (WP + Patreon)
 * ------------------------------------------------------------ */
function multiplier_get_login_status(WP_REST_Request $request)
{
    $is_logged_in = is_user_logged_in();
    $is_admin     = current_user_can('manage_options');
    $user_id = get_current_user_id();

    $status = [
        'logged_in'         => (bool) $is_logged_in,
        'is_admin'          => (bool) $is_admin,
        'patreon_logged_in' => false,
        'tier'              => 'none',            // '$3_or_higher' | 'below_$3' | 'all_access' | 'none'
        'patreon_tier_cents' => null,
        'patreon_user_id'   => null,
        'patreon_email'     => null,
        'user_id'           => $user_id,
    ];

    if ($is_admin) {
        // Admins have all access by definition
        $status['tier'] = 'all_access';
    }

    if ($is_logged_in) {
        $wp_user_id = multiplier_current_user_id();

        // Pull Patreon info from user meta as primary, since the official plugin stores these
        $pledge_cents = (int) get_user_meta($wp_user_id, 'patreon_pledge_amount_cents', true);
        $patreon_id   = get_user_meta($wp_user_id, 'patreon_user_id', true);
        if (!$patreon_id) {
            // Some versions use 'patreon_user' as array/JSON
            $raw = get_user_meta($wp_user_id, 'patreon_user', true);
            if (is_array($raw) && isset($raw['data']['id'])) {
                $patreon_id = $raw['data']['id'];
            } elseif (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (isset($decoded['data']['id'])) {
                    $patreon_id = $decoded['data']['id'];
                }
            }
        }
        $patreon_email = get_user_meta($wp_user_id, 'patreon_email', true);

        // If the official class exposes a helper, try it defensively for richer data
        if (class_exists('Patreon_Wordpress') && method_exists('Patreon_Wordpress', 'getPatreonUser')) {
            $puser = Patreon_Wordpress::getPatreonUser();
            if (is_array($puser) && isset($puser['data']['id'])) {
                $status['patreon_logged_in'] = true;
                $status['patreon_user_id']   = $puser['data']['id'];
                if (!$patreon_email && isset($puser['data']['attributes']['email'])) {
                    $patreon_email = $puser['data']['attributes']['email'];
                }
                // Extract membership entitlement if present
                if (!empty($puser['included'])) {
                    foreach ($puser['included'] as $inc) {
                        if (($inc['type'] ?? '') === 'member') {
                            $cents = (int) ($inc['attributes']['currently_entitled_amount_cents'] ?? 0);
                            if ($cents > 0) {
                                $pledge_cents = $cents;
                            }
                            break;
                        }
                    }
                }
            }
        }

        if ($pledge_cents > 0 || $status['patreon_user_id'] || $patreon_id) {
            $status['patreon_logged_in'] = true;
        }

        $status['patreon_tier_cents'] = $pledge_cents ?: null;
        $status['patreon_user_id']    = $status['patreon_user_id'] ?: ($patreon_id ?: null);
        $status['patreon_email']      = $patreon_email ?: null;

        if ($is_admin) {
            $status['tier'] = 'all_access';
        } elseif ($pledge_cents >= 300) {
            $status['tier'] = '$3_or_higher';
        } elseif ($pledge_cents > 0) {
            $status['tier'] = 'below_$3';
        } else {
            $status['tier'] = 'none';
        }
    }

    return rest_ensure_response($status);
}

/* ------------------------------------------------------------
 * FREQ ARRAYS
 * ------------------------------------------------------------ */
function multiplier_get_freq_arrays()
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_freq_array';
    return $wpdb->get_results("SELECT * FROM $table");
}

function multiplier_create_freq_array(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_freq_array';

    $data = $request->get_json_params();

    $array_name = isset($data['array_name']) ? sanitize_text_field($data['array_name']) : '';
    $base_freq  = isset($data['base_freq']) ? floatval($data['base_freq']) : null;
    $multiplier = isset($data['multiplier']) ? floatval($data['multiplier']) : null;
    $user_id    = isset($data['user_id']) ? intval($data['user_id']) : multiplier_current_user_id();

    if ($array_name === '' || $base_freq === null || $multiplier === null || !$user_id) {
        return new WP_Error('missing_data', 'Required fields: array_name, base_freq, multiplier, user_id', ['status' => 400]);
    }

    $ok = $wpdb->insert(
        $table,
        [
            'array_name' => $array_name,
            'base_freq'  => $base_freq,
            'multiplier' => $multiplier,
            'user_id'    => $user_id,
        ],
        ['%s', '%f', '%f', '%d']
    );

    if ($ok === false) {
        return new WP_Error('db_insert_error', 'Could not insert frequency array', ['status' => 500]);
    }

    return ['success' => true, 'array_id' => (int) $wpdb->insert_id];
}

function multiplier_get_freq_array(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_freq_array';
    $id = intval($request['id']);
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $id));
}

/* ------------------------------------------------------------
 * INDEX ARRAYS
 * ------------------------------------------------------------ */
function multiplier_create_index_array(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_index_array';

    $data = $request->get_json_params();

    $index_array = isset($data['index_array']) ? sanitize_text_field($data['index_array']) : '';
    $array_name  = isset($data['array_name']) ? sanitize_text_field($data['array_name']) : '';
    $user_id     = isset($data['user_id']) ? intval($data['user_id']) : multiplier_current_user_id();

    if ($index_array === '' || $array_name === '' || !$user_id) {
        return new WP_Error('missing_data', 'Required fields: index_array, array_name, user_id', ['status' => 400]);
    }

    $ok = $wpdb->insert(
        $table,
        [
            'index_array' => $index_array,
            'array_name'  => $array_name,
            'user_id'     => $user_id,
        ],
        ['%s', '%s', '%d']
    );

    if ($ok === false) {
        return new WP_Error('db_insert_error', 'Could not insert index array', ['status' => 500]);
    }

    return ['success' => true, 'array_id' => (int) $wpdb->insert_id];
}

function multiplier_get_index_array(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_index_array';
    $id = intval($request['id']);
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $id));
}

/* ------------------------------------------------------------
 * PRESETS
 * ------------------------------------------------------------ */
function multiplier_create_preset(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_preset';

    $d = $request->get_json_params();

    $row = [
        'name'            => isset($d['name']) ? sanitize_text_field($d['name']) : null,
        'tempo'           => isset($d['tempo']) ? intval($d['tempo']) : null,
        'waveshape'       => isset($d['waveshape']) ? sanitize_text_field($d['waveshape']) : null,
        'duration'        => isset($d['duration']) ? floatval($d['duration']) : null,
        'lowpass_freq'    => isset($d['lowpass_freq']) ? intval($d['lowpass_freq']) : null,
        'lowpass_q'       => isset($d['lowpass_q']) ? intval($d['lowpass_q']) : null,
        'index_array_id'  => isset($d['index_array_id']) ? intval($d['index_array_id']) : null,
        'freq_array_id'   => isset($d['freq_array_id']) ? intval($d['freq_array_id']) : null,
        'multiplier_min'  => isset($d['multiplier_min']) ? floatval($d['multiplier_min']) : null,
        'multiplier_max'  => isset($d['multiplier_max']) ? floatval($d['multiplier_max']) : null,
        'multiplier_step' => isset($d['multiplier_step']) ? floatval($d['multiplier_step']) : null,
        'base_min'        => isset($d['base_min']) ? floatval($d['base_min']) : null,
        'base_max'        => isset($d['base_max']) ? floatval($d['base_max']) : null,
        'base_step'       => isset($d['base_step']) ? floatval($d['base_step']) : null,
        'user_id'         => isset($d['user_id']) ? intval($d['user_id']) : multiplier_current_user_id(),
    ];

    foreach ($row as $k => $v) {
        if ($v === null) {
            return new WP_Error('missing_data', 'Missing field: ' . $k, ['status' => 400]);
        }
    }

    $ok = $wpdb->insert(
        $table,
        $row,
        ['%s', '%d', '%s', '%f', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%f', '%d']
    );

    if ($ok === false) {
        return new WP_Error('db_insert_error', 'Could not insert preset', ['status' => 500]);
    }

    return ['success' => true, 'preset_id' => (int) $wpdb->insert_id];
}

function multiplier_get_presets(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_preset';
    $id = intval($request['id']);
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $id));
}
