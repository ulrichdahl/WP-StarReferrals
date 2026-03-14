<?php

/*
Plugin Name: Star Citizen Referral Randomizer
Plugin URI: https://github.com/ulrichdahl/sc-referral-system
GitHub Plugin URI: https://github.com/ulrichdahl/sc-referral-system
Description: Et system der fordeler Star Citizen referral codes retfærdigt via AJAX, opdatering via Discord, og Admin panel.
Version: 1.3
Author: Ulrich Dahl <ulrich.dahl@gmail.com>
Author URI: https://github.com/ulrichdahl
License: GPL2
*/

if (!defined('ABSPATH')) exit;

// ---------------------------------------------------------
// 1. DATABASE OPRETTELSE
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

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

// ---------------------------------------------------------
// 2. ADMIN SIDE (NYT: Vis liste og Nulstil knap)
// ---------------------------------------------------------
add_action('admin_menu', 'sc_referral_add_admin_menu');

function sc_referral_add_admin_menu() {
	// Tilføjer menu under "Indstillinger" eller som hovedmenu
	add_menu_page(
		'Star Citizen Referrals', // Side titel
		'SC Referrals',           // Menu titel
		'manage_options',         // Rettighed krævet
		'sc-referrals',           // Menu slug
		'sc_referral_options_page', // Callback funktion
		'dashicons-groups',       // Ikon
		25                         // Position
	);
}

