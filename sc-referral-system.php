<?php
/*
 * Plugin Name: Star Citizen Referral Randomizer
 * Plugin URI: https://github.com/ulrichdahl/WP-StarReferrals
 * Description: A system that distributes Star Citizen referral codes fairly via AJAX, updates via Discord, and an admin panel.
 * Version: 1.4.0
 * Author: Ulrich Dahl <ulrich.dahl@gmail.com>
 * Author URI: https://github.com/ulrichdahl
 * License: GPL3
 * Text Domain: sc-referral-system
 * Domain Path: /languages
 * Tool: OpenCode, LM Studio, Gemma4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SC_REFERRAL_DB_VERSION', '2');

add_action('plugins_loaded', 'sc_referral_load_textdomain');
function sc_referral_load_textdomain() {
    load_plugin_textdomain(
            'sc-referral-system',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
    );
}

// ---------------------------------------------------------
// 1. DATABASE CREATION
// ---------------------------------------------------------
register_activation_hook(__FILE__, 'sc_referral_create_table');

function sc_referral_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sc_referrals';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		discord_user_id varchar(50) NOT NULL,
		discord_user_name varchar(255) NOT NULL,
		referral_code varchar(50) NOT NULL,
		usage_count mediumint(9) DEFAULT 0 NOT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY discord_user_id (discord_user_id),
		UNIQUE KEY referral_code (referral_code)
	) $charset_collate;";

    $gleam_table = $wpdb->prefix . 'sc_gleam_tokens';
    $gleam_sql = "CREATE TABLE $gleam_table (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		token char(32) NOT NULL,
		referral_id mediumint(9) NOT NULL,
		referral_code varchar(50) NOT NULL,
		rsi_handle varchar(60) DEFAULT NULL,
		status varchar(20) DEFAULT 'pending' NOT NULL,
		created_at datetime NOT NULL,
		verified_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		UNIQUE KEY rsi_handle (rsi_handle)
	) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    dbDelta($gleam_sql);

    update_option('sc_referral_db_version', SC_REFERRAL_DB_VERSION);
}

// Run the schema update for sites that upgraded without re-activating the plugin.
add_action('plugins_loaded', function () {
    if (get_option('sc_referral_db_version') !== SC_REFERRAL_DB_VERSION) {
        sc_referral_create_table();
    }
});

// ---------------------------------------------------------
// 2. ADMIN AREA
// ---------------------------------------------------------
$sc_menu_slug = 'star-citizen';

function sc_referral_menu_exists() {
    global $menu, $sc_menu_slug;
    if (!is_array($menu)) {
        return false;
    }
    foreach ($menu as $item) {
        if (isset($item[2]) && $item[2] === $sc_menu_slug) {
            return true;
        }
    }
    return false;
}

add_action('admin_menu', 'sc_referral_add_admin_menu');
add_action('admin_menu', function () {
    global $sc_menu_slug;
    if (sc_referral_menu_exists()) {
        remove_submenu_page($sc_menu_slug, $sc_menu_slug);
    }
}, 999);
add_action('admin_head', 'sc_referral_fix_svg_size');

function sc_referral_fix_svg_size() {
    global $sc_menu_slug;
    if (sc_referral_menu_exists()) {
        echo '
		<style>
			#toplevel_page_' . esc_attr($sc_menu_slug) . ' .wp-menu-image img {
				width: 20px !important;
				height: 20px !important;
				padding: 0 !important;
				margin: 0 !important;
				box-sizing: border-box;
				display: inline-block;
				vertical-align: middle;
			}

			#toplevel_page_' . esc_attr($sc_menu_slug) . ' .wp-menu-image {
				display: flex !important;
				align-items: center;
				justify-content: center;
			}
		</style>
		';
    }
}

function sc_referral_add_admin_menu() {
    if (!sc_referral_menu_exists()) {
        add_menu_page(
                __('Star Citizen', 'sc-referral-system'),
                __('Star Citizen', 'sc-referral-system'),
                'manage_options',
                'star-citizen',
                null,
                plugins_url('sc-referral-system/assets/scc-logo.svg', __FILE__),
                26
        );
    }

    add_submenu_page(
            'star-citizen',
            __('Star Citizen Referrals', 'sc-referral-system'),
            __('Referrals', 'sc-referral-system'),
            'manage_options',
            'sc-referrals',
            'sc_referral_options_page',
            5
    );
}

function sc_referral_options_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sc_referrals';

    if (isset($_POST['sc_reset_counts']) && check_admin_referer('sc_reset_action', 'sc_reset_nonce')) {
        $wpdb->query("UPDATE $table_name SET usage_count = 0");
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All counters were successfully reset to 0!', 'sc-referral-system') . '</p></div>';
    }

    if (isset($_POST['sc_delete_user']) && check_admin_referer('sc_delete_action', 'sc_delete_nonce')) {
        $wpdb->delete(
                $table_name,
                array('id' => intval($_POST['sc_user_id'])),
                array('%d')
        );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The user has been deleted!', 'sc-referral-system') . '</p></div>';
    }

    if (isset($_POST['sc_add_user']) && check_admin_referer('sc_add_action', 'sc_add_nonce')) {
        $discord_id = sanitize_text_field($_POST['sc_discord_id'] ?? '');
        $discord_name = sanitize_text_field($_POST['sc_discord_name'] ?? '');
        $referral_code = sanitize_text_field($_POST['sc_referral_code'] ?? '');

        if (empty($discord_id) || empty($discord_name) || empty($referral_code)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('ID, name, or code is missing!', 'sc-referral-system') . '</p></div>';
        } elseif (!preg_match('/^STAR-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $referral_code)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('The referral code has an invalid format!', 'sc-referral-system') . '</p></div>';
        } else {
            sc_add_user($discord_id, $discord_name, $referral_code);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The user was added to the list!', 'sc-referral-system') . '</p></div>';
        }
    }

    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY usage_count DESC, id ASC");
    ?>
    <div class="wrap sc-admin-wrap">
        <h1><?php echo esc_html__('Star Citizen Referral Overview', 'sc-referral-system'); ?></h1>
        <p class="description"><?php echo esc_html__('Here is a list of all registered Discord users and how many times their code has been shown.', 'sc-referral-system'); ?></p>

        <style>
            .sc-admin-wrap .sc-admin-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 16px;
                margin: 24px 0;
            }

            .sc-admin-wrap .sc-admin-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            }

            .sc-admin-wrap .sc-admin-card h2 {
                margin-top: 0;
                margin-bottom: 12px;
                font-size: 16px;
            }

            .sc-admin-wrap .sc-admin-card p {
                margin-top: 0;
                margin-bottom: 14px;
            }

            .sc-admin-wrap .sc-admin-card form {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .sc-admin-wrap .sc-field {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .sc-admin-wrap .sc-field input[type="text"] {
                width: 100%;
                max-width: 100%;
            }

            .sc-admin-wrap .sc-actions-row {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }

            .sc-admin-wrap .sc-code {
                background: #f6f7f7;
                padding: 4px 8px;
                border-radius: 4px;
                display: inline-block;
            }

            .sc-admin-wrap .sc-empty-state {
                text-align: center;
                color: #646970;
                padding: 18px 12px;
            }
        </style>

        <div class="sc-admin-grid">
            <div class="sc-admin-card">
                <h2><?php echo esc_html__('Add a User', 'sc-referral-system'); ?></h2>
                <p><?php echo esc_html__('Add a Discord user and assign a referral code.', 'sc-referral-system'); ?></p>
                <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to add this user?', 'sc-referral-system')); ?>');">
                    <?php wp_nonce_field('sc_add_action', 'sc_add_nonce'); ?>
                    <div class="sc-field">
                        <label for="sc_discord_id"><?php echo esc_html__('Discord ID:', 'sc-referral-system'); ?></label>
                        <input id="sc_discord_id" type="text" name="sc_discord_id" value="">
                    </div>
                    <div class="sc-field">
                        <label for="sc_discord_name"><?php echo esc_html__('Discord name:', 'sc-referral-system'); ?></label>
                        <input id="sc_discord_name" type="text" name="sc_discord_name" value="">
                    </div>
                    <div class="sc-field">
                        <label for="sc_referral_code"><?php echo esc_html__('Referral code:', 'sc-referral-system'); ?></label>
                        <input id="sc_referral_code" type="text" name="sc_referral_code" value="" placeholder="<?php echo esc_attr__('STAR-xxxx-xxxx', 'sc-referral-system'); ?>">
                    </div>
                    <div class="sc-actions-row">
                        <input type="submit" name="sc_add_user" class="button button-primary" value="<?php echo esc_attr__('Add User', 'sc-referral-system'); ?>">
                    </div>
                </form>
            </div>

            <div class="sc-admin-card">
                <h2><?php echo esc_html__('Reset Usage Counters', 'sc-referral-system'); ?></h2>
                <p><?php echo esc_html__('Reset the shown count for every referral code back to 0.', 'sc-referral-system'); ?></p>
                <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to reset the counter for ALL users?', 'sc-referral-system')); ?>');">
                    <?php wp_nonce_field('sc_reset_action', 'sc_reset_nonce'); ?>
                    <div class="sc-actions-row">
                        <input type="submit" name="sc_reset_counts" class="button button-primary" value="<?php echo esc_attr__('Reset All Counters', 'sc-referral-system'); ?>">
                    </div>
                </form>
            </div>

            <div class="sc-admin-card">
                <h2><?php echo esc_html__('Delete a User', 'sc-referral-system'); ?></h2>
                <p><?php echo esc_html__('Remove a user from the referral list by ID.', 'sc-referral-system'); ?></p>
                <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this user?', 'sc-referral-system')); ?>');">
                    <?php wp_nonce_field('sc_delete_action', 'sc_delete_nonce'); ?>
                    <div class="sc-field">
                        <label for="sc_user_id"><?php echo esc_html__('User ID:', 'sc-referral-system'); ?></label>
                        <input id="sc_user_id" type="text" name="sc_user_id" value="">
                    </div>
                    <div class="sc-actions-row">
                        <input type="submit" name="sc_delete_user" class="button button-primary" value="<?php echo esc_attr__('Delete User', 'sc-referral-system'); ?>">
                    </div>
                </form>
            </div>
        </div>

        <table class="widefat fixed striped">
            <thead>
            <tr>
                <th scope="col"><?php echo esc_html__('ID', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Discord User ID', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Discord User', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Referral Code', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Shown Count', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Created Date', 'sc-referral-system'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!empty($results)) : ?>
                <?php foreach ($results as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->id); ?></td>
                        <td><?php echo esc_html($row->discord_user_id); ?></td>
                        <td><?php echo esc_html($row->discord_user_name); ?></td>
                        <td><code class="sc-code"><?php echo esc_html($row->referral_code); ?></code></td>
                        <td><strong><?php echo esc_html($row->usage_count); ?></strong></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="6" class="sc-empty-state"><?php echo esc_html__('No referrals found yet.', 'sc-referral-system'); ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ---------------------------------------------------------
// 3. REST API FOR DISCORD
// ---------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('sc-referral/v1', '/add', array(
            'methods' => 'POST',
            'callback' => 'sc_referral_handle_discord_webhook',
            'permission_callback' => '__return_true',
    ));
});

function sc_add_user($discord_id, $discord_name, $referral_code) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sc_referrals';
    $min_usage = $wpdb->get_var("SELECT MIN(usage_count) FROM $table_name");

    if ($min_usage === null) {
        $min_usage = 0;
    }

    $sql = $wpdb->prepare(
            "INSERT INTO $table_name (discord_user_id, discord_user_name, referral_code, usage_count)
		VALUES (%s, %s, %s, %d)
		ON DUPLICATE KEY UPDATE
			referral_code = VALUES(referral_code),
			discord_user_id = VALUES(discord_user_id),
			discord_user_name = VALUES(discord_user_name)",
            $discord_id,
            $discord_name,
            $referral_code,
            $min_usage
    );

    return $wpdb->query($sql);
}

function sc_referral_handle_discord_webhook($request) {
    $params = $request->get_json_params();
    $discord_id = sanitize_text_field($params['discord_id'] ?? '');
    $discord_name = sanitize_text_field($params['discord_name'] ?? '');
    $referral_code = sanitize_text_field($params['referral_code'] ?? '');
    $api_secret = sanitize_text_field($params['secret'] ?? '');

    // REMEMBER TO CHANGE THIS SECRET KEY!
    $my_secret_key = '97dc6786b98892e4395963116153a4ca4ff84b3313f7e356c18e90d115b8bafd';

    if ($api_secret !== $my_secret_key) {
        return new WP_Error('no_auth', __('Invalid secret key', 'sc-referral-system'), array('status' => 403));
    }

    if (empty($discord_id) || empty($discord_name) || empty($referral_code)) {
        return new WP_Error('missing_data', __('Missing ID, name, or code', 'sc-referral-system'), array('status' => 400));
    }

    if (!preg_match('/^STAR-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $referral_code)) {
        return new WP_Error('invalid_format', __('Invalid code format', 'sc-referral-system'), array('status' => 400));
    }

    $result = sc_add_user($discord_id, $discord_name, $referral_code);

    if ($result === false) {
        return new WP_Error('db_error', __('Database error', 'sc-referral-system'), array('status' => 500));
    }

    return new WP_REST_Response(array('message' => __('Success', 'sc-referral-system')), 200);
}

// ---------------------------------------------------------
// 4. SHORTCODE AND FRONTEND
// ---------------------------------------------------------
add_shortcode('sc_referrals_button', 'sc_render_button');

function sc_render_button($atts) {
    wp_enqueue_script('jquery');

    $strings = array(
            'buttonText' => __('Create Star Citizen Account', 'sc-referral-system'),
            'loadingText' => __('Fetching code...', 'sc-referral-system'),
            'retryText' => __('Try again', 'sc-referral-system'),
            'errorLabel' => __('Error:', 'sc-referral-system'),
            'serverError' => __('Server error.', 'sc-referral-system'),
    );

    wp_register_script('sc-referral-inline', '', array('jquery'), '1.3.1', true);
    wp_enqueue_script('sc-referral-inline');
    wp_add_inline_script('sc-referral-inline', 'window.scReferralStrings = ' . wp_json_encode($strings) . ';', 'before');

    $style = '
	<style>
		.sc-btn {
			background-color: #00d4ff;
			color: #000;
			padding: 15px 30px;
			text-decoration: none;
			font-weight: bold;
			border-radius: 5px;
			cursor: pointer;
			display: inline-block;
			border: none;
			font-size: 16px;
		}
		.sc-btn:hover {
			background-color: #00a3cc;
		}
		.sc-loading {
			opacity: 0.6;
			cursor: wait;
		}
	</style>';

    $html = '<button id="get-sc-code" class="sc-btn">' . esc_html__('Create Star Citizen Account', 'sc-referral-system') . '</button>';

    $ajax_url = admin_url('admin-ajax.php');
    $script = '
	<script>
	jQuery(document).ready(function($) {
		$("#get-sc-code").click(function(e) {
			e.preventDefault();
			var btn = $(this);
			if (btn.hasClass("sc-loading")) return;

			btn.addClass("sc-loading").text(window.scReferralStrings.loadingText);

			$.ajax({
				url: ' . wp_json_encode($ajax_url) . ',
				type: "POST",
				data: { action: "get_sc_referral_code" },
				success: function(response) {
					if (response.success) {
						var url = "https://robertsspaceindustries.com/enlist?referral=" + response.data.code;
						window.location.href = url;
						btn.removeClass("sc-loading").text(window.scReferralStrings.buttonText);
					} else {
						alert(window.scReferralStrings.errorLabel + " " + response.data.message);
						btn.removeClass("sc-loading").text(window.scReferralStrings.retryText);
					}
				},
				error: function() {
					alert(window.scReferralStrings.serverError);
					btn.removeClass("sc-loading").text(window.scReferralStrings.retryText);
				}
			});
		});
	});
	</script>';

    return $style . $html . $script;
}

// ---------------------------------------------------------
// 5. AJAX LOGIC (FAIRNESS ALGORITHM)
// ---------------------------------------------------------
add_action('wp_ajax_get_sc_referral_code', 'sc_get_random_referral');
add_action('wp_ajax_nopriv_get_sc_referral_code', 'sc_get_random_referral');

/**
 * Picks a referral code among the least-used ones and increments its counter.
 *
 * @return object|WP_Error Row with id and referral_code.
 */
