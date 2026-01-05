<?php
/**
 * Plugin Name: Last.fm 3×3 Album Art Image Grid for WordPress
 * Plugin URI:  https://collinsasse.com/wordpress-plugins/last-fm-3x3-album-art-image-grid-for-wordpress/
 * Description: Create a 3x3 grid of album art from your Last.fm top albums.
 * Author:      Collin Sasse
 * Author URI:  https://collinsasse.com/wordpress-plugins/last-fm-3x3-album-art-image-grid-for-wordpress/
 * Version:     1.1.0
 * Requires at least: 5.2
 * Update URI:  https://collinsasse.com/wordpress-plugins/last-fm-3x3-album-art-image-grid-for-wordpress/
 */

if (!defined('ABSPATH')) {
	exit;
}

final class CS_LastFM_3x3_Grid_Plugin {
	const VERSION      = '1.1.0';
	const SHORTCODE    = 'show_lastfm_3x3_grid';
	const STYLE_HANDLE = 'cs-lastfm-3x3-grid';

	const OPTION_NAME  = 'cs_lastfm_grid_settings';
	const MENU_SLUG    = 'cs-lastfm-grid-settings';

	// Plugin defaults (also used as defaults for new installs).
	const DEFAULT_SIZE   = 'medium';
	const DEFAULT_PERIOD = '1month';

	private static $allowed_sizes   = array('small', 'medium', 'large');
	private static $allowed_periods = array('overall', '7day', '1month', '3month', '6month', '12month');

