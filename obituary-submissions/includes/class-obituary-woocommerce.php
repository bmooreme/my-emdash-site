<?php
defined( 'ABSPATH' ) || exit;

class Obituary_WooCommerce {

	const ORDER_META_POST_ID = '_obituary_post_id';
	const ITEM_META_TOKEN    = '_obituary_submission_token';

	public static function init(): void {
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'attach_token_to_cart_item' ], 10, 2 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'hide_token_from_display' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'save_token_to_order_item' ], 10, 4 );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'maybe_process_order' ], 10, 3 );
		add_action( 'woocommerce_email_after_order_table', [ __CLASS__, 'append_obituary_to_email' ], 10, 4 );
	}

	public static function attach_token_to_cart_item( array $cart_item_data, int $product_id ): array {
		if ( $product_id !== (int) get_option( 'obituary_submissions_product_id' ) ) {
			return $cart_item_data;
		}
		$token = WC()->session->get( 'obituary_submission_token' );
		if ( $token ) {
			$cart_item_data[ self::ITEM_META_TOKEN ] = sanitize_text_field( $token );
			$cart_item_data['unique_key']            = md5( $token );
		}
		return $cart_item_data;
	}

	public static function hide_token_from_display( array $item_data, array $cart_item ): array {
		return array_filter( $item_data, fn( $row ) => ( $row['key'] ?? '' ) !== self::ITEM_META_TOKEN );
	}

	public static function save_token_to_order_item( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( isset( $values[ self::ITEM_META_TOKEN ] ) ) {
			$item->add_meta_data( self::ITEM_META_TOKEN, sanitize_text_field( $values[ self::ITEM_META_TOKEN ] ), true );
		}
	}

	public static function maybe_process_order( int $order_id, string $from, string $to ): void {
		if ( ! in_array( $to, [ 'processing', 'completed' ], true ) ) {
			return;
		}
		if ( get_post_meta( $order_id, self::ORDER_META_POST_ID, true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$obituary_product_id = (int) get_option( 'obituary_submissions_product_id' );
		$token = null;
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === $obituary_product_id ) {
				$token = $item->get_meta( self::ITEM_META_TOKEN );
				break;
			}
		}
		if ( ! $token ) {
			return;
		}

		$submission = get_transient( 'obituary_submission_' . $token );
		if ( ! $submission || ! is_array( $submission ) ) {
			error_log( "Obituary Submissions: transient missing for order {$order_id}, token {$token}" );
			return;
		}

		$post_id = self::create_draft_post( $submission, $order_id );
		if ( is_wp_error( $post_id ) ) {
			error_log( 'Obituary Submissions: failed to create draft post for order ' . $order_id . ' — ' . $post_id->get_error_message() );
			return;
		}

		$order->update_meta_data( self::ORDER_META_POST_ID, $post_id );
		$order->save();

		Obituary_Emails::send_admin_notification( $post_id, $order, $submission );
	}

	private static function create_draft_post( array $data, int $order_id ) {
		$category_id = (int) get_option( 'obituary_submissions_category_id' );
		$post_args   = [
			'post_title'    => sanitize_text_field( $data['name'] ),
			'post_content'  => wp_kses_post( $data['content'] ),
			'post_status'   => 'draft',
			'post_author'   => (int) $data['user_id'],
			'post_type'     => 'post',
			'post_date'     => current_time( 'mysql' ),
		];
		if ( $category_id ) {
			$post_args['post_category'] = [ $category_id ];
		}

		$post_id = wp_insert_post( $post_args, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $data['featured_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $data['featured_id'] );
			wp_update_post( [ 'ID' => (int) $data['featured_id'], 'post_parent' => $post_id ] );
		}
		if ( ! empty( $data['gallery_ids'] ) && is_array( $data['gallery_ids'] ) ) {
			$gallery_ids = array_map( 'absint', $data['gallery_ids'] );
			update_post_meta( $post_id, '_obituary_gallery', $gallery_ids );
			foreach ( $gallery_ids as $gid ) {
				wp_update_post( [ 'ID' => $gid, 'post_parent' => $post_id ] );
			}
		}
		if ( ! empty( $data['death_date'] ) ) {
			update_post_meta( $post_id, '_obituary_death_date', sanitize_text_field( $data['death_date'] ) );
		}
		if ( ! empty( $data['video_url'] ) ) {
			update_post_meta( $post_id, '_obituary_video_url', esc_url_raw( $data['video_url'] ) );
		}
		update_post_meta( $post_id, '_obituary_order_id', $order_id );
		if ( ! empty( $data['submitted_at'] ) ) {
			update_post_meta( $post_id, '_obituary_submitted_at', sanitize_text_field( $data['submitted_at'] ) );
		}

		return $post_id;
	}

	public static function append_obituary_to_email( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin ) {
			return;
		}
		$allowed = [ 'customer_processing_order', 'customer_completed_order', 'customer_invoice' ];
		if ( ! in_array( $email->id, $allowed, true ) ) {
			return;
		}

		$obituary_product_id = (int) get_option( 'obituary_submissions_product_id' );
		$token = null;
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === $obituary_product_id ) {
				$token = $item->get_meta( self::ITEM_META_TOKEN );
				break;
			}
		}
		if ( ! $token ) {
			return;
		}

		$submission = get_transient( 'obituary_submission_' . $token );
		if ( ! $submission ) {
			$post_id = $order->get_meta( self::ORDER_META_POST_ID );
			if ( $post_id ) {
				$post = get_post( $post_id );
				$submission = $post ? [
					'name'       => $post->post_title,
					'death_date' => get_post_meta( $post_id, '_obituary_death_date', true ),
					'content'    => $post->post_content,
					'video_url'  => get_post_meta( $post_id, '_obituary_video_url', true ),
				] : null;
			}
		}
		if ( ! $submission ) {
			return;
		}

		Obituary_Emails::render_receipt_section( $submission, $plain_text );
	}
}