function sc_pick_referral() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sc_referrals';

    $min_usage = $wpdb->get_var("SELECT MIN(usage_count) FROM $table_name");

    if ($min_usage === null) {
        return new WP_Error('no_codes', __('No codes in the system.', 'sc-referral-system'));
    }

    $rows = $wpdb->get_results(
            $wpdb->prepare(
                    "SELECT id, referral_code FROM $table_name WHERE usage_count = %d ORDER BY RAND()",
                    $min_usage
            )
    );

    if (empty($rows)) {
        return new WP_Error('db_error', __('Database error.', 'sc-referral-system'));
    }

    $winner = $rows[array_rand($rows)];

    $wpdb->query(
            $wpdb->prepare(
                    "UPDATE $table_name SET usage_count = usage_count + 1 WHERE id = %d",
                    $winner->id
            )
    );

    return $winner;
}

function sc_get_random_referral() {
    $winner = sc_pick_referral();

    if (is_wp_error($winner)) {
        wp_send_json_error(array('message' => $winner->get_error_message()));
    }

    wp_send_json_success(array('code' => $winner->referral_code));
}

// ---------------------------------------------------------
// 6. GLEAM MODE (VERIFIED RSI SIGNUP STEP)
// ---------------------------------------------------------
// Flow: the Gleam custom action links to a page with [sc_referrals_gleam].
// 1. The visitor gets a token tied to a referral code (same fairness rules as the button)
//    and is sent to the RSI enlist page with that code.
// 2. The visitor comes back and enters their new RSI handle.
// 3. The plugin loads the public RSI citizen page and checks that the account exists
//    and was enlisted after the token was issued.
// 4. On success the page reports the action to Gleam via its JavaScript API tracking.

