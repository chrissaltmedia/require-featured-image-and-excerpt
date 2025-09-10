<?php
/**
 * Plugin Name: Require Featured Image & Excerpt Before Publish (Safe Mode)
 * Description: Prevents publishing/scheduling any public post type unless BOTH a featured image and an excerpt are set. Draft saves/autosaves are never blocked. No JS, no REST hooks.
 * Author: Salt Media LTD - Christopher Sheppard
 * Version: 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------- Helpers ---------------- */

function sm_enforce_requirements_for_type( $post_type ) : bool {
	if ( in_array( $post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) return false;
	$obj = get_post_type_object( $post_type );
	return $obj && ! empty( $obj->public );
}

function sm_is_attempting_publish_status( $status ) : bool {
	return in_array( (string) $status, array( 'publish', 'future' ), true );
}

function sm_missing_requirements_from_payload( array $data, array $postarr ) : array {
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

	// If updating an existing post, check DB
	if ( $post_id > 0 && get_post_thumbnail_id( $post_id ) ) {
		$has_thumb = true;
	}

	// Otherwise respect incoming meta/_thumbnail_id (Classic/Gutenberg/REST)
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
	return count( $need ) === 2
		? 'Publishing blocked: please add a featured image and an excerpt.'
		: 'Publishing blocked: please add ' . $need[0] . '.';
}

/* -------- Core enforcement: applies to all save paths (incl. REST) --------
   - Only intercepts when trying to publish/schedule.
   - Never interferes with drafts/autosaves/AJAX, avoiding spinner deadlocks.
--------------------------------------------------------------------------- */

add_filter( 'wp_insert_post_data', function( $data, $postarr ) {

	// Sanity checks
	if ( empty( $data['post_type'] ) || ! sm_enforce_requirements_for_type( $data['post_type'] ) ) {
		return $data;
	}
	if ( ! sm_is_attempting_publish_status( $data['post_status'] ) ) {
		return $data; // allow drafts, pending, etc.
	}

	$missing = sm_missing_requirements_from_payload( $data, $postarr );
	if ( empty( $missing ) ) {
		return $data; // all good, publish/schedule allowed
	}

	// Avoid UI hangs during autosaves or AJAX requests
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
		return $data; // don't alter status mid-autosave; user can still add fields
	}

	// Force back to draft on real publish/schedule attempts
	$data['post_status'] = 'draft';

	// Add an admin notice on redirect (Classic + Gutenberg both use it)
	add_filter( 'redirect_post_location', function( $location ) use ( $missing ) {
		return add_query_arg( array( 'sm_require_feat_excerpt' => implode( ',', $missing ) ), $location );
	}, 999 );

	return $data;

}, 10, 2 );

/* -------- Admin notice after redirect -------- */

add_action( 'admin_notices', function() {
	if ( empty( $_GET['sm_require_feat_excerpt'] ) ) return;
	$missing = explode( ',', sanitize_text_field( wp_unslash( $_GET['sm_require_feat_excerpt'] ) ) );
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sm_requirements_message( $missing ) ) . '</p></div>';
});