function sc_referral_options_page() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sc_referrals';

	// A. Håndter Nulstil-knap handling
	if (isset($_POST['sc_reset_counts']) && check_admin_referer('sc_reset_action', 'sc_reset_nonce')) {
		$wpdb->query("UPDATE $table_name SET usage_count = 0");
		echo '<div class="notice notice-success is-dismissible"><p>Alle tællere er succesfuldt nulstillet til 0!</p></div>';
	}

	if (isset($_POST['sc_delete_user']) && check_admin_referer('sc_delete_action', 'sc_delete_nonce')) {
		$wpdb->query("DELETE FROM $table_name WHERE id = ".intval($_POST['sc_user_id']));
		echo '<div class="notice notice-success is-dismissible"><p>Brugeren er blevet slettet!</p></div>';
	}

	if (isset($_POST['sc_add_user']) && check_admin_referer('sc_add_action', 'sc_add_nonce')) {
		if (empty($_POST['sc_discord_id']) || empty($_POST['sc_discord_name']) || empty($_POST['sc_referral_code'])) {
			echo '<div class="notice notice-error is-dismissible"><p>Der mangler id, navn eller kode!</p></div>';
		}
		elseif (!preg_match('/^STAR-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $_POST['sc_referral_code'])) {
			echo '<div class="notice notice-error is-dismissible"><p>Referral koden er ikke korrekt format!</p></div>';
		}
		else {
			sc_add_user($_POST['sc_discord_id'], $_POST['sc_discord_name'], $_POST['sc_referral_code']);
			echo '<div class="notice notice-success is-dismissible"><p>Brugeren blev tilføjet listen!</p></div>';
		}
	}

	// B. Hent data fra databasen
	$results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY usage_count DESC, id ASC");

	// C. Vis Admin Siden
	?>
	<div class="wrap">
		<h1>Star Citizen Referral Oversigt</h1>
		<p>Her er en liste over alle tilmeldte Discord brugere og hvor mange gange deres kode er blevet vist.</p>

		<div style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
			<div style="display: flex; justify-content: space-between; align-content: flex-start">
				<div style="display: inline-block">
					<p>Vil du tilføje en ny bruger.</p>
					<form method="post" onsubmit="return confirm('Er du sikker på, at du vil tilføje denne bruger?');">
						<?php wp_nonce_field('sc_add_action', 'sc_add_nonce'); ?>
						<label>Discord ID: <input type="text" name="sc_discord_id" value=""></label><br/>
						<label>Discord navn: <input type="text" name="sc_discord_name" value=""></label><br/>
						<label>Referral kode: <input type="text" name="sc_referral_code" value="" placeholder="STAR-xxxx-xxxx"></label><br/>
						<input type="submit" name="sc_add_user" class="button button-primary" value="Tilføj bruger">
					</form>
				</div>
				<div style="display: inline-block">
					<p>Ønsker du at starte forfra med retfærdigheden? Dette sætter "Vist antal gange" til 0 for alle brugere.</p>
					<form method="post" onsubmit="return confirm('Er du sikker på, at du vil nulstille tælleren for ALLE brugere?');">
						<?php wp_nonce_field('sc_reset_action', 'sc_reset_nonce'); ?>
						<input type="submit" name="sc_reset_counts" class="button button-primary" value="Nulstil alle tællere">
					</form>
				</div>
				<div style="display: inline-block">
					<p>Ønsker du at slette en bruger, så indtast dennes id her.</p>
					<form method="post" onsubmit="return confirm('Er du sikker på, at du vil slette brugere?');">
						<?php wp_nonce_field('sc_delete_action', 'sc_delete_nonce'); ?>
						<label>Brugers ID: <input type="text" name="sc_user_id" value=""></label><br/>
						<input type="submit" name="sc_delete_user" class="button button-primary" value="Slet bruger">
					</form>
				</div>
			</div>
		</div>

		<table class="widefat fixed" cellspacing="0">
			<thead>
			<tr>
				<th class="manage-column column-columnname" scope="col">ID</th>
				<th class="manage-column column-columnname" scope="col">Discord User ID</th>
				<th class="manage-column column-columnname" scope="col">Discord User</th>
				<th class="manage-column column-columnname" scope="col">Referral Kode</th>
				<th class="manage-column column-columnname" scope="col">Vist antal gange</th>
				<th class="manage-column column-columnname" scope="col">Oprettet dato</th>
			</tr>
			</thead>
			<tbody>
			<?php if (!empty($results)) : ?>
				<?php foreach ($results as $row) : ?>
					<tr>
						<td><?php echo esc_html($row->id); ?></td>
						<td><?php echo esc_html($row->discord_user_id); ?></td>
						<td><?php echo esc_html($row->discord_user_name); ?></td>
						<td><code style="background: #e6f7ff; padding: 3px 5px;"><?php echo esc_html($row->referral_code); ?></code></td>
						<td><strong><?php echo esc_html($row->usage_count); ?></strong></td>
						<td><?php echo esc_html($row->created_at); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="5">Ingen referrals fundet endnu.</td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

// ---------------------------------------------------------
// 3. REST API TIL DISCORD
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
	$sql = $wpdb->prepare(
		"INSERT INTO $table_name (discord_user_id, discord_user_name, referral_code, usage_count)
    VALUES (%s, %s, %s, %d)
    ON DUPLICATE KEY UPDATE
        referral_code=VALUES(referral_code),
        discord_user_id=VALUES(discord_user_id),
        discord_user_name=VALUES(discord_user_name)",
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

	// HUSK AT ÆNDRE DENNE KODE!
	$my_secret_key = '97dc6786b98892e4395963116153a4ca4ff84b3313f7e356c18e90d115b8bafd';

	if ($api_secret !== $my_secret_key) {
		return new WP_Error('no_auth', 'Forkert hemmelig kode', array('status' => 403));
	}

	if (empty($discord_id) || empty($discord_name) || empty($referral_code)) {
		return new WP_Error('missing_data', 'Mangler ID, navn eller Kode', array('status' => 400));
	}

	if (!preg_match('/^STAR-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $referral_code)) {
		return new WP_Error('invalid_format', 'Forkert kode format', array('status' => 400));
	}

	$result = sc_add_user($discord_id, $discord_name, $referral_code);

	if ($result === false) {
		return new WP_Error('db_error', 'Database fejl', array('status' => 500));
	}

	return new WP_REST_Response(array('message' => 'Success'), 200);
}

// ---------------------------------------------------------
// 4. SHORTCODE OG FRONTEND
// ---------------------------------------------------------
add_shortcode('star_citizen_button', 'sc_render_button');

function sc_render_button($atts) {
	wp_enqueue_script('jquery');

	$style = "
    <style>
        .sc-btn {
            background-color: #00d4ff; color: #000; padding: 15px 30px;
            text-decoration: none; font-weight: bold; border-radius: 5px;
            cursor: pointer; display: inline-block; border: none; font-size: 16px;
        }
        .sc-btn:hover { background-color: #00a3cc; }
        .sc-loading { opacity: 0.6; cursor: wait; }
    </style>";

	$html = '<button id="get-sc-code" class="sc-btn">Opret Star Citizen Konto</button>';

	$ajax_url = admin_url('admin-ajax.php');
	$script = "
    <script>
    jQuery(document).ready(function($) {
        $('#get-sc-code').click(function(e) {
            e.preventDefault();
            var btn = $(this);
            if(btn.hasClass('sc-loading')) return;
            btn.addClass('sc-loading').text('Henter kode...');

            $.ajax({
                url: '$ajax_url',
                type: 'POST',
                data: { action: 'get_sc_referral_code' },
                success: function(response) {
                if(response.success) {
                        var url = 'https://robertsspaceindustries.com/enlist?referral=' + response.data.code;
                        window.open(url, '_blank');
                        btn.removeClass('sc-loading').text('Opret Star Citizen Konto');
                    } else {
                        alert('Fejl: ' + response.data.message);
                        btn.removeClass('sc-loading').text('Prøv igen');
                    }
                },
                error: function() {
                    newWindow.close();
                    alert('Server fejl.');
                    btn.removeClass('sc-loading').text('Prøv igen');
                }
            });
        });
    });
    </script>
    ";

	return $style . $html . $script;
}

// ---------------------------------------------------------
// 5. AJAX LOGIK (FAIRNESS ALGORITME)
// ---------------------------------------------------------
add_action('wp_ajax_get_sc_referral_code', 'sc_get_random_referral');
add_action('wp_ajax_nopriv_get_sc_referral_code', 'sc_get_random_referral');

function sc_get_random_referral() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sc_referrals';

	// Find det laveste usage_count
	$min_usage = $wpdb->get_var("SELECT MIN(usage_count) FROM $table_name");

	if ($min_usage === null) {
		wp_send_json_error(array('message' => 'Ingen koder i systemet.'));
	}

	// Hent puljen af koder med dette lave tal
	$rows = $wpdb->get_results($wpdb->prepare("SELECT id, referral_code FROM $table_name WHERE usage_count = %d ORDER BY RAND()", $min_usage));

	if (empty($rows)) {
		wp_send_json_error(array('message' => 'Database fejl.'));
	}

	// Vælg tilfældig vinder
	$winner = $rows[array_rand($rows)];

	// Opdater vinderens tæller
	$wpdb->query($wpdb->prepare("UPDATE $table_name SET usage_count = usage_count + 1 WHERE id = %d", $winner->id));

	wp_send_json_success(array('code' => $winner->referral_code));
}