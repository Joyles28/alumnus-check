	<?php
	/**
	 * Plugin Name: WordPress Plugin Template
	 * Version: 1.0.0
	 * Plugin URI: http://www.hughlashbrooke.com/
	 * Description: This is your starter template for your next WordPress plugin.
	 * Author: Hugh Lashbrooke
	 * Author URI: http://www.hughlashbrooke.com/
	 * Requires at least: 4.0
	 * Tested up to: 4.0
	 *
	 * Text Domain: wordpress-plugin-template
	 * Domain Path: /lang/
	 *
	 * @package WordPress
	 * @author Hugh Lashbrooke
	 * @since 1.0.0
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	// Load plugin class files.
	require_once 'includes/class-wordpress-plugin-template.php';
	require_once 'includes/class-wordpress-plugin-template-settings.php';

	// Session & auth helpers for custom alumni login
	require_once 'includes/alumnus-session.php';

	// Load plugin libraries.
	require_once 'includes/lib/class-wordpress-plugin-template-admin-api.php';
	require_once 'includes/lib/class-wordpress-plugin-template-post-type.php';
	require_once 'includes/lib/class-wordpress-plugin-template-taxonomy.php';

	// Shortcodes.
	require_once 'community-feed-shortcode.php';
	require_once 'header-shortcode.php';
	require_once 'directory-shortcode.php';
	require_once 'profile-shortcode.php';
	// Event information template & shortcode.
	require_once 'event-information-template.php';
	// Event list view shortcode.
	require_once 'event-list-shortcode.php';

	require_once 'add-alumni.php';
	require_once 'manage-skills.php';

	require_once 'login-alumni.php';
	require_once 'create-db.php';

	/**
	 * Returns the main instance of WordPress_Plugin_Template to prevent the need to use globals.
	 *
	 * @since  1.0.0
	 * @return object WordPress_Plugin_Template
	 */
	function wordpress_plugin_template() {
		$instance = WordPress_Plugin_Template::instance( __FILE__, '1.0.0' );

		if ( is_null( $instance->settings ) ) {
			$instance->settings = WordPress_Plugin_Template_Settings::instance( $instance );
		}

		return $instance;
	}

	wordpress_plugin_template();
