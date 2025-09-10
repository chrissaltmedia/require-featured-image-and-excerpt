<?php
/**
 * Plugin Name: Require Featured Image & Excerpt Before Publish
 * Description: Blocks publishing/scheduling of public post types unless BOTH a featured image and an excerpt are set.
 * Author: Salt Media LTD - Christopher Sheppard
 * Version: 1.0.1
 */

// ---- helpers ---------------------------------------------------------------

function sm_enforce_requirements_for_type( $post_type ) : bool {
	if ( in_array( $post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) {
		return false;
	}
	$obj = get_post_type_object( $post_type );
	return $obj && ! empty( $obj->public );
}

function sm_is_attempting_publish( array $data ) : bool {
	$status = isset( $data['post_status'] ) ? $data['post_status'] : '';
	return in_array( $status, array( 'publish', 'future' ), true );
}

function sm_missing_requirements( array $data, array $postarr ) : array {
	$missing = array();

	// Excerpt present?
	$excerpt = '';
	if ( isset( $data['post_excerpt'] ) ) {
		$excerpt = trim( (string) $data['post_excerpt'] );
	} elseif ( isset( $postarr['post_excerpt'] ) ) {
		$excerpt = trim( (string) $postarr['post_excerpt'] );
	}
	if ( $excerpt === '' ) {
		$missing[] = 'excerpt';
	}

	// Featured image present?
	$has_thumb = false;
	$post_id   = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	if ( $post_id > 0 ) {
		$has_thumb = (bool) get_post_thumbnail_id( $post_id );
	}
	if ( ! $has_thumb ) {
		if ( isset( $postarr['meta_input']['_thumbnail_id'] ) && (int) $postarr['meta_input']['_thumbnail_id'] > 0 ) {
			$has_thumb = true;
		} elseif ( isset( $_POST['_thumbnail_id'] ) && (int) $_POST['_thumbnail_id'] > 0 ) {
			$has_thumb = true;
		}
	}

	if ( ! $has_thumb ) {
		$missing[] = 'thumbnail';
	}

	return $missing;
}

function sm_requirements_message( array $missing ) : string {
	$need = array();
	if ( in_array( 'thumbnail', $missing, true ) ) $need[] = 'a featured image';
	if ( in_array( 'excerpt',   $missing, true ) ) $need[] = 'an excerpt';

	if ( count( $need ) === 2 ) {
		return 'Publishing blocked: please add a featured image and an excerpt.';
	}
	if ( count( $need ) === 1 ) {
		return 'Publishing blocked: please add ' . $need[0] . '.';
	}
	return 'Publishing blocked: required fields missing.';
}

// ---- classic/quick/bulk/programmatic (non-REST) ----------------------------

add_filter( 'wp_insert_post_data', function( $data, $postarr ) {

	if ( ! sm_is_attempting_publish( $data ) || ! sm_enforce_requirements_for_type( $data['post_type'] ) ) {
		return $data;
	}

	$missing = sm_missing_requirements( $data, $postarr );
	if ( ! empty( $missing ) ) {
		$data['post_status'] = 'draft';

		add_filter( 'redirect_post_location', function( $location ) use ( $missing ) {
			return add_query_arg(
				array( 'sm_require_feat_excerpt' => implode( ',', $missing ) ),
				$location
			);
		}, 999 );
	}

	return $data;

}, 10, 2 );

// ---- admin notice ----------------------------------------------------------

add_action( 'admin_notices', function() {
	if ( empty( $_GET['sm_require_feat_excerpt'] ) ) return;

	$missing = explode( ',', sanitize_text_field( wp_unslash( $_GET['sm_require_feat_excerpt'] ) ) );
	$msg = sm_requirements_message( $missing );

	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
});

// ---- REST (Gutenberg/editor & API) -----------------------------------------

// Accept 2 or 3 args safely (older WP may pass only 2).
function sm_rest_guard_requirements( $prepared_post, $request, $creating = null ) {
	$post_type = isset( $prepared_post->post_type ) ? $prepared_post->post_type : 'post';
	if ( ! sm_enforce_requirements_for_type( $post_type ) ) {
		return $prepared_post;
	}

	$status = isset( $prepared_post->post_status ) ? $prepared_post->post_status : '';
	if ( ! in_array( $status, array( 'publish', 'future' ), true ) ) {
		return $prepared_post;
	}

	$data = array(
		'post_status'  => $status,
		'post_type'    => $post_type,
		'post_excerpt' => isset( $prepared_post->post_excerpt ) ? (string) $prepared_post->post_excerpt : '',
	);

	$postarr = array(
		'ID' => isset( $prepared_post->ID ) ? (int) $prepared_post->ID : 0,
		'meta_input' => array(
			// WP REST uses featured_media; fall back to meta if present
			'_thumbnail_id' => (int) (
				$request instanceof WP_REST_Request
					? ( $request->get_param( 'featured_media' ) ?: $request->get_param( 'meta' )['_thumbnail_id'] ?? 0 )
					: 0
			),
		),
	);

	$missing = sm_missing_requirements( $data, $postarr );
	if ( ! empty( $missing ) ) {
		return new WP_Error(
			'sm_require_feat_excerpt',
			sm_requirements_message( $missing ),
			array( 'status' => 400 )
		);
	}

	return $prepared_post;
}

// Explicitly register for core types with accepted_args=3 (safe even if WP passes 2)
add_filter( 'rest_pre_insert_post', 'sm_rest_guard_requirements', 10, 3 );
add_filter( 'rest_pre_insert_page', 'sm_rest_guard_requirements', 10, 3 );

// Dynamically register for all other public CPTs
add_action( 'init', function() {
	foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
		if ( in_array( $type, array( 'post','page','attachment' ), true ) ) continue;
		add_filter( "rest_pre_insert_{$type}", 'sm_rest_guard_requirements', 10, 3 );
	}
});
