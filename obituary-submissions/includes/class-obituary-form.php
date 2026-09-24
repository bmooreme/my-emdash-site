<?php
defined( 'ABSPATH' ) || exit;

class Obituary_Form {

	const MAX_GALLERY_IMAGES = 9;
	const ALLOWED_MIME_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
	const TRANSIENT_TTL      = 86400;

	public static function init(): void {
		add_shortcode( 'obituary_submission_form', [ __CLASS__, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_obituary_submit', [ __CLASS__, 'handle_submission' ] );
		add_action( 'admin_post_nopriv_obituary_submit', [ __CLASS__, 'handle_nopriv_submission' ] );
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public static function render_shortcode( array $atts = [] ): string {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="obituary-login-required"><p>%s</p><p><a href="%s" class="obituary-btn">%s</a></p></div>',
				esc_html__( 'You must be logged in to submit an obituary.', 'obituary-submissions' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log In', 'obituary-submissions' )
			);
		}

		$product_id = (int) get_option( 'obituary_submissions_product_id' );
		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			return '<div class="obituary-notice"><p>' . esc_html__( 'Obituary submissions are not currently available. Please check back later.', 'obituary-submissions' ) . '</p></div>';
		}

		$error_key = 'obituary_errors_' . get_current_user_id();
		$errors    = get_transient( $error_key );
		delete_transient( $error_key );

		$old_data_key = 'obituary_old_data_' . get_current_user_id();
		$old_data     = get_transient( $old_data_key ) ?: [];
		delete_transient( $old_data_key );

		ob_start();
		self::render_form( $errors ?: [], $old_data );
		return ob_get_clean();
	}

	private static function render_form( array $errors, array $old ): void {
		$price      = wc_price( get_option( 'obituary_submissions_price', '50.00' ) );
		$action_url = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="obituary-form-wrap">

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="obituary-errors" role="alert">
					<ul>
						<?php foreach ( $errors as $err ) : ?>
							<li><?php echo esc_html( $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<p class="obituary-price-notice">
				<?php printf( esc_html__( 'Submission fee: %s', 'obituary-submissions' ), wp_kses_post( $price ) ); ?>
			</p>

			<form id="obituary-submission-form" method="post" action="<?php echo $action_url; ?>" enctype="multipart/form-data" novalidate>
				<input type="hidden" name="action" value="obituary_submit">
				<?php wp_nonce_field( 'obituary_submit_action', 'obituary_nonce' ); ?>

				<!-- Deceased Name -->
				<div class="obituary-field <?php echo isset( $errors['name'] ) ? 'has-error' : ''; ?>">
					<label for="obituary_name"><?php esc_html_e( 'Full Name of Deceased', 'obituary-submissions' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input type="text" id="obituary_name" name="obituary_name" value="<?php echo esc_attr( $old['obituary_name'] ?? '' ); ?>" required maxlength="200">
					<?php if ( isset( $errors['name'] ) ) : ?><span class="obituary-field-error"><?php echo esc_html( $errors['name'] ); ?></span><?php endif; ?>
				</div>

				<!-- Date of Death -->
				<div class="obituary-field <?php echo isset( $errors['death_date'] ) ? 'has-error' : ''; ?>">
					<label for="obituary_death_date"><?php esc_html_e( 'Date of Death', 'obituary-submissions' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input type="date" id="obituary_death_date" name="obituary_death_date" value="<?php echo esc_attr( $old['obituary_death_date'] ?? '' ); ?>" max="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" required>
					<?php if ( isset( $errors['death_date'] ) ) : ?><span class="obituary-field-error"><?php echo esc_html( $errors['death_date'] ); ?></span><?php endif; ?>
				</div>

				<!-- Obituary Content -->
				<div class="obituary-field <?php echo isset( $errors['content'] ) ? 'has-error' : ''; ?>">
					<label for="obituary_content"><?php esc_html_e( 'Obituary', 'obituary-submissions' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<textarea id="obituary_content" name="obituary_content" rows="12" required><?php echo esc_textarea( $old['obituary_content'] ?? '' ); ?></textarea>
					<?php if ( isset( $errors['content'] ) ) : ?><span class="obituary-field-error"><?php echo esc_html( $errors['content'] ); ?></span><?php endif; ?>
				</div>

				<!-- Featured Image -->
				<div class="obituary-field <?php echo isset( $errors['featured_image'] ) ? 'has-error' : ''; ?>">
					<label for="obituary_featured_image"><?php esc_html_e( 'Featured Photo', 'obituary-submissions' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input type="file" id="obituary_featured_image" name="obituary_featured_image" accept="image/jpeg,image/png,image/gif,image/webp" required>
					<p class="description"><?php esc_html_e( 'JPG, PNG, GIF or WebP. Max 8 MB.', 'obituary-submissions' ); ?></p>
					<div id="obituary-featured-preview" class="obituary-preview-single"></div>
					<?php if ( isset( $errors['featured_image'] ) ) : ?><span class="obituary-field-error"><?php echo esc_html( $errors['featured_image'] ); ?></span><?php endif; ?>
				</div>

				<!-- Gallery -->
				<div class="obituary-field <?php echo isset( $errors['gallery'] ) ? 'has-error' : ''; ?>">
					<label for="obituary_gallery">
						<?php esc_html_e( 'Photo Gallery', 'obituary-submissions' ); ?>
						<span class="optional"><?php esc_html_e( '(optional)', 'obituary-submissions' ); ?></span>
					</label>
					<input type="file" id="obituary_gallery" name="obituary_gallery[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
					<p class="description">
						<?php printf( esc_html__( 'Upload up to %d photos. Drag thumbnails to reorder. JPG, PNG, GIF or WebP. Max 8 MB each.', 'obituary-submissions' ), self::MAX_GALLERY_IMAGES ); ?>
					</p>
					<div id="obituary-gallery-preview" class="obituary-preview-grid"></div>
					<?php if ( isset( $errors['gallery'] ) ) : ?><span class="obituary-field-error"><?php echo esc_html( $errors['gallery'] ); ?></span><?php endif; ?>
				</div>

				<!-- Video URL -->
				<div class="obituary-field <?php echo isset( $errors['video_url'] ) ? 'has-error' : ''; ?>">
					<label for="obituary_video_url">
						<?php esc_html_e( 'Memorial Video Link', 'obituary-submissions' ); ?>
						<span class="optional"><?php esc_html_e( '(optional)', 'obituary-submissions' ); ?></span>
					</label>
					<input type="url" id="obituary_video_url" name="obituary_video_url" value="<?php echo esc_attr( $old['obituary_video_url'] ?? '' ); ?>" placeholder="https://www.youtube.com/watch?v=..." maxlength="500">
					<p class="description"><?php esc_html_e( 'YouTube or Vimeo link to a memorial video.', 'obituary-submissions' ); ?></p>
					<?php if ( isset( $errors['video_url'] ) ) : ?><span class="obituary-field-error"><?php echo esc_html( $errors['video_url'] ); ?></span><?php endif; ?>
				</div>

				<div class="obituary-submit-wrap">
					<button type="submit" class="obituary-btn obituary-btn-primary" id="obituary-submit-btn">
						<?php printf( esc_html__( 'Submit & Pay %s', 'obituary-submissions' ), wp_kses_post( $price ) ); ?>
					</button>

					<!-- Hidden until the user clicks Submit & Pay -->
					<p id="obituary-processing-msg" class="obituary-processing" hidden aria-live="polite">
						<?php esc_html_e( 'Processing your submission&hellip;', 'obituary-submissions' ); ?>
					</p>

					<p class="obituary-submit-note">
						<?php esc_html_e( 'You will be taken to a secure checkout after submitting. Your obituary will be reviewed by our staff before publication.', 'obituary-submissions' ); ?>
					</p>
				</div>

			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Form submission handler
	// -------------------------------------------------------------------------

	public static function handle_nopriv_submission(): void {
		wp_redirect( wp_login_url( wp_get_referer() ) );
		exit;
	}

	public static function handle_submission(): void {
		if ( ! is_user_logged_in() ) {
			wp_redirect( wp_login_url( wp_get_referer() ) );
			exit;
		}
		if ( ! isset( $_POST['obituary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['obituary_nonce'] ) ), 'obituary_submit_action' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'obituary-submissions' ) );
		}

		$name       = sanitize_text_field( wp_unslash( $_POST['obituary_name'] ?? '' ) );
		$death_date = sanitize_text_field( wp_unslash( $_POST['obituary_death_date'] ?? '' ) );
		$content    = wp_kses_post( wp_unslash( $_POST['obituary_content'] ?? '' ) );
		$video_url  = esc_url_raw( wp_unslash( $_POST['obituary_video_url'] ?? '' ) );

		$errors   = [];
		$old_data = [
			'obituary_name'       => $name,
			'obituary_death_date' => $death_date,
			'obituary_content'    => $content,
			'obituary_video_url'  => $video_url,
		];

		if ( empty( $name ) ) {
			$errors['name'] = __( 'Full name of deceased is required.', 'obituary-submissions' );
		}
		if ( empty( $death_date ) ) {
			$errors['death_date'] = __( 'Date of death is required.', 'obituary-submissions' );
		} elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $death_date ) ) {
			$errors['death_date'] = __( 'Invalid date format.', 'obituary-submissions' );
		} elseif ( strtotime( $death_date ) > time() ) {
			$errors['death_date'] = __( 'Date of death cannot be in the future.', 'obituary-submissions' );
		}
		if ( empty( trim( strip_tags( $content ) ) ) ) {
			$errors['content'] = __( 'Obituary text is required.', 'obituary-submissions' );
		}
		if ( ! empty( $video_url ) && ! self::is_valid_video_url( $video_url ) ) {
			$errors['video_url'] = __( 'Please enter a valid YouTube or Vimeo URL.', 'obituary-submissions' );
		}

		$featured_file_error = self::validate_uploaded_file( 'obituary_featured_image' );
		if ( $featured_file_error === 'missing' ) {
			$errors['featured_image'] = __( 'A featured photo is required.', 'obituary-submissions' );
		} elseif ( $featured_file_error ) {
			$errors['featured_image'] = $featured_file_error;
		}

		$gallery_names = $_FILES['obituary_gallery']['name'] ?? [];
		$gallery_count = is_array( $gallery_names ) ? count( array_filter( $gallery_names ) ) : 0;
		if ( $gallery_count > self::MAX_GALLERY_IMAGES ) {
			$errors['gallery'] = sprintf( __( 'You may upload a maximum of %d gallery photos.', 'obituary-submissions' ), self::MAX_GALLERY_IMAGES );
		} else {
			foreach ( array_keys( array_filter( (array) $gallery_names ) ) as $idx ) {
				$err = self::validate_uploaded_file_by_index( 'obituary_gallery', $idx );
				if ( $err && $err !== 'missing' ) {
					$errors['gallery'] = $err;
					break;
				}
			}
		}

		if ( ! empty( $errors ) ) {
			$uid = get_current_user_id();
			set_transient( 'obituary_errors_' . $uid, $errors, 300 );
			set_transient( 'obituary_old_data_' . $uid, $old_data, 300 );
			wp_redirect( wp_get_referer() ?: home_url() );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$featured_id = self::upload_file( 'obituary_featured_image' );
		if ( is_wp_error( $featured_id ) ) {
			$errors['featured_image'] = $featured_id->get_error_message();
			set_transient( 'obituary_errors_' . get_current_user_id(), $errors, 300 );
			wp_redirect( wp_get_referer() ?: home_url() );
			exit;
		}

		$gallery_ids = [];
		if ( $gallery_count > 0 ) {
			foreach ( array_keys( array_filter( (array) $_FILES['obituary_gallery']['name'] ) ) as $idx ) {
				$gid = self::upload_file_by_index( 'obituary_gallery', $idx );
				if ( ! is_wp_error( $gid ) ) {
					$gallery_ids[] = $gid;
				}
			}
		}

		$product_id = (int) get_option( 'obituary_submissions_product_id' );
		if ( ! $product_id ) {
			wp_die( esc_html__( 'Obituary submissions are not currently available. Please contact site administration.', 'obituary-submissions' ) );
		}

		$token      = wp_generate_password( 32, false );
		$submission = [
			'user_id'      => get_current_user_id(),
			'name'         => $name,
			'death_date'   => $death_date,
			'content'      => $content,
			'video_url'    => $video_url,
			'featured_id'  => $featured_id,
			'gallery_ids'  => $gallery_ids,
			'submitted_at' => current_time( 'mysql' ),
		];
		set_transient( 'obituary_submission_' . $token, $submission, self::TRANSIENT_TTL );

		WC()->session->set( 'obituary_submission_token', $token );
		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart( $product_id, 1 );
		if ( ! $added ) {
			wp_die( esc_html__( 'Could not add the obituary submission to your cart. Please try again.', 'obituary-submissions' ) );
		}

		wp_redirect( wc_get_checkout_url() );
		exit;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function enqueue_assets(): void {
		if ( ! is_page() ) {
			return;
		}
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, 'obituary_submission_form' ) ) {
			return;
		}
		wp_enqueue_style( 'obituary-form', OBITUARY_SUBMISSIONS_URL . 'assets/css/obituary-form.css', [], OBITUARY_SUBMISSIONS_VERSION );
		wp_enqueue_script( 'obituary-form', OBITUARY_SUBMISSIONS_URL . 'assets/js/obituary-form.js', [], OBITUARY_SUBMISSIONS_VERSION, true );
		wp_localize_script( 'obituary-form', 'obituaryFormConfig', [
			'maxGallery' => self::MAX_GALLERY_IMAGES,
			'maxSizeMB'  => 8,
			'i18n'       => [
				'tooManyImages' => sprintf( __( 'You may only upload up to %d gallery photos. Extra files have been removed.', 'obituary-submissions' ), self::MAX_GALLERY_IMAGES ),
				'invalidType'   => __( 'Only JPG, PNG, GIF and WebP images are allowed.', 'obituary-submissions' ),
				'fileTooLarge'  => __( 'One or more files exceed the 8 MB limit and have been removed.', 'obituary-submissions' ),
				'remove'        => __( 'Remove', 'obituary-submissions' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function is_valid_video_url( string $url ): bool {
		$host        = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$valid_hosts = [ 'youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ];
		return in_array( $host, $valid_hosts, true );
	}

	private static function validate_uploaded_file( string $input_name ): ?string {
		if ( empty( $_FILES[ $input_name ]['name'] ) || $_FILES[ $input_name ]['error'] === UPLOAD_ERR_NO_FILE ) {
			return 'missing';
		}
		if ( $_FILES[ $input_name ]['error'] !== UPLOAD_ERR_OK ) {
			return __( 'File upload failed. Please try again.', 'obituary-submissions' );
		}
		$mime = mime_content_type( $_FILES[ $input_name ]['tmp_name'] );
		if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return __( 'Invalid file type. Only JPG, PNG, GIF and WebP images are allowed.', 'obituary-submissions' );
		}
		if ( $_FILES[ $input_name ]['size'] > 8 * MB_IN_BYTES ) {
			return __( 'File exceeds the 8 MB limit.', 'obituary-submissions' );
		}
		return null;
	}

	private static function validate_uploaded_file_by_index( string $input_name, int $idx ): ?string {
		$file = $_FILES[ $input_name ] ?? null;
		if ( ! $file || empty( $file['name'][ $idx ] ) ) {
			return 'missing';
		}
		if ( $file['error'][ $idx ] !== UPLOAD_ERR_OK ) {
			return __( 'File upload failed. Please try again.', 'obituary-submissions' );
		}
		$mime = mime_content_type( $file['tmp_name'][ $idx ] );
		if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return __( 'Invalid file type. Only JPG, PNG, GIF and WebP images are allowed.', 'obituary-submissions' );
		}
		if ( $file['size'][ $idx ] > 8 * MB_IN_BYTES ) {
			return __( 'A gallery image exceeds the 8 MB limit.', 'obituary-submissions' );
		}
		return null;
	}

	private static function upload_file( string $input_name ) {
		$overrides = [ 'test_form' => false, 'mimes' => array_fill_keys( self::ALLOWED_MIME_TYPES, true ) ];
		$moved     = wp_handle_upload( $_FILES[ $input_name ], $overrides );
		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'upload_failed', $moved['error'] );
		}
		return self::create_attachment( $moved );
	}

	private static function upload_file_by_index( string $input_name, int $idx ) {
		$source = $_FILES[ $input_name ];
		$single = [
			'name'     => $source['name'][ $idx ],
			'type'     => $source['type'][ $idx ],
			'tmp_name' => $source['tmp_name'][ $idx ],
			'error'    => $source['error'][ $idx ],
			'size'     => $source['size'][ $idx ],
		];
		$overrides = [ 'test_form' => false, 'mimes' => array_fill_keys( self::ALLOWED_MIME_TYPES, true ) ];
		$moved     = wp_handle_upload( $single, $overrides );
		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'upload_failed', $moved['error'] );
		}
		return self::create_attachment( $moved );
	}

	private static function create_attachment( array $file_data ) {
		$filetype   = wp_check_filetype( basename( $file_data['file'] ), null );
		$upload_dir = wp_upload_dir();
		$attachment = [
			'guid'           => $upload_dir['url'] . '/' . basename( $file_data['file'] ),
			'post_mime_type' => $filetype['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_data['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];
		$attach_id = wp_insert_attachment( $attachment, $file_data['file'] );
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_data['file'] );
		wp_update_attachment_metadata( $attach_id, $attach_data );
		return $attach_id;
	}
}
