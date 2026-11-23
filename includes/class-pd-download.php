<?php
/**
 * Serves protected files through /?pd_download=ID after checking the user's role.
 *
 * @package ProtectedDownloads
 */

defined( 'ABSPATH' ) || exit;

class PD_Download {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
		add_shortcode( 'protected_download', array( __CLASS__, 'shortcode' ) );
	}

	public static function url( $attachment_id ) {
		return add_query_arg( 'pd_download', (int) $attachment_id, home_url( '/' ) );
	}

	public static function maybe_serve() {
		if ( empty( $_GET['pd_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$id   = absint( $_GET['pd_download'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file = $id ? get_attached_file( $id ) : '';

		if ( ! $file || 'attachment' !== get_post_type( $id ) || ! is_readable( $file ) ) {
			wp_die( esc_html__( 'File not found.', 'protected-downloads' ), '', array( 'response' => 404 ) );
		}

		$roles = PD_DB::get_roles( $id );

		// Not protected, so just send them to the normal file URL.
		if ( ! $roles ) {
			wp_redirect( wp_get_attachment_url( $id ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		if ( ! self::can_download( wp_get_current_user(), $roles ) ) {
			wp_die( esc_html__( 'You don\'t have access to this file.', 'protected-downloads' ), '', array( 'response' => 403 ) );
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		PD_DB::log_download( $id, wp_get_current_user(), $ip );

		self::send( $file, $id );
	}

	public static function can_download( WP_User $user, array $roles ) {
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		return (bool) array_intersect( $user->roles, $roles );
	}

	private static function send( $file, $id ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$type = get_post_mime_type( $id );

		nocache_headers();
		header( 'Content-Type: ' . ( $type ? $type : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $file ) ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * [protected_download id="123"]Download the HR policy[/protected_download]
	 */
	public static function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'protected_download' );
		$id   = absint( $atts['id'] );

		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return '';
		}

		$label = '' !== trim( (string) $content ) ? $content : get_the_title( $id );

		return sprintf(
			'<a class="pd-download" href="%s">%s</a>',
			esc_url( self::url( $id ) ),
			esc_html( $label )
		);
	}
}