	public static function init() {
		add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
		add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_shortcode'));

		if (is_admin()) {
			add_action('admin_menu', array(__CLASS__, 'admin_menu'));
			add_action('admin_init', array(__CLASS__, 'register_settings'));
			add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(__CLASS__, 'action_links'));
		}
	}

	/* ---------------------------
	 * Frontend assets + shortcode
	 * --------------------------*/

	public static function register_assets() {
		wp_register_style(self::STYLE_HANDLE, false, array(), self::VERSION);

		$css = '
			.cs-lastfm-grid{
				display:grid;
				grid-template-columns:repeat(3,1fr);
				gap:6px;
				max-width:420px;
			}
			.cs-lastfm-grid__img{
				width:100%;
				height:auto;
				display:block;
				border-radius:6px;
			}
			.cs-lastfm-grid__note{
				font-size:0.95em;
				opacity:.85;
			}
		';

		wp_add_inline_style(self::STYLE_HANDLE, $css);
	}

	public static function render_shortcode($atts) {
		wp_enqueue_style(self::STYLE_HANDLE);

		$settings = self::get_settings();

		$atts = shortcode_atts(
			array(
				// Allow shortcode overrides, otherwise use defaults.
				'size'   => $settings['default_size'],
				'period' => $settings['default_period'],
			),
			(array) $atts,
			self::SHORTCODE
		);

		$size   = sanitize_text_field($atts['size']);
		$period = sanitize_text_field($atts['period']);

		if (!in_array($size, self::$allowed_sizes, true)) {
			$size = $settings['default_size'];
		}
		if (!in_array($period, self::$allowed_periods, true)) {
			$period = $settings['default_period'];
		}

		$username = $settings['username'];
		$api_key  = $settings['api_key'];

		if ($username === '' || $api_key === '') {
			return '<span class="cs-lastfm-grid__note">Last.fm grid: set your username and API key in <strong>Settings → Last.fm Grid</strong>.</span>';
		}

		// Cache HTML so the page doesn't call Last.fm on every load.
		$cache_key = 'cs_lastfm_3x3_' . md5($username . '|' . $api_key . '|' . $size . '|' . $period . '|' . self::VERSION);
		$cached = get_transient($cache_key);
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		$data = self::fetch_top_albums($username, $api_key, $period, 9);
		if (is_wp_error($data)) {
			return '<span class="cs-lastfm-grid__note">Last.fm grid error: ' . esc_html($data->get_error_message()) . '</span>';
		}

		$albums = array();
		if (isset($data['topalbums']['album']) && is_array($data['topalbums']['album'])) {
			$albums = $data['topalbums']['album'];
		}

		if (!$albums) {
			return '<span class="cs-lastfm-grid__note">Last.fm grid: no albums found.</span>';
		}

		$imgs  = array();
		$count = 0;

		foreach ($albums as $album) {
			if ($count >= 9) {
				break;
			}
			if (!is_array($album)) {
				continue;
			}

			$img_url = self::get_album_image_url($album, $size);
			if ($img_url === '') {
				continue;
			}

			$album_name  = isset($album['name']) ? (string) $album['name'] : '';
			$artist_name = '';
			if (isset($album['artist']['name'])) {
				$artist_name = (string) $album['artist']['name'];
			} elseif (isset($album['artist']) && is_string($album['artist'])) {
				$artist_name = (string) $album['artist'];
			}

			$alt = trim($artist_name . ' — ' . $album_name);
			if ($alt === '' || $alt === '—') {
				$alt = 'Last.fm album art';
			}

			$imgs[] = sprintf(
				'<img class="cs-lastfm-grid__img" src="%s" alt="%s" loading="lazy" decoding="async" />',
				esc_url($img_url),
				esc_attr($alt)
			);

			$count++;
		}

		if (!$imgs) {
			return '<span class="cs-lastfm-grid__note">Last.fm grid: album images unavailable.</span>';
		}

		$html = '<div class="cs-lastfm-grid" aria-label="Last.fm top albums">' . implode('', $imgs) . '</div>';

		$ttl = (int) apply_filters('cs_lastfm_3x3_grid_cache_ttl', HOUR_IN_SECONDS);
		if ($ttl < 60) {
			$ttl = 60;
		}
		set_transient($cache_key, $html, $ttl);

		return $html;
	}

	private static function fetch_top_albums($username, $api_key, $period, $limit) {
		$endpoint = 'https://ws.audioscrobbler.com/2.0/';

		$url = add_query_arg(
			array(
				'method'  => 'user.getTopAlbums',
				'user'    => $username,
				'api_key' => $api_key,
				'format'  => 'json',
				'period'  => $period,
				'limit'   => (int) $limit,
			),
			$endpoint
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);

		if ($code < 200 || $code >= 300) {
			return new WP_Error('lastfm_http_error', 'HTTP ' . $code . ' from Last.fm.');
		}

		$data = json_decode($body, true);
		if (!is_array($data)) {
			return new WP_Error('lastfm_bad_json', 'Invalid JSON returned from Last.fm.');
		}

		if (isset($data['error']) && isset($data['message'])) {
			return new WP_Error('lastfm_api_error', (string) $data['message']);
		}

		return $data;
	}

	private static function get_album_image_url(array $album, $size) {
		if (!isset($album['image']) || !is_array($album['image'])) {
			return '';
		}

		// Prefer matching "size" key.
		foreach ($album['image'] as $img) {
			if (!is_array($img)) {
				continue;
			}
			if (isset($img['size'], $img['#text']) && (string) $img['size'] === $size && (string) $img['#text'] !== '') {
				return (string) $img['#text'];
			}
		}

		// Fallback: last non-empty.
		$best = '';
		foreach ($album['image'] as $img) {
			if (is_array($img) && isset($img['#text']) && (string) $img['#text'] !== '') {
				$best = (string) $img['#text'];
			}
		}

		return $best;
	}

	/* ---------------------------
	 * Admin settings page
	 * --------------------------*/

	public static function action_links($links) {
		$url = admin_url('options-general.php?page=' . self::MENU_SLUG);
		$links[] = '<a href="' . esc_url($url) . '">Settings</a>';
		return $links;
	}

	public static function admin_menu() {
		add_options_page(
			'Last.fm Grid',
			'Last.fm Grid',
			'manage_options',
			self::MENU_SLUG,
			array(__CLASS__, 'render_settings_page')
		);
	}

	public static function register_settings() {
		register_setting(
			'cs_lastfm_grid_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
				'default'           => self::default_settings(),
			)
		);

		add_settings_section(
			'cs_lastfm_grid_main',
			'Last.fm Settings',
			function () {
				echo '<p>Enter your Last.fm username and API key once, then use the shortcode anywhere.</p>';
				echo '<p>For setup directions, please <a href="https://collinsasse.com/wordpress-plugins/last-fm-3x3-album-art-image-grid-for-wordpress/"/>click here.</a></p><br><br><br>';
				echo '<p><code>[show_lastfm_3x3_grid size="medium" period="1month"]</code></p><br><br>';
			},
			self::MENU_SLUG
		);

		add_settings_field(
			'cs_lastfm_grid_username',
			'Last.fm Username',
			array(__CLASS__, 'field_username'),
			self::MENU_SLUG,
			'cs_lastfm_grid_main'
		);

		add_settings_field(
			'cs_lastfm_grid_api_key',
			'Last.fm API Key',
			array(__CLASS__, 'field_api_key'),
			self::MENU_SLUG,
			'cs_lastfm_grid_main'
		);

		add_settings_field(
			'cs_lastfm_grid_default_size',
			'Default Image Size',
			array(__CLASS__, 'field_default_size'),
			self::MENU_SLUG,
			'cs_lastfm_grid_main'
		);

		add_settings_field(
			'cs_lastfm_grid_default_period',
			'Default Period',
			array(__CLASS__, 'field_default_period'),
			self::MENU_SLUG,
			'cs_lastfm_grid_main'
		);
	}

	private static function default_settings() {
		return array(
			'username'       => '',
			'api_key'        => '',
			'default_size'   => self::DEFAULT_SIZE,   // medium
			'default_period' => self::DEFAULT_PERIOD, // 1month
		);
	}

	private static function get_settings() {
		$raw = get_option(self::OPTION_NAME);
		$raw = is_array($raw) ? $raw : array();

		$defaults = self::default_settings();
		$settings = array_merge($defaults, $raw);

		$settings['username'] = sanitize_text_field($settings['username']);
		$settings['api_key']  = sanitize_text_field($settings['api_key']);

		$settings['default_size']   = sanitize_text_field($settings['default_size']);
		$settings['default_period'] = sanitize_text_field($settings['default_period']);

		if (!in_array($settings['default_size'], self::$allowed_sizes, true)) {
			$settings['default_size'] = self::DEFAULT_SIZE;
		}
		if (!in_array($settings['default_period'], self::$allowed_periods, true)) {
			$settings['default_period'] = self::DEFAULT_PERIOD;
		}

		return $settings;
	}

	public static function sanitize_settings($raw) {
		$raw = is_array($raw) ? $raw : array();

		$username = isset($raw['username']) ? sanitize_text_field($raw['username']) : '';
		$api_key  = isset($raw['api_key']) ? sanitize_text_field($raw['api_key']) : '';

		$default_size   = isset($raw['default_size']) ? sanitize_text_field($raw['default_size']) : self::DEFAULT_SIZE;
		$default_period = isset($raw['default_period']) ? sanitize_text_field($raw['default_period']) : self::DEFAULT_PERIOD;

		if (!in_array($default_size, self::$allowed_sizes, true)) {
			$default_size = self::DEFAULT_SIZE;
		}
		if (!in_array($default_period, self::$allowed_periods, true)) {
			$default_period = self::DEFAULT_PERIOD;
		}

		return array(
			'username'       => $username,
			'api_key'        => $api_key,
			'default_size'   => $default_size,
			'default_period' => $default_period,
		);
	}

	public static function field_username() {
		$s = self::get_settings();
		printf(
			'<input type="text" class="regular-text" name="%s[username]" value="%s" />',
			esc_attr(self::OPTION_NAME),
			esc_attr($s['username'])
		);
	}

	public static function field_api_key() {
		$s = self::get_settings();
		printf(
			'<input type="text" class="regular-text" name="%s[api_key]" value="%s" autocomplete="off" />',
			esc_attr(self::OPTION_NAME),
			esc_attr($s['api_key'])
		);
		echo '<p class="description">This is your API key, not the shared secret. Please visit <a href="https://collinsasse.com/wordpress-plugins/last-fm-3x3-album-art-image-grid-for-wordpress/"/>here</a> for directions and to get a key.</p>';
	}

	public static function field_default_size() {
		$s = self::get_settings();
		echo '<select name="' . esc_attr(self::OPTION_NAME) . '[default_size]">';
		foreach (self::$allowed_sizes as $size) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr($size),
				selected($s['default_size'], $size, false),
				esc_html(ucfirst($size))
			);
		}
		echo '</select>';
	}

	public static function field_default_period() {
		$s = self::get_settings();
		echo '<select name="' . esc_attr(self::OPTION_NAME) . '[default_period]">';
		foreach (self::$allowed_periods as $period) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr($period),
				selected($s['default_period'], $period, false),
				esc_html($period)
			);
		}
		echo '</select>';
	}

	public static function render_settings_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>Last.fm Grid</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields('cs_lastfm_grid_group');
		do_settings_sections(self::MENU_SLUG);
		submit_button('Save Settings');
		echo '</form>';

		echo '<hr />';
		echo '<h2>Shortcode</h2>';
		echo '<p>Use anywhere:</p>';
		echo '<p><code>[show_lastfm_3x3_grid]</code></p>';
		echo '<p>Or override defaults per instance:</p>';
		echo '<p><code>[show_lastfm_3x3_grid size="large" period="7day"]</code></p>';
		echo '<p class="description">Accepted size: small, medium, large. <br>Accepted period: overall, 7day, 1month, 3month, 6month, 12month.</p>';

		echo '</div>';
		
		echo '<br><br><br><br><big>If you are using this plugin, please consider <a href="https://collinsasse.com/wordpress-plugins/last-fm-3x3-album-art-image-grid-for-wordpress"/>buying me a coffee as a thank-you</a>. Also, this plugin does not auto-update, please <a href="https://collinsasse.com/wordpress-plugins/last-fm-3x3-album-art-image-grid-for-wordpress"/>check my website</a> every now and then for updates, fixes, and changes.</big>';
	}
}

CS_LastFM_3x3_Grid_Plugin::init();
