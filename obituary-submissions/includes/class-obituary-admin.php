<?php
defined( 'ABSPATH' ) || exit;

class Obituary_Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_obituary_sync_product', [ __CLASS__, 'handle_sync_product' ] );
		add_action( 'admin_notices', [ __CLASS__, 'show_sync_notice' ] );
	}

	public static function register_settings_page(): void {
		add_options_page(
			__( 'Obituary Submissions', 'obituary-submissions' ),
			__( 'Obituary Submissions', 'obituary-submissions' ),
			'manage_options',
			'obituary-submissions',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$product_id = (int) get_option( 'obituary_submissions_product_id' );
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Obituary Submissions Settings', 'obituary-submissions' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'obituary_submissions_group' ); ?>
				<?php do_settings_sections( 'obituary-submissions' ); ?>
				<?php submit_button( __( 'Save Settings', 'obituary-submissions' ) ); ?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'WooCommerce Product', 'obituary-submissions' ); ?></h2>
			<?php if ( $product ) : ?>
				<p>
					<?php printf( esc_html__( 'Linked product: %1$s (ID %2$d)', 'obituary-submissions' ), '<strong>' . esc_html( $product->get_name() ) . '</strong>', $product_id ); ?>
					&mdash; <a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>"><?php esc_html_e( 'Edit in WooCommerce', 'obituary-submissions' ); ?></a>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'No product linked yet. Click the button below to create one.', 'obituary-submissions' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'obituary_sync_product', 'obituary_sync_nonce' ); ?>
				<input type="hidden" name="action" value="obituary_sync_product">
				<?php submit_button( $product ? __( 'Sync Price to Product', 'obituary-submissions' ) : __( 'Create WooCommerce Product', 'obituary-submissions' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function register_settings(): void {
		register_setting( 'obituary_submissions_group', 'obituary_submissions_price', [ 'type' => 'string', 'sanitize_callback' => [ __CLASS__, 'sanitize_price' ], 'default' => '50.00' ] );
		register_setting( 'obituary_submissions_group', 'obituary_submissions_category_id', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ] );
		register_setting( 'obituary_submissions_group', 'obituary_submissions_admin_email', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => get_option( 'admin_email' ) ] );

		add_settings_section( 'obituary_submissions_main', __( 'General Settings', 'obituary-submissions' ), '__return_false', 'obituary-submissions' );
		add_settings_field( 'obituary_submissions_price', __( 'Submission Price ($)', 'obituary-submissions' ), [ __CLASS__, 'field_price' ], 'obituary-submissions', 'obituary_submissions_main' );
		add_settings_field( 'obituary_submissions_category_id', __( 'Obituary Category', 'obituary-submissions' ), [ __CLASS__, 'field_category' ], 'obituary-submissions', 'obituary_submissions_main' );
		add_settings_field( 'obituary_submissions_admin_email', __( 'Admin Notification Email', 'obituary-submissions' ), [ __CLASS__, 'field_admin_email' ], 'obituary-submissions', 'obituary_submissions_main' );
	}

	public static function field_price(): void {
		$value = get_option( 'obituary_submissions_price', '50.00' );
		echo '<input type="number" id="obituary_submissions_price" name="obituary_submissions_price" value="' . esc_attr( $value ) . '" min="0" step="0.01" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Price users pay per obituary submission. Changing this updates the WooCommerce product price after you click "Sync Price to Product".', 'obituary-submissions' ) . '</p>';
	}

	public static function field_category(): void {
		$selected   = (int) get_option( 'obituary_submissions_category_id', 0 );
		$categories = get_categories( [ 'hide_empty' => false ] );
		echo '<select id="obituary_submissions_category_id" name="obituary_submissions_category_id">';
		echo '<option value="">' . esc_html__( '— Select a category —', 'obituary-submissions' ) . '</option>';
		foreach ( $categories as $cat ) {
			printf( '<option value="%d"%s>%s</option>', (int) $cat->term_id, selected( $selected, $cat->term_id, false ), esc_html( $cat->name ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Draft obituary posts will be assigned to this category.', 'obituary-submissions' ) . '</p>';
	}

	public static function field_admin_email(): void {
		$value = get_option( 'obituary_submissions_admin_email', get_option( 'admin_email' ) );
		echo '<input type="email" id="obituary_submissions_admin_email" name="obituary_submissions_admin_email" value="' . esc_attr( $value ) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Email address that receives notifications when a new obituary is submitted and paid.', 'obituary-submissions' ) . '</p>';
	}

	public static function handle_sync_product(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'obituary-submissions' ) );
		}
		check_admin_referer( 'obituary_sync_product', 'obituary_sync_nonce' );

		$product_id = (int) get_option( 'obituary_submissions_product_id' );
		$price      = get_option( 'obituary_submissions_price', '50.00' );
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product || $product->get_type() !== 'simple' ) {
			$product = new WC_Product_Simple();
			$product->set_name( __( 'Obituary Submission', 'obituary-submissions' ) );
			$product->set_description( __( 'Submit an obituary for review and publication.', 'obituary-submissions' ) );
			$product->set_virtual( true );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_status( 'publish' );
		}
		$product->set_regular_price( $price );
		$new_id = $product->save();
		update_option( 'obituary_submissions_product_id', $new_id );

		wp_redirect( add_query_arg( [ 'page' => 'obituary-submissions', 'obituary_synced' => '1' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function show_sync_notice(): void {
		if ( isset( $_GET['obituary_synced'] ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'WooCommerce product created / updated successfully.', 'obituary-submissions' ) . '</p></div>';
		}
	}

	public static function sanitize_price( $value ): string {
		$price = (float) $value;
		return $price >= 0 ? number_format( $price, 2, '.', '' ) : '0.00';
	}
}
