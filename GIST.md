# Protected Downloads: schema + "who hasn't downloaded it" query

```sql
CREATE TABLE wp_pd_rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL,
  role varchar(64) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY attachment_role (attachment_id,role),
  KEY role (role)
);

CREATE TABLE wp_pd_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  user_role varchar(191) NOT NULL DEFAULT '',
  downloaded_at datetime NOT NULL,
  ip_hash char(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY attachment_date (attachment_id,downloaded_at),
  KEY user_attachment (user_id,attachment_id)
);
```

Users who are allowed to download a file but haven't yet. Roles are in the serialized `wp_capabilities` usermeta, so each allowed role becomes a `LIKE '%"role"%'` condition, and the LEFT JOIN + `IS NULL` keeps only people with no log row for this file.

```php
$roles    = PD_DB::get_roles( $attachment_id );
$role_sql = implode( ' OR ', array_fill( 0, count( $roles ), 'cap.meta_value LIKE %s' ) );
$params   = array( $wpdb->get_blog_prefix() . 'capabilities', (int) $attachment_id );

foreach ( $roles as $role ) {
	$params[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
}
$params[] = (int) $limit;

$users = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT u.ID, u.display_name, u.user_email
		FROM {$wpdb->users} u
		INNER JOIN {$wpdb->usermeta} cap
			ON cap.user_id = u.ID AND cap.meta_key = %s
		LEFT JOIN {$wpdb->prefix}pd_log l
			ON l.user_id = u.ID AND l.attachment_id = %d
		WHERE l.id IS NULL
			AND ( {$role_sql} )
		ORDER BY u.display_name
		LIMIT %d",
		$params
	)
);
```
