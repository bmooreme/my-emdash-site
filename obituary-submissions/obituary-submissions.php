<?php
/**
 * Plugin Name:       Obituary Submissions
 * Plugin URI:        https://example.com/obituary-submissions
 * Description:       Allow logged-in users to submit obituaries and pay for them via WooCommerce. Submissions are saved as draft posts pending admin review.
 * Version:           1.1.0
 * Author:            Your Name
 * Text Domain:       obituary-submissions
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'OBITUARY_SUBMISSIONS_VERSION', '1.1.0' );
define( 'OBITUARY_SUBMISSIONS_FILE',    __FILE__ );
define( 'OBITUARY_SUBMISSIONS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'OBITUARY_SUBMISSIONS_URL',     plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

function obituary_submissions_has_woocommerce(): bool {
	return class_exists( 'WooCommerce' );
}

add_action( 'admin_notices', function () {
	if ( ! obituary_submissions_has_woocommerce() ) {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Obituary Submissions requires WooCommerce to be installed and active.', 'obituary-submissions' )
			. '</p></div>';
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! obituary_submissions_has_woocommerce() ) {
		return;
	}
	require_once OBITUARY_SUBMISSIONS_DIR . 'includes/class-obituary-admin.php';
	require_once OBITUARY_SUBMISSIONS_DIR . 'includes/class-obituary-form.php';
	require_once OBITUARY_SUBMISSIONS_DIR . 'includes/class-obituary-woocommerce.php';
	require_once OBITUARY_SUBMISSIONS_DIR . 'includes/class-obituary-emails.php';

	Obituary_Admin::init();
	Obituary_Form::init();
	Obituary_WooCommerce::init();
	Obituary_Emails::init();
} );

register_activation_hook( __FILE__, function () {
	add_option( 'obituary_submissions_price',       '50.00' );
	add_option( 'obituary_submissions_category_id', '' );
	add_option( 'obituary_submissions_admin_email', get_option( 'admin_email' ) );
	add_option( 'obituary_submissions_product_id',  '' );
} );

register_deactivation_hook( __FILE__, '__return_false' );
