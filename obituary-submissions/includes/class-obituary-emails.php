<?php
defined( 'ABSPATH' ) || exit;

class Obituary_Emails {

	public static function init(): void {}

	public static function send_admin_notification( int $post_id, \WC_Order $order, array $submission ): void {
		$admin_email = get_option( 'obituary_submissions_admin_email', get_option( 'admin_email' ) );
		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$site_name   = get_bloginfo( 'name' );
		$edit_url    = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		$order_url   = $order->get_edit_order_url();
		$order_total = $order->get_formatted_order_total();
		$name        = sanitize_text_field( $submission['name'] ?? '' );
		$death_date  = sanitize_text_field( $submission['death_date'] ?? '' );
		$content     = wp_strip_all_tags( $submission['content'] ?? '' );
		$video_url   = esc_url_raw( $submission['video_url'] ?? '' );
		$preview     = mb_strlen( $content ) > 300 ? mb_substr( $content, 0, 300 ) . '…' : $content;

		$subject  = sprintf( __( '[%s] New obituary submission received', 'obituary-submissions' ), $site_name );
		$body     = sprintf( __( 'A new obituary submission has been received and paid for on %s.', 'obituary-submissions' ), $site_name ) . "\n\n";
		$body    .= "----------------------------------------\n";
		$body    .= sprintf( __( 'Deceased Name : %s', 'obituary-submissions' ), $name ) . "\n";
		$body    .= sprintf( __( 'Date of Death : %s', 'obituary-submissions' ), $death_date ) . "\n";
		$body    .= sprintf( __( 'Order #       : %s', 'obituary-submissions' ), $order->get_order_number() ) . "\n";
		$body    .= sprintf( __( 'Order Total   : %s', 'obituary-submissions' ), wp_strip_all_tags( $order_total ) ) . "\n";
		if ( $video_url ) {
			$body .= sprintf( __( 'Video URL     : %s', 'obituary-submissions' ), $video_url ) . "\n";
		}
		$body    .= "----------------------------------------\n\n";
		$body    .= __( 'Obituary Preview:', 'obituary-submissions' ) . "\n\n" . $preview . "\n\n";
		$body    .= "----------------------------------------\n\n";
		$body    .= sprintf( __( 'Review & publish draft : %s', 'obituary-submissions' ), $edit_url ) . "\n";
		$body    .= sprintf( __( 'View WooCommerce order : %s', 'obituary-submissions' ), $order_url ) . "\n";

		wp_mail( $admin_email, $subject, $body, [
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>',
		] );
	}

	public static function render_receipt_section( array $submission, bool $plain_text ): void {
		$name       = sanitize_text_field( $submission['name'] ?? '' );
		$death_date = sanitize_text_field( $submission['death_date'] ?? '' );
		$content    = $submission['content'] ?? '';
		$video_url  = esc_url_raw( $submission['video_url'] ?? '' );

		if ( $plain_text ) {
			self::render_receipt_plain( $name, $death_date, $content, $video_url );
		} else {
			self::render_receipt_html( $name, $death_date, $content, $video_url );
		}
	}

	private static function render_receipt_html( string $name, string $death_date, string $content, string $video_url ): void {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		?>
		<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:40px;border-top:1px solid #e5e5e5;">
			<tr><td style="padding:30px 0 0;">
				<h2 style="font-size:18px;font-weight:bold;color:#333;margin:0 0 12px;"><?php esc_html_e( 'Your Obituary Submission', 'obituary-submissions' ); ?></h2>
				<p style="color:#555;margin:0 0 16px;"><?php printf( esc_html__( 'Below is a copy of the obituary you submitted to %s. Our staff will review it before publication.', 'obituary-submissions' ), $site_name ); ?></p>
				<table width="100%" cellpadding="0" cellspacing="0" style="background:#f9f9f9;border:1px solid #e5e5e5;border-radius:4px;margin-bottom:20px;">
					<tr><td style="padding:20px;">
						<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Name of Deceased:', 'obituary-submissions' ); ?></strong><br><?php echo esc_html( $name ); ?></p>
						<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Date of Death:', 'obituary-submissions' ); ?></strong><br><?php echo esc_html( $death_date ); ?></p>
						<?php if ( $video_url ) : ?>
							<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Memorial Video:', 'obituary-submissions' ); ?></strong><br><a href="<?php echo esc_url( $video_url ); ?>" style="color:#0073aa;"><?php echo esc_html( $video_url ); ?></a></p>
						<?php endif; ?>
						<p style="margin:16px 0 8px;"><strong><?php esc_html_e( 'Obituary:', 'obituary-submissions' ); ?></strong></p>
						<div style="white-space:pre-wrap;color:#333;line-height:1.6;"><?php echo wp_kses_post( $content ); ?></div>
					</td></tr>
				</table>
				<p style="color:#777;font-size:13px;margin:0;"><?php esc_html_e( 'Please keep this email for your records.', 'obituary-submissions' ); ?></p>
			</td></tr>
		</table>
		<?php
	}

	private static function render_receipt_plain( string $name, string $death_date, string $content, string $video_url ): void {
		echo "\n\n========================================\n";
		echo strtoupper( __( 'Your Obituary Submission', 'obituary-submissions' ) ) . "\n";
		echo "========================================\n\n";
		printf( __( 'Name of Deceased : %s', 'obituary-submissions' ) . "\n", $name );
		printf( __( 'Date of Death    : %s', 'obituary-submissions' ) . "\n", $death_date );
		if ( $video_url ) {
			printf( __( 'Memorial Video   : %s', 'obituary-submissions' ) . "\n", $video_url );
		}
		echo "\n" . __( 'Obituary:', 'obituary-submissions' ) . "\n\n";
		echo wp_strip_all_tags( $content ) . "\n";
		echo "\n========================================\n";
		printf( __( 'Our staff will review your obituary before publication on %s.', 'obituary-submissions' ) . "\n", get_bloginfo( 'name' ) );
	}
}
