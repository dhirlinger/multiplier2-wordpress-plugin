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

function multiplier_setup_table()
{
    global $wpdb;

    $index_array_table = $wpdb->prefix . 'multiplier_index_array';
    $freq_array_table  = $wpdb->prefix . 'multiplier_freq_array';
    $preset_table      = $wpdb->prefix . 'multiplier_preset';
    $charset_collate   = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE $index_array_table (
            preset_number   SMALLINT UNSIGNED NOT NULL,  
            array_id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(50) NOT NULL,
            index_array VARCHAR(25) NOT NULL,
            user_id smallint(9) NOT NULL,
            PRIMARY KEY  (array_id),
            KEY  (user_id)
        ) $charset_collate;

        CREATE TABLE $freq_array_table (
            preset_number   SMALLINT UNSIGNED NOT NULL,
            array_id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(50) NOT NULL,
            base_freq DOUBLE,
            multiplier DOUBLE,
            params_json   JSON NOT NULL,
            user_id smallint(9) NOT NULL,
            PRIMARY KEY  (array_id),
            KEY  (user_id)
        ) $charset_collate;

        CREATE TABLE $preset_table (
            preset_number   SMALLINT UNSIGNED NOT NULL,
            preset_id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(25),
            params_json   JSON NOT NULL,
            user_id smallint(9) NOT NULL,
            PRIMARY KEY (preset_id),
            KEY  user_id (user_id)
        ) $charset_collate;
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Add FKs only once
    // $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'fk_preset_index'", $preset_table));
    // if (!$exists) {
    //     $wpdb->query("ALTER TABLE $preset_table ADD CONSTRAINT fk_preset_index FOREIGN KEY (index_array_id) REFERENCES $index_array_table(array_id)");
    // }
    // $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'fk_preset_freq'", $preset_table));
    // if (!$exists) {
    //     $wpdb->query("ALTER TABLE $preset_table ADD CONSTRAINT fk_preset_freq FOREIGN KEY (freq_array_id) REFERENCES $freq_array_table(array_id)");
    // }
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
    register_rest_route('multiplier-api/v1', '/freq-arrays/delete/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'multiplier_delete_freq_array',
        'permission_callback' => 'multiplier_verify_nonce_permission',
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
    register_rest_route('multiplier-api/v1', '/index-arrays/delete/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'multiplier_delete_index_array',
        'permission_callback' => 'multiplier_verify_nonce_permission',
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
    register_rest_route('multiplier-api/v1', '/presets/delete/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'multiplier_delete_preset',
        'permission_callback' => 'multiplier_verify_nonce_permission',
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

    $row = [
        'name'            => isset($data['name']) ? sanitize_text_field($data['name']) : null,
        'preset_number'   => isset($data['preset_number']) ? sanitize_text_field($data['preset_number']) : null,
        'base_freq'  => isset($data['base_freq']) ? floatval($data['base_freq']) : null,
        'multiplier'   => isset($data['multiplier']) ? floatval($data['multiplier']) : null,
        'params_json'     => isset($data['params_json']) ? wp_json_encode($data['params_json']) : null,
        'user_id'         => isset($data['user_id']) ? intval($data['user_id']) : multiplier_current_user_id(),
    ];

    foreach ($row as $k => $v) {
        if ($v === null) {
            return new WP_Error('missing_data', 'Missing field: ' . $k, ['status' => 400]);
        }
    }

    $selected_preset_number = $row["preset_number"];
    $selected_user_id = $row['user_id'];
    $contains_preset_number = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE preset_number = %d AND user_id = %d", $selected_preset_number, $selected_user_id));
    $selected_user_id = $row['user_id'];

    $have_same_preset_num = $contains_preset_number->preset_number == $selected_preset_number;
    $have_same_user = $contains_preset_number->user_id == $selected_user_id;

    if (!$have_same_preset_num) {
        $ok = $wpdb->insert(
            $table,
            $row,
            ['%s', '%d', '%f', '%f', '%s', '%d']
        );

        if ($ok === false) {
            return new WP_Error('db_insert_error', 'Could not insert frequency array', ['status' => 500]);
        }
    } else if ($have_same_preset_num) {

        $where = array('preset_number' => $selected_preset_number, 'user_id' => $selected_user_id);

        $ok = $wpdb->update(
            $table,
            $row,
            $where
        );

        if ($ok === false) {
            return new WP_Error('db_insert_error', 'Could not insert preset', ['status' => 500]);
        }
    }

    $updated_data =  $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $row["user_id"]));

    foreach ($updated_data as $row) {
        if (isset($row->params_json)) {
            $row->params_json = json_decode($row->params_json, true);
        }
    }

    return ['row' => $contains_preset_number, 'success' => true, 'array_id' => (int) $wpdb->insert_id, 'updated_data' => $updated_data];
}

function multiplier_get_freq_array(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_freq_array';
    $id = intval($request['id']);
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $id));
    // decode JSON for output 
    foreach ($results as $row) {
        if (isset($row->params_json)) {
            $row->params_json = json_decode($row->params_json, true);
        }
    }
    return $results;
}

