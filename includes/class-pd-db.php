<?php
/**
 * @package ProtectedDownloads
 */

defined( 'ABSPATH' ) || exit;

class PD_DB {

	public static function rules_table() {
		global $wpdb;
		return $wpdb->prefix . 'pd_rules';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'pd_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$rules   = self::rules_table();
		$log     = self::log_table();

		dbDelta(
			"CREATE TABLE {$rules} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				role varchar(64) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY attachment_role (attachment_id,role),
				KEY role (role)
			) {$charset};
			CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				user_role varchar(191) NOT NULL DEFAULT '',
				downloaded_at datetime NOT NULL,
				ip_hash char(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY attachment_date (attachment_id,downloaded_at),
				KEY user_attachment (user_id,attachment_id)
			) {$charset};"
		);

		update_option( 'pd_db_version', PD_DB_VERSION );
	}

	public static function get_roles( $attachment_id ) {
		global $wpdb;
		$table = self::rules_table();

		return $wpdb->get_col(
			$wpdb->prepare( "SELECT role FROM {$table} WHERE attachment_id = %d ORDER BY role", $attachment_id )
		);
	}

	public static function set_roles( $attachment_id, array $roles ) {
		global $wpdb;
		$table = self::rules_table();

		$wpdb->delete( $table, array( 'attachment_id' => $attachment_id ), array( '%d' ) );

		foreach ( array_unique( $roles ) as $role ) {
			$wpdb->insert(
				$table,
				array(
					'attachment_id' => $attachment_id,
					'role'          => $role,
				),
				array( '%d', '%s' )
			);
		}
	}

	public static function log_download( $attachment_id, WP_User $user, $ip ) {
		global $wpdb;

		// Role is saved as it was at download time, since roles can change later.
		// We only need to tell visits apart, so the IP is stored hashed.
		$wpdb->insert(
			self::log_table(),
			array(
				'attachment_id' => $attachment_id,
				'user_id'       => $user->ID,
				'user_role'     => implode( ',', $user->roles ),
				'downloaded_at' => current_time( 'mysql', true ),
				'ip_hash'       => $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : '',
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	public static function recent_downloads( $limit = 20 ) {
		global $wpdb;
		$log = self::log_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.attachment_id, l.user_id, l.user_role, l.downloaded_at,
					p.post_title, u.display_name, u.user_email
				FROM {$log} l
				INNER JOIN {$wpdb->posts} p ON p.ID = l.attachment_id
				LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				ORDER BY l.id DESC
				LIMIT %d",
				$limit
			)
		);
	}

	public static function downloaded_by( $attachment_id, $limit = 200 ) {
		global $wpdb;
		$log = self::log_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.user_id, u.display_name, u.user_email,
					COUNT(*) AS downloads,
					MAX(l.downloaded_at) AS last_download
				FROM {$log} l
				LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				WHERE l.attachment_id = %d
				GROUP BY l.user_id, u.display_name, u.user_email
				ORDER BY last_download DESC
				LIMIT %d",
				$attachment_id,
				$limit
			)
		);
	}

	public static function protected_files() {
		global $wpdb;
		$rules = self::rules_table();

		return $wpdb->get_results(
			"SELECT r.attachment_id, p.post_title,
				GROUP_CONCAT(r.role ORDER BY r.role SEPARATOR ', ') AS roles
			FROM {$rules} r
			INNER JOIN {$wpdb->posts} p ON p.ID = r.attachment_id
			GROUP BY r.attachment_id, p.post_title
			ORDER BY p.post_title"
		);
	}

	public static function top_downloads( $since, $limit = 20 ) {
		global $wpdb;
		$log = self::log_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.attachment_id, p.post_title,
					COUNT(*) AS downloads,
					COUNT(DISTINCT l.user_id) AS people,
					MAX(l.downloaded_at) AS last_download
				FROM {$log} l
				INNER JOIN {$wpdb->posts} p ON p.ID = l.attachment_id
				WHERE l.downloaded_at >= %s
				GROUP BY l.attachment_id, p.post_title
				ORDER BY people DESC, downloads DESC
				LIMIT %d",
				$since,
				$limit
			)
		);
	}

	public static function people_count( $attachment_id ) {
		global $wpdb;
		$log = self::log_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$log} WHERE attachment_id = %d", $attachment_id )
		);
	}

	/**
	 * Users who are allowed to download the file but haven't done it yet.
	 *
	 * Roles live in the serialized capabilities usermeta, so each allowed role
	 * is matched with LIKE '%"role"%' (same approach WP_User_Query uses).
	 */
	public static function not_downloaded( $attachment_id, $limit = 200 ) {
		global $wpdb;

		$roles = self::get_roles( $attachment_id );
		if ( ! $roles ) {
			return array();
		}

		$log      = self::log_table();
		$role_sql = implode( ' OR ', array_fill( 0, count( $roles ), 'cap.meta_value LIKE %s' ) );
		$params   = array( $wpdb->get_blog_prefix() . 'capabilities', (int) $attachment_id );

		foreach ( $roles as $role ) {
			$params[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
		}
		$params[] = (int) $limit;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.display_name, u.user_email
				FROM {$wpdb->users} u
				INNER JOIN {$wpdb->usermeta} cap
					ON cap.user_id = u.ID AND cap.meta_key = %s
				LEFT JOIN {$log} l
					ON l.user_id = u.ID AND l.attachment_id = %d
				WHERE l.id IS NULL
					AND ( {$role_sql} )
				ORDER BY u.display_name
				LIMIT %d",
				$params
			)
		);
	}
}