add_action('admin_menu', 'sc_gleam_add_admin_menu');
add_shortcode('sc_referrals_gleam', 'sc_gleam_render');

add_action('wp_ajax_sc_gleam_start', 'sc_gleam_ajax_start');
add_action('wp_ajax_nopriv_sc_gleam_start', 'sc_gleam_ajax_start');
add_action('wp_ajax_sc_gleam_status', 'sc_gleam_ajax_status');
add_action('wp_ajax_nopriv_sc_gleam_status', 'sc_gleam_ajax_status');
add_action('wp_ajax_sc_gleam_verify', 'sc_gleam_ajax_verify');
add_action('wp_ajax_nopriv_sc_gleam_verify', 'sc_gleam_ajax_verify');

function sc_gleam_table() {
    global $wpdb;
    return $wpdb->prefix . 'sc_gleam_tokens';
}

function sc_gleam_token_days() {
    return max(1, intval(get_option('sc_gleam_token_days', 7)));
}

function sc_gleam_enlist_url($code) {
    return 'https://robertsspaceindustries.com/enlist?referral=' . rawurlencode($code);
}

function sc_gleam_add_admin_menu() {
    add_submenu_page(
            'star-citizen',
            __('Star Citizen Referrals – Gleam', 'sc-referral-system'),
            __('Referrals: Gleam', 'sc-referral-system'),
            'manage_options',
            'sc-referrals-gleam',
            'sc_gleam_options_page',
            6
    );
}