function multiplier_delete_freq_array(WP_REST_Request $request)
{
    if (is_user_logged_in()) {
        $current_user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'multiplier_freq_array';
        $id = intval($request['id']);

        $wpdb->delete($table, array('array_id' => $id), array('%d'));

        $updated_data =  $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $current_user_id));

        foreach ($updated_data as $row) {
            if (isset($row->params_json)) {
                $row->params_json = json_decode($row->params_json, true);
            }
        }

        return ['success' => true, 'updated_data' => $updated_data];
    }
    return ['user_logged_in' => false];
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
    $name  = isset($data['name']) ? sanitize_text_field($data['name']) : '';
    $preset_number = isset($data['preset_number']) ? intval($data['preset_number']) : '';
    $user_id     = isset($data['user_id']) ? intval($data['user_id']) : multiplier_current_user_id();

    if ($index_array === '' || $name === '' || $preset_number === '' || !$user_id) {
        return new WP_Error('missing_data', 'Required fields: index_array, name, preset_number, user_id', ['status' => 400]);
    }

    $ok = $wpdb->insert(
        $table,
        [
            'index_array' => $index_array,
            'name'  => $name,
            'preset_number' => $preset_number,
            'user_id'     => $user_id,
        ],
        ['%s', '%s', '%d', '%d']
    );

    if ($ok === false) {
        return new WP_Error('db_insert_error', 'Could not insert index array', ['status' => 500]);
    }

    $updated_data =  $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $user_id));
    return ['success' => true, 'array_id' => (int) $wpdb->insert_id, 'updated_data' => $updated_data];
}

function multiplier_get_index_array(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_index_array';
    $id = intval($request['id']);
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $id));
}

function multiplier_delete_index_array(WP_REST_Request $request)
{
    if (is_user_logged_in()) {
        $current_user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'multiplier_index_array';
        $id = intval($request['id']);

        $wpdb->delete($table, array('array_id' => $id), array('%d'));

        $updated_data =  $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $current_user_id));

        foreach ($updated_data as $row) {
            if (isset($row->params_json)) {
                $row->params_json = json_decode($row->params_json, true);
            }
        }

        return ['success' => true, 'updated_data' => $updated_data];
    }
    return ['user_logged_in' => false];
}

/* ------------------------------------------------------------
 * GLOBAL PRESETS
 * ------------------------------------------------------------ */
function multiplier_create_preset(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_preset';

    $data = $request->get_json_params();

    $row = [
        'name'            => isset($data['name']) ? sanitize_text_field($data['name']) : null,
        'preset_number'   => isset($data['preset_number']) ? sanitize_text_field($data['preset_number']) : null,
        'params_json'     => isset($data['params_json']) ? wp_json_encode($data['params_json']) : null,
        'user_id'         => isset($data['user_id']) ? intval($data['user_id']) : multiplier_current_user_id(),
    ];

    foreach ($row as $k => $v) {
        if ($v === null) {
            return new WP_Error('missing_data', 'Missing field: ' . $k, ['status' => 400]);
        }
    }

    $selected_preset_number = $row["preset_number"];
    $selected_user_id = $row['user_id'];
    $contains_preset_number = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE preset_number = %d AND user_id = %d", $selected_preset_number, $selected_user_id));
    $selected_user_id = $row['user_id'];

    $have_same_preset_num = $contains_preset_number->preset_number == $selected_preset_number;
    $have_same_user = $contains_preset_number->user_id == $selected_user_id;
    $have_same_preset_num_and_different_user = $have_same_preset_num && !$have_same_user;

    if (!$have_same_preset_num) {

        $ok = $wpdb->insert(
            $table,
            $row,
            ['%s', '%d', '%s', '%d']
        );

        if ($ok === false) {
            return new WP_Error('db_insert_error', 'Could not insert preset', ['status' => 500]);
        }
    } else if ($have_same_preset_num) {

        $where = array('preset_number' => $selected_preset_number, 'user_id' => $selected_user_id);

        $ok = $wpdb->update(
            $table,
            $row,
            $where
        );

        if ($ok === false) {
            return new WP_Error('db_insert_error', 'Could not insert preset', ['status' => 500]);
        }
    }

    $updated_data =  $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $row["user_id"]));

    foreach ($updated_data as $row) {
        if (isset($row->params_json)) {
            $row->params_json = json_decode($row->params_json, true);
        }
    }
    //'array_id' => (int) $wpdb->insert_id,
    return ['row' => $contains_preset_number, 'success' => true, 'updated_data' => $updated_data];
}

function multiplier_get_presets(WP_REST_Request $request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'multiplier_preset';
    $id = intval($request['id']);
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $id));
    // decode JSON for output
    foreach ($results as $row) {
        if (isset($row->params_json)) {
            $row->params_json = json_decode($row->params_json, true);
        }
    }
    return $results;
}

function multiplier_delete_preset(WP_REST_Request $request)
{
    if (is_user_logged_in()) {
        $current_user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'multiplier_preset';
        $id = intval($request['id']);

        $wpdb->delete($table, array('preset_id' => $id), array('%d'));

        $updated_data =  $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $current_user_id));

        foreach ($updated_data as $row) {
            if (isset($row->params_json)) {
                $row->params_json = json_decode($row->params_json, true);
            }
        }

        return ['success' => true, 'updated_data' => $updated_data];
    }

    return ['user_logged_in' => false];
}
