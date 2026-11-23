<?php
/**
 * Role checkboxes on the attachment screen, and the download report under Media.
 *
 * @package ProtectedDownloads
 */

defined( 'ABSPATH' ) || exit;

class PD_Admin {

	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function fields( $fields, $post ) {
		$current = PD_DB::get_roles( $post->ID );

		// Lets save() tell "all unchecked" apart from "field not sent".
		$html = sprintf( '<input type="hidden" name="attachments[%d][pd_roles_sent]" value="1">', $post->ID );

		foreach ( wp_roles()->get_names() as $role => $name ) {
			$html .= sprintf(
				'<label style="display:block"><input type="checkbox" name="attachments[%1$d][pd_roles][]" value="%2$s" %3$s> %4$s</label>',
				$post->ID,
				esc_attr( $role ),
				checked( in_array( $role, $current, true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}

		$fields['pd_roles'] = array(
			'label' => __( 'Who can download', 'protected-downloads' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'Leave everything unchecked to keep the file public. Protected link:', 'protected-downloads' ) . ' ' . esc_html( PD_Download::url( $post->ID ) ),
		);

		return $fields;
	}

	public static function save( $post, $attachment ) {
		if ( empty( $attachment['pd_roles_sent'] ) || ! current_user_can( 'edit_post', $post['ID'] ) ) {
			return $post;
		}

		$roles = array_map( 'sanitize_key', (array) ( $attachment['pd_roles'] ?? array() ) );
		$roles = array_intersect( $roles, array_keys( wp_roles()->roles ) );

		PD_DB::set_roles( (int) $post['ID'], $roles );

		return $post;
	}

	/**
	 * "editor,author" => "Editor, Author"
	 */
	public static function role_names( $roles ) {
		if ( ! is_array( $roles ) ) {
			$roles = array_filter( explode( ',', (string) $roles ) );
		}
		if ( ! $roles ) {
			return '-';
		}

		$names = wp_roles()->get_names();

		return implode(
			', ',
			array_map(
				function ( $role ) use ( $names ) {
					return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
				},
				$roles
			)
		);
	}

	public static function menu() {
		add_media_page(
			__( 'Download report', 'protected-downloads' ),
			__( 'Download report', 'protected-downloads' ),
			'manage_options',
			'pd-report',
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$doc  = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		// phpcs:enable

		if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) {
			$days = 30;
		}

		$base = admin_url( 'upload.php?page=pd-report' );

		echo '<div class="wrap">';

		if ( $doc ) {
			self::render_document( $doc, $base );
		} else {
			self::render_overview( $days, $base );
		}

		echo '</div>';
	}

	private static function render_overview( $days, $base ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$top    = PD_DB::top_downloads( $since );
		$files  = PD_DB::protected_files();
		$recent = PD_DB::recent_downloads();
		?>
		<h1><?php esc_html_e( 'Download report', 'protected-downloads' ); ?></h1>

		<p>
			<?php foreach ( array( 7, 30, 90 ) as $option ) : ?>
				<?php if ( $option === $days ) : ?>
					<strong><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Last %d days', 'protected-downloads' ), $option ) ); ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'days', $option, $base ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Last %d days', 'protected-downloads' ), $option ) ); ?></a>
				<?php endif; ?>
				&nbsp;
			<?php endforeach; ?>
		</p>

		<h2><?php esc_html_e( 'Recent downloads', 'protected-downloads' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'File', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Who', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Role at download', 'protected-downloads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $recent ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Nothing downloaded yet.', 'protected-downloads' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $recent as $row ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row->downloaded_at, 'Y-m-d H:i' ) ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( 'doc', $row->attachment_id, $base ) ); ?>"><?php echo esc_html( $row->post_title ); ?></a></td>
						<td>
							<?php if ( $row->display_name ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $row->user_id ) ); ?>"><?php echo esc_html( $row->display_name ); ?></a>
								<br><small><?php echo esc_html( $row->user_email ); ?></small>
							<?php else : ?>
								<?php esc_html_e( '(deleted user)', 'protected-downloads' ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::role_names( $row->user_role ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Most downloaded', 'protected-downloads' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'People', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Downloads', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Last download', 'protected-downloads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $top ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No downloads in this period.', 'protected-downloads' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $top as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'doc', $row->attachment_id, $base ) ); ?>"><?php echo esc_html( $row->post_title ); ?></a></td>
						<td><?php echo (int) $row->people; ?></td>
						<td><?php echo (int) $row->downloads; ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $row->last_download, 'Y-m-d H:i' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Protected files', 'protected-downloads' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Allowed roles', 'protected-downloads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $files ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No protected files yet. Open a file in the Media Library and tick "Who can download".', 'protected-downloads' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $files as $file ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'doc', $file->attachment_id, $base ) ); ?>"><?php echo esc_html( $file->post_title ); ?></a></td>
						<td><?php echo esc_html( $file->roles ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_document( $doc, $base ) {
		$roles      = PD_DB::get_roles( $doc );
		$pending    = PD_DB::not_downloaded( $doc );
		$downloaded = PD_DB::downloaded_by( $doc );
		?>
		<h1><?php echo esc_html( get_the_title( $doc ) ); ?></h1>
		<p><a href="<?php echo esc_url( $base ); ?>">&larr; <?php esc_html_e( 'Back to report', 'protected-downloads' ); ?></a></p>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: roles, 2: number of people. */
					__( 'Allowed roles: %1$s. Downloaded by %2$d people.', 'protected-downloads' ),
					$roles ? self::role_names( $roles ) : __( 'none (public)', 'protected-downloads' ),
					PD_DB::people_count( $doc )
				)
			);
			?>
		</p>

		<h2><?php esc_html_e( 'Downloaded by', 'protected-downloads' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Email', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Current role', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Downloads', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Last download', 'protected-downloads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $downloaded ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nobody has downloaded it yet.', 'protected-downloads' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $downloaded as $person ) : ?>
					<?php $user = get_userdata( $person->user_id ); ?>
					<tr>
						<td><?php echo $person->display_name ? esc_html( $person->display_name ) : esc_html__( '(deleted user)', 'protected-downloads' ); ?></td>
						<td><?php echo esc_html( (string) $person->user_email ); ?></td>
						<td><?php echo esc_html( $user ? self::role_names( $user->roles ) : '-' ); ?></td>
						<td><?php echo (int) $person->downloads; ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $person->last_download, 'Y-m-d H:i' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Haven\'t downloaded it yet', 'protected-downloads' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Email', 'protected-downloads' ); ?></th>
					<th><?php esc_html_e( 'Role', 'protected-downloads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $pending ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Everyone with access has downloaded it.', 'protected-downloads' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $pending as $person ) : ?>
					<?php $user = get_userdata( $person->ID ); ?>
					<tr>
						<td><?php echo esc_html( $person->display_name ); ?></td>
						<td><?php echo esc_html( $person->user_email ); ?></td>
						<td><?php echo esc_html( $user ? self::role_names( $user->roles ) : '-' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
