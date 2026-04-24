<?php
/*
 * Plugin Name: Star Citizen Referral Randomizer
 * Plugin URI: https://github.com/ulrichdahl/WP-StarReferrals
 * Description: A system that distributes Star Citizen referral codes fairly via AJAX, updates via Discord, and an admin panel.
 * Version: 1.3.2
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

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

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

function sc_get_random_referral() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sc_referrals';

    $min_usage = $wpdb->get_var("SELECT MIN(usage_count) FROM $table_name");

    if ($min_usage === null) {
        wp_send_json_error(array('message' => __('No codes in the system.', 'sc-referral-system')));
    }

    $rows = $wpdb->get_results(
            $wpdb->prepare(
                    "SELECT id, referral_code FROM $table_name WHERE usage_count = %d ORDER BY RAND()",
                    $min_usage
            )
    );

    if (empty($rows)) {
        wp_send_json_error(array('message' => __('Database error.', 'sc-referral-system')));
    }

    $winner = $rows[array_rand($rows)];

    $wpdb->query(
            $wpdb->prepare(
                    "UPDATE $table_name SET usage_count = usage_count + 1 WHERE id = %d",
                    $winner->id
            )
    );

    wp_send_json_success(array('code' => $winner->referral_code));
}