function sc_gleam_options_page() {
    global $wpdb;
    $table = sc_gleam_table();

    if (isset($_POST['sc_gleam_save']) && check_admin_referer('sc_gleam_save_action', 'sc_gleam_save_nonce')) {
        update_option('sc_gleam_action_name', sanitize_text_field(wp_unslash($_POST['sc_gleam_action_name'] ?? '')));
        update_option('sc_gleam_token_days', max(1, intval($_POST['sc_gleam_token_days'] ?? 7)));
        // The tracking snippet is raw HTML/JS from Gleam, so only users allowed to post unfiltered HTML may change it.
        if (current_user_can('unfiltered_html')) {
            update_option('sc_gleam_snippet', wp_unslash($_POST['sc_gleam_snippet'] ?? ''));
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Gleam settings saved.', 'sc-referral-system') . '</p></div>';
    }

    if (isset($_POST['sc_gleam_delete']) && check_admin_referer('sc_gleam_delete_action', 'sc_gleam_delete_nonce')) {
        $wpdb->delete($table, array('id' => intval($_POST['sc_gleam_token_id'])), array('%d'));
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The token has been deleted!', 'sc-referral-system') . '</p></div>';
    }

    $results = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Gleam Mode', 'sc-referral-system'); ?></h1>
        <p class="description">
            <?php echo esc_html__('Put the [sc_referrals_gleam] shortcode on a page and use that page as the link in a Gleam custom action with API tracking. The action is only reported to Gleam after the visitor’s new RSI account has been verified.', 'sc-referral-system'); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field('sc_gleam_save_action', 'sc_gleam_save_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="sc_gleam_action_name"><?php echo esc_html__('Gleam action name', 'sc-referral-system'); ?></label></th>
                    <td>
                        <input id="sc_gleam_action_name" class="regular-text" type="text" name="sc_gleam_action_name" value="<?php echo esc_attr(get_option('sc_gleam_action_name', '')); ?>">
                        <p class="description"><?php echo esc_html__('Must match the action name configured for API tracking in your Gleam campaign exactly.', 'sc-referral-system'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sc_gleam_snippet"><?php echo esc_html__('Gleam tracking snippet', 'sc-referral-system'); ?></label></th>
                    <td>
                        <textarea id="sc_gleam_snippet" class="large-text code" rows="5" name="sc_gleam_snippet" <?php disabled(!current_user_can('unfiltered_html')); ?>><?php echo esc_textarea(get_option('sc_gleam_snippet', '')); ?></textarea>
                        <p class="description"><?php echo esc_html__('Paste the tracking/embed script Gleam gives you for API tracking. It is printed on the shortcode page.', 'sc-referral-system'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sc_gleam_token_days"><?php echo esc_html__('Token lifetime (days)', 'sc-referral-system'); ?></label></th>
                    <td>
                        <input id="sc_gleam_token_days" class="small-text" type="number" min="1" name="sc_gleam_token_days" value="<?php echo esc_attr(sc_gleam_token_days()); ?>">
                        <p class="description"><?php echo esc_html__('How long a visitor has to create the account and verify their handle.', 'sc-referral-system'); ?></p>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="sc_gleam_save" class="button button-primary" value="<?php echo esc_attr__('Save Settings', 'sc-referral-system'); ?>"></p>
        </form>

        <h2><?php echo esc_html__('Gleam Tokens', 'sc-referral-system'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
            <tr>
                <th scope="col"><?php echo esc_html__('ID', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Referral Code', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('RSI Handle', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Status', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Created (UTC)', 'sc-referral-system'); ?></th>
                <th scope="col"><?php echo esc_html__('Verified (UTC)', 'sc-referral-system'); ?></th>
                <th scope="col"></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!empty($results)) : ?>
                <?php foreach ($results as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->id); ?></td>
                        <td><code><?php echo esc_html($row->referral_code); ?></code></td>
                        <td><?php echo esc_html($row->rsi_handle ?? ''); ?></td>
                        <td><?php echo esc_html($row->status); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td><?php echo esc_html($row->verified_at ?? ''); ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this token?', 'sc-referral-system')); ?>');">
                                <?php wp_nonce_field('sc_gleam_delete_action', 'sc_gleam_delete_nonce'); ?>
                                <input type="hidden" name="sc_gleam_token_id" value="<?php echo esc_attr($row->id); ?>">
                                <input type="submit" name="sc_gleam_delete" class="button button-small" value="<?php echo esc_attr__('Delete', 'sc-referral-system'); ?>">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="7"><?php echo esc_html__('No Gleam tokens yet.', 'sc-referral-system'); ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Loads a token row if it exists and has not expired (verified tokens never expire).
 */
function sc_gleam_get_token($token) {
    global $wpdb;
    $table = sc_gleam_table();

    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token = %s", $token));
    if (!$row) {
        return null;
    }

    $expired = strtotime($row->created_at . ' UTC') + sc_gleam_token_days() * DAY_IN_SECONDS < time();
    if ($row->status !== 'verified' && $expired) {
        return null;
    }

    return $row;
}

function sc_gleam_token_response($row) {
    return array(
            'token' => $row->token,
            'status' => $row->status,
            'enlistUrl' => sc_gleam_enlist_url($row->referral_code),
            'handle' => $row->rsi_handle,
            'action' => get_option('sc_gleam_action_name', ''),
    );
}

function sc_gleam_ajax_status() {
    $row = sc_gleam_get_token(sanitize_text_field(wp_unslash($_POST['token'] ?? '')));
    if (!$row) {
        wp_send_json_error(array('message' => __('Unknown or expired token.', 'sc-referral-system')));
    }
    wp_send_json_success(sc_gleam_token_response($row));
}

function sc_gleam_ajax_start() {
    global $wpdb;

    // Reuse an existing token so repeated clicks don't skew the fairness counters.
    $existing = sc_gleam_get_token(sanitize_text_field(wp_unslash($_POST['token'] ?? '')));
    if ($existing) {
        wp_send_json_success(sc_gleam_token_response($existing));
    }

    $winner = sc_pick_referral();
    if (is_wp_error($winner)) {
        wp_send_json_error(array('message' => $winner->get_error_message()));
    }

    $token = bin2hex(random_bytes(16));
    $inserted = $wpdb->insert(
            sc_gleam_table(),
            array(
                    'token' => $token,
                    'referral_id' => $winner->id,
                    'referral_code' => $winner->referral_code,
                    'status' => 'pending',
                    'created_at' => current_time('mysql', true),
            ),
            array('%s', '%d', '%s', '%s', '%s')
    );

    if (!$inserted) {
        wp_send_json_error(array('message' => __('Database error.', 'sc-referral-system')));
    }

    wp_send_json_success(sc_gleam_token_response(sc_gleam_get_token($token)));
}

/**
 * Fetches the public RSI citizen page and returns the enlisted date as a UTC timestamp.
 *
 * @return int|WP_Error
 */
function sc_gleam_fetch_enlisted($handle) {
    $response = wp_remote_get(
            'https://robertsspaceindustries.com/citizens/' . rawurlencode($handle),
            array(
                    'timeout' => 15,
                    'redirection' => 3,
                    'user-agent' => 'Mozilla/5.0 (compatible; SC-Referral-System; +' . home_url('/') . ')',
                    'headers' => array('Accept-Language' => 'en-US,en;q=0.9'),
            )
    );

    if (is_wp_error($response)) {
        return new WP_Error('rsi_unreachable', __('Could not reach the RSI website. Please try again later.', 'sc-referral-system'));
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code === 404) {
        return new WP_Error('handle_not_found', __('No RSI account with that handle was found. Check the spelling – it is your handle, not your email or display name.', 'sc-referral-system'));
    }
    if ($code !== 200) {
        return new WP_Error('rsi_unreachable', __('Could not reach the RSI website. Please try again later.', 'sc-referral-system'));
    }

    $timestamp = false;
    $body = wp_remote_retrieve_body($response);
    if (preg_match('/Enlisted\s*<\/span>\s*<strong[^>]*>\s*([^<]+?)\s*<\/strong>/i', $body, $m)) {
        $timestamp = strtotime(html_entity_decode($m[1]) . ' UTC');
    }

    // Lets site owners patch the parsing if RSI changes its page markup.
    $timestamp = apply_filters('sc_gleam_enlisted_timestamp', $timestamp, $body, $handle);

    if (!$timestamp) {
        return new WP_Error('parse_failed', __('Could not read the RSI profile. Please try again later.', 'sc-referral-system'));
    }

    return $timestamp;
}

function sc_gleam_ajax_verify() {
    global $wpdb;
    $table = sc_gleam_table();

    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $handle = trim(sanitize_text_field(wp_unslash($_POST['handle'] ?? '')));

    $row = sc_gleam_get_token($token);
    if (!$row) {
        wp_send_json_error(array('message' => __('Unknown or expired token. Please start again.', 'sc-referral-system')));
    }
    if ($row->status === 'verified') {
        wp_send_json_success(sc_gleam_token_response($row));
    }

    if (!preg_match('/^[A-Za-z0-9_-]{3,60}$/', $handle)) {
        wp_send_json_error(array('message' => __('That does not look like a valid RSI handle.', 'sc-referral-system')));
    }

    // Each token gets a limited number of attempts per 10 minutes, so the RSI site isn't hammered.
    $rate_key = 'sc_gleam_rl_' . $row->id;
    $attempts = intval(get_transient($rate_key));
    if ($attempts >= 5) {
        wp_send_json_error(array('message' => __('Too many attempts. Please wait a few minutes and try again.', 'sc-referral-system')));
    }
    set_transient($rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

    $taken = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE rsi_handle = %s AND id <> %d",
            strtolower($handle),
            $row->id
    ));
    if ($taken) {
        wp_send_json_error(array('message' => __('That RSI handle has already been used.', 'sc-referral-system')));
    }

    $enlisted = sc_gleam_fetch_enlisted($handle);
    if (is_wp_error($enlisted)) {
        wp_send_json_error(array('message' => $enlisted->get_error_message()));
    }

    // RSI only shows the enlist date (no time), so allow one day of slack for time zones.
    $issued_day = strtotime(gmdate('Y-m-d', strtotime($row->created_at . ' UTC')) . ' 00:00:00 UTC');
    if ($enlisted < $issued_day - DAY_IN_SECONDS) {
        wp_send_json_error(array('message' => __('That RSI account was created before you started this step. Only new accounts created with the referral link count.', 'sc-referral-system')));
    }

    $updated = $wpdb->update(
            $table,
            array(
                    'rsi_handle' => strtolower($handle),
                    'status' => 'verified',
                    'verified_at' => current_time('mysql', true),
            ),
            array('id' => $row->id, 'status' => 'pending'),
            array('%s', '%s', '%s'),
            array('%d', '%s')
    );

    // A failed update means the unique handle index was hit by a concurrent request.
    if (!$updated) {
        wp_send_json_error(array('message' => __('That RSI handle has already been used.', 'sc-referral-system')));
    }

    wp_send_json_success(sc_gleam_token_response(sc_gleam_get_token($token)));
}

function sc_gleam_render($atts) {
    wp_enqueue_script('jquery');

    $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'strings' => array(
                    'loadingText' => __('Fetching code...', 'sc-referral-system'),
                    'verifyingText' => __('Checking your RSI account...', 'sc-referral-system'),
                    'serverError' => __('Server error.', 'sc-referral-system'),
                    'enterHandle' => __('Please enter your RSI handle.', 'sc-referral-system'),
            ),
    );

    wp_register_script('sc-gleam-inline', '', array('jquery'), '1.4.0', true);
    wp_enqueue_script('sc-gleam-inline');
    wp_add_inline_script('sc-gleam-inline', 'window.scGleamConfig = ' . wp_json_encode($config) . ';', 'before');

    $style = '
	<style>
		.sc-gleam { max-width: 520px; }
		.sc-gleam-step { margin-bottom: 20px; }
		.sc-gleam .sc-btn {
			background-color: #00d4ff;
			color: #000;
			padding: 15px 30px;
			text-decoration: none;
			font-weight: bold;
			border-radius: 5px;
			cursor: pointer;
			display: inline-block;
			border: none;
			font-size: 16px;
		}
		.sc-gleam .sc-btn:hover { background-color: #00a3cc; }
		.sc-gleam .sc-loading { opacity: 0.6; cursor: wait; }
		.sc-gleam input[type="text"] { width: 100%; max-width: 320px; padding: 10px; font-size: 16px; margin-bottom: 10px; }
		.sc-gleam-message { margin-top: 10px; }
		.sc-gleam-error { color: #d63638; }
		.sc-gleam-success { color: #00a32a; font-weight: bold; }
	</style>';

    $html = '
	<div class="sc-gleam">
		<div class="sc-gleam-step" id="sc-gleam-step1">
			<p>' . esc_html__('Step 1: Create your Star Citizen account using our referral link.', 'sc-referral-system') . '</p>
			<button type="button" id="sc-gleam-start" class="sc-btn">' . esc_html__('Create Star Citizen Account', 'sc-referral-system') . '</button>
		</div>
		<div class="sc-gleam-step" id="sc-gleam-step2" style="display:none">
			<p>' . esc_html__('Step 2: When your account is created, enter your RSI handle to complete the step.', 'sc-referral-system') . '</p>
			<input type="text" id="sc-gleam-handle" autocomplete="off" placeholder="' . esc_attr__('Your RSI handle', 'sc-referral-system') . '">
			<br>
			<button type="button" id="sc-gleam-verify" class="sc-btn">' . esc_html__('Verify Account', 'sc-referral-system') . '</button>
		</div>
		<div class="sc-gleam-message" id="sc-gleam-message"></div>
	</div>';

    $done_text = esc_js(__('Your account is verified and the Gleam step is completed!', 'sc-referral-system'));
    $start_text = esc_js(__('Create Star Citizen Account', 'sc-referral-system'));
    $verify_text = esc_js(__('Verify Account', 'sc-referral-system'));

    $script = '
	<script>
	jQuery(document).ready(function($) {
		var cfg = window.scGleamConfig;
		var storageKey = "scGleamToken";
		var msg = $("#sc-gleam-message");

		function getToken() {
			try { return window.localStorage.getItem(storageKey) || ""; } catch (e) { return ""; }
		}
		function setToken(t) {
			try { window.localStorage.setItem(storageKey, t); } catch (e) {}
		}
		function showError(text) {
			msg.removeClass("sc-gleam-success").addClass("sc-gleam-error").text(text);
		}

		// Reports the completed action to Gleam. Supports both the gleam.track() API and the
		// older Gleam.push() queue, and polls because the Gleam script loads asynchronously.
		function reportToGleam(data) {
			if (!data.action) return;
			window.Gleam = window.Gleam || [];
			window.Gleam.push([data.action, data.handle]);
			var tries = 0;
			(function track() {
				if (window.gleam && typeof window.gleam.track === "function") {
					window.gleam.track(data.action);
				} else if (tries++ < 30) {
					setTimeout(track, 500);
				}
			})();
			document.dispatchEvent(new CustomEvent("sc-gleam-verified", { detail: data }));
		}

		function render(data) {
			setToken(data.token);
			if (data.status === "verified") {
				$("#sc-gleam-step1, #sc-gleam-step2").hide();
				msg.removeClass("sc-gleam-error").addClass("sc-gleam-success").text("' . $done_text . '");
				reportToGleam(data);
			} else {
				$("#sc-gleam-step2").show();
			}
		}

		if (getToken()) {
			$.post(cfg.ajaxUrl, { action: "sc_gleam_status", token: getToken() }, function(response) {
				if (response.success) render(response.data);
			});
		}

		$("#sc-gleam-start").click(function(e) {
			e.preventDefault();
			var btn = $(this);
			if (btn.hasClass("sc-loading")) return;
			btn.addClass("sc-loading").text(cfg.strings.loadingText);

			// Open the tab synchronously in the click handler so popup blockers allow it;
			// this page stays open for step 2.
			var win = window.open("", "_blank");

			$.ajax({
				url: cfg.ajaxUrl,
				type: "POST",
				data: { action: "sc_gleam_start", token: getToken() },
				success: function(response) {
					btn.removeClass("sc-loading").text("' . $start_text . '");
					if (!response.success) {
						if (win) win.close();
						showError(response.data.message);
						return;
					}
					render(response.data);
					if (win) {
						win.location.href = response.data.enlistUrl;
					} else {
						window.location.href = response.data.enlistUrl;
					}
				},
				error: function() {
					if (win) win.close();
					btn.removeClass("sc-loading").text("' . $start_text . '");
					showError(cfg.strings.serverError);
				}
			});
		});

		$("#sc-gleam-verify").click(function(e) {
			e.preventDefault();
			var btn = $(this);
			if (btn.hasClass("sc-loading")) return;
			var handle = $.trim($("#sc-gleam-handle").val());
			if (!handle) {
				showError(cfg.strings.enterHandle);
				return;
			}
			btn.addClass("sc-loading").text(cfg.strings.verifyingText);
			msg.text("");

			$.ajax({
				url: cfg.ajaxUrl,
				type: "POST",
				data: { action: "sc_gleam_verify", token: getToken(), handle: handle },
				success: function(response) {
					btn.removeClass("sc-loading").text("' . $verify_text . '");
					if (response.success) {
						render(response.data);
					} else {
						showError(response.data.message);
					}
				},
				error: function() {
					btn.removeClass("sc-loading").text("' . $verify_text . '");
					showError(cfg.strings.serverError);
				}
			});
		});
	});
	</script>';

    return $style . $html . get_option('sc_gleam_snippet', '') . $script;
}
