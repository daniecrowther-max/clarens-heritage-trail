<?php
/**
 * Admin screen: Voucher Redemption Metrics.
 *
 * Metrics dashboard for partner voucher redemptions — total redeemed,
 * time-windowed (7/30-day), remaining stock, and full redemption log export.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Voucher_Metrics {

	const SLUG = 'cha-voucher-metrics';

	/**
	 * Hook menu + action handling.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_csv_export' ) );
	}

	/**
	 * Add the screen under the Partners menu.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=partner',
			__( 'Voucher Metrics', 'cha' ),
			__( 'Voucher Metrics', 'cha' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	protected static function page_url() {
		return admin_url( 'edit.php?post_type=partner&page=' . self::SLUG );
	}

	/**
	 * Resolve partner_id (VARCHAR 191 from cha_redemptions) to a partner post.
	 * Uses the same logic as CHA_Redeem::find_partner().
	 *
	 * @param string $partner_id From cha_redemptions.
	 * @return WP_Post|null
	 */
	protected static function find_partner( $partner_id ) {
		$post = null;

		if ( preg_match( '/^partner-(\d+)$/', $partner_id, $m ) ) {
			$post = get_post( (int) $m[1] );
		} elseif ( ctype_digit( $partner_id ) ) {
			$post = get_post( (int) $partner_id );
		} else {
			$post = get_page_by_path( $partner_id, OBJECT, 'partner' );
		}

		if ( $post && 'partner' === $post->post_type && 'publish' === $post->post_status ) {
			return $post;
		}
		return null;
	}

	/**
	 * Fetch all partners with redemption stats.
	 *
	 * @return array Array of { post_id, name, partner_id, total, days7, days30, max, used, remaining, dateFrom, dateTo, window }.
	 */
	protected static function get_partner_stats() {
		global $wpdb;

		// Get all published partners.
		$partners = get_posts(
			array(
				'post_type'      => 'partner',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		$stats = array();
		$now   = current_time( 'timestamp' );
		$seven = gmdate( 'Y-m-d H:i:s', $now - 7 * 86400 );
		$thirty = gmdate( 'Y-m-d H:i:s', $now - 30 * 86400 );

		foreach ( $partners as $post ) {
			$partner_id = $post->post_name; // slug is the canonical partner_id.

			$total = (int) get_post_meta( $post->ID, 'usedCount', true );
			$max   = (int) get_post_meta( $post->ID, 'maxVouchers', true );
			$used  = $total; // usedCount is already maintained server-side.

			// Time-windowed redemptions via direct query.
			$redemptions_table = $wpdb->prefix . 'cha_redemptions';
			$days7 = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $redemptions_table WHERE partner_id = %s AND redeemed_at >= %s",
					$partner_id,
					$seven
				)
			);
			$days30 = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $redemptions_table WHERE partner_id = %s AND redeemed_at >= %s",
					$partner_id,
					$thirty
				)
			);

			$date_from = (string) get_post_meta( $post->ID, 'dateFrom', true );
			$date_to   = (string) get_post_meta( $post->ID, 'dateTo', true );

			if ( $date_from || $date_to ) {
				$window = ( $date_from ? $date_from : '—' ) . ' to ' . ( $date_to ? $date_to : '—' );
			} else {
				$window = 'No restriction';
			}

			$remaining = ( $max > 0 ) ? max( 0, $max - $used ) : null; // null = unlimited.

			$stats[] = array(
				'post_id'    => $post->ID,
				'name'       => $post->post_title,
				'partner_id' => $partner_id,
				'total'      => $total,
				'days7'      => $days7,
				'days30'     => $days30,
				'max'        => $max,
				'used'       => $used,
				'remaining'  => $remaining,
				'dateFrom'   => $date_from,
				'dateTo'     => $date_to,
				'window'     => $window,
			);
		}

		// Sort by total redemptions descending.
		usort(
			$stats,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);

		return $stats;
	}

	/**
	 * Handle CSV export request.
	 */
	public static function handle_csv_export() {
		// Check if this is a CSV export request and user has permission.
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'export_csv' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'cha_export_voucher_csv' );

		self::export_csv();
	}

	/**
	 * Stream CSV export: partner name, token, redeemed_at for all cha_redemptions.
	 */
	protected static function export_csv() {
		global $wpdb;
		$redemptions_table = $wpdb->prefix . 'cha_redemptions';

		// Fetch all redemptions.
		$rows = $wpdb->get_results(
			"SELECT partner_id, token, redeemed_at FROM $redemptions_table ORDER BY redeemed_at DESC"
		);

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="cha-voucher-redemptions-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// BOM for Excel UTF-8.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		// Header row.
		fputcsv( $output, array( 'Partner Name', 'Token', 'Redeemed At' ) );

		foreach ( $rows as $row ) {
			$partner = self::find_partner( $row->partner_id );
			$partner_name = $partner ? $partner->post_title : '(unknown: ' . $row->partner_id . ')';

			fputcsv( $output, array( $partner_name, $row->token, $row->redeemed_at ) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Render the metrics page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$stats = self::get_partner_stats();
		$grand_total = array_sum( array_column( $stats, 'total' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Voucher Redemption Metrics', 'cha' ); ?></h1>

			<div style="margin-bottom: 20px;">
				<p>
					<strong><?php esc_html_e( 'Total Redemptions (All Time):', 'cha' ); ?></strong>
					<span style="font-size: 24px; color: #23282d;"><?php echo esc_html( $grand_total ); ?></span>
				</p>
				<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'export_csv', '_wpnonce' => wp_create_nonce( 'cha_export_voucher_csv' ) ), self::page_url() ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Download CSV', 'cha' ); ?>
				</a>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width: 25%;"><?php esc_html_e( 'Partner', 'cha' ); ?></th>
						<th style="width: 10%; text-align: center;"><?php esc_html_e( 'Total', 'cha' ); ?></th>
						<th style="width: 10%; text-align: center;"><?php esc_html_e( '7 Days', 'cha' ); ?></th>
						<th style="width: 10%; text-align: center;"><?php esc_html_e( '30 Days', 'cha' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'Stock', 'cha' ); ?></th>
						<th style="width: 30%;"><?php esc_html_e( 'Voucher Window', 'cha' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $stats ) ) : ?>
						<tr>
							<td colspan="6" style="text-align: center; padding: 20px;">
								<?php esc_html_e( 'No partners found.', 'cha' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $stats as $stat ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $stat['post_id'] ) ); ?>">
										<?php echo esc_html( $stat['name'] ); ?>
									</a>
								</td>
								<td style="text-align: center;">
									<?php echo esc_html( $stat['total'] ); ?>
								</td>
								<td style="text-align: center;">
									<?php echo esc_html( $stat['days7'] ); ?>
								</td>
								<td style="text-align: center;">
									<?php echo esc_html( $stat['days30'] ); ?>
								</td>
								<td>
									<?php
									if ( null === $stat['remaining'] ) {
										esc_html_e( 'Unlimited', 'cha' );
									} else {
										echo esc_html( $stat['remaining'] . ' / ' . $stat['max'] );
									}
									?>
								</td>
								<td>
									<?php echo esc_html( $stat['window'] ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
