<?php
/**
 * Plugin Name:       Protected Downloads
 * Description:       Limit media files to selected roles and log every download.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Martina Rafat
 * License:           GPL-2.0-or-later
 * Text Domain:       protected-downloads
 *
 * @package ProtectedDownloads
 */

defined( 'ABSPATH' ) || exit;

define( 'PD_VERSION', '1.0.0' );
define( 'PD_DB_VERSION', '1.1' );

require __DIR__ . '/includes/class-pd-db.php';
require __DIR__ . '/includes/class-pd-download.php';
require __DIR__ . '/includes/class-pd-admin.php';

register_activation_hook( __FILE__, array( 'PD_DB', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'pd_db_version' ) !== PD_DB_VERSION ) {
			PD_DB::install();
		}

		PD_Download::init();

		if ( is_admin() ) {
			PD_Admin::init();
		}
	}
);
