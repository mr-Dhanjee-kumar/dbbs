<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes every trace the plugin stored: settings, the stored page ID and all
 * project code/link meta. The projects (post content) themselves are left
 * alone so nothing is destroyed by accident — delete them from the admin first
 * if you want them gone.
 *
 * @package CCP\Clean_Code_Projects
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Bail for multisite: settings are network-wide, so let the admin decide.
if ( is_multisite() ) {
	return;
}

// Remove the mirrored .html files from uploads/clean-code-projects/.
$ccp_uploads = wp_upload_dir();
if ( ! empty( $ccp_uploads['basedir'] ) ) {
	$ccp_dir = trailingslashit( $ccp_uploads['basedir'] ) . 'clean-code-projects';
	foreach ( glob( $ccp_dir . '/*.html' ) ?: array() as $ccp_file ) {
		unlink( $ccp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( is_dir( $ccp_dir ) ) {
		rmdir( $ccp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- only succeeds when empty.
	}
}

delete_option( 'ccp_options' );
delete_option( 'ccp_projects_page_id' );
delete_transient( 'ccp_activation_notice' );

global $wpdb;

// Remove every piece of meta the plugin saved.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE m FROM {$wpdb->postmeta} AS m
		 INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id
		 WHERE p.post_type = %s
		 AND m.meta_key IN ( '_ccp_link', '_ccp_code', '_ccp_mode', '_ccp_file' )",
		'ccp_project'
	)
);
