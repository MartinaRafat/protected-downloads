<?php
/**
 * @package ProtectedDownloads
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pd_rules" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pd_log" );

delete_option( 'pd_db_version' );
