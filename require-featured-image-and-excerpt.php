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
				array( 'sm
