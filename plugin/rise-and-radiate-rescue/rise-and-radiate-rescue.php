<?php
/**
 * Plugin Name: Rise & Radiate Rescue
 * Description: Rebuilds the current Rise & Radiate site as a complete, editable WordPress experience while preserving its original copy.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Marcus Cheong
 * License: GPL-2.0-or-later
 * Text Domain: rise-and-radiate-rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RAR_RESCUE_VERSION', '1.1.0' );
define( 'RAR_RESCUE_FILE', __FILE__ );
define( 'RAR_RESCUE_DIR', plugin_dir_path( __FILE__ ) );

require_once RAR_RESCUE_DIR . 'includes/redesign.php';

register_activation_hook( __FILE__, 'rar_rescue_activate' );

/**
 * Preserve the current site, install the rebuild, and report completion.
 */
function rar_rescue_activate() {
	rar_rescue_apply();
	set_transient( 'rar_rescue_activation_notice', 1, MINUTE_IN_SECONDS * 10 );
	flush_rewrite_rules();
}

/**
 * Run every idempotent installation operation.
 *
 * @return array<string, string>
 */
function rar_rescue_apply() {
	$report = array();

	rar_rescue_capture_site_backup();
	rar_rescue_fix_site_identity( $report );
	rar_rescue_clean_legacy_slugs( $report );
	rar_rescue_install_brand_mark( $report );

	if ( function_exists( 'rar_redesign_apply' ) ) {
		rar_redesign_apply();
		$report['site'] = 'Installed the complete Rise & Radiate page system.';
	}

	update_option(
		'rar_rescue_last_report',
		array(
			'time'  => current_time( 'mysql' ),
			'items' => $report,
		),
		false
	);

	return $report;
}

/**
 * Preserve global settings once, before the first change.
 */
function rar_rescue_capture_site_backup() {
	if ( get_option( 'rar_rescue_backup_v1' ) ) {
		return;
	}

	update_option(
		'rar_rescue_backup_v1',
		array(
			'time'        => current_time( 'mysql' ),
			'blogname'    => get_option( 'blogname' ),
			'description' => get_option( 'blogdescription' ),
			'custom_logo' => get_theme_mod( 'custom_logo' ),
			'pages'       => array(),
		),
		false
	);
}

/**
 * Preserve one page before its first update.
 *
 * @param int $post_id Page ID.
 */
function rar_rescue_backup_page( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$backup = get_option( 'rar_rescue_backup_v1', array() );
	if ( ! isset( $backup['pages'] ) || ! is_array( $backup['pages'] ) ) {
		$backup['pages'] = array();
	}

	if ( isset( $backup['pages'][ $post_id ] ) ) {
		return;
	}

	$backup['pages'][ $post_id ] = array(
		'post_title'   => $post->post_title,
		'post_name'    => $post->post_name,
		'post_status'  => $post->post_status,
		'post_content' => $post->post_content,
	);

	update_option( 'rar_rescue_backup_v1', $backup, false );
}

/**
 * Replace only obvious starter identity values.
 *
 * @param array<string, string> $report Report items.
 */
function rar_rescue_fix_site_identity( &$report ) {
	$current_name = trim( (string) get_option( 'blogname' ) );
	if ( '' === $current_name || in_array( strtolower( $current_name ), array( 'my wordpress', 'agency' ), true ) ) {
		update_option( 'blogname', 'Rise & Radiate' );
		$report['site_title'] = 'Set the site title to Rise & Radiate.';
	}

	$current_description = trim( (string) get_option( 'blogdescription' ) );
	if ( '' === $current_description || 'just another wordpress site' === strtolower( $current_description ) ) {
		update_option( 'blogdescription', 'Supporting families to grow with confidence, connection, and purpose.' );
		$report['tagline'] = 'Set the published Rise & Radiate tagline.';
	}
}

/**
 * Clean the two demo-template slugs while retaining page revisions.
 *
 * @param array<string, string> $report Report items.
 */
function rar_rescue_clean_legacy_slugs( &$report ) {
	$changes = array(
		'web-agency-gb-about-us'   => 'about',
		'web-agency-gb-contact-us' => 'contact',
	);

	foreach ( $changes as $old_slug => $new_slug ) {
		if ( get_page_by_path( $new_slug ) ) {
			continue;
		}

		$page = get_page_by_path( $old_slug );
		if ( ! $page instanceof WP_Post ) {
			continue;
		}

		rar_rescue_backup_page( $page->ID );
		wp_save_post_revision( $page->ID );
		$result = wp_update_post(
			array(
				'ID'        => $page->ID,
				'post_name' => $new_slug,
			),
			true
		);

		if ( ! is_wp_error( $result ) ) {
			$report[ 'slug_' . $new_slug ] = sprintf( 'Changed /%1$s/ to /%2$s/.', $old_slug, $new_slug );
		}
	}
}

/**
 * Import the site's existing brand mark for normal WordPress theme fallbacks.
 *
 * @param array<string, string> $report Report items.
 */
function rar_rescue_install_brand_mark( &$report ) {
	$attachment_id = (int) get_option( 'rar_rescue_brand_attachment_id' );
	if ( $attachment_id && get_post( $attachment_id ) ) {
		set_theme_mod( 'custom_logo', $attachment_id );
		return;
	}

	$source = RAR_RESCUE_DIR . 'assets/images/rise-radiate-mark.png';
	if ( ! file_exists( $source ) ) {
		return;
	}

	$contents = file_get_contents( $source );
	if ( false === $contents ) {
		return;
	}

	$upload = wp_upload_bits( 'rise-radiate-mark.png', null, $contents );
	if ( ! empty( $upload['error'] ) ) {
		$report['logo'] = 'The Rise & Radiate mark could not be imported.';
		return;
	}

	$filetype      = wp_check_filetype( $upload['file'] );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => 'Rise & Radiate',
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	if ( is_wp_error( $attachment_id ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Rise & Radiate' );
	update_option( 'rar_rescue_brand_attachment_id', $attachment_id, false );
	set_theme_mod( 'custom_logo', $attachment_id );
	$report['logo'] = 'Installed the existing Rise & Radiate mark.';
}

/**
 * Redirect the two broken numeric service links still used by the old site.
 */
function rar_rescue_legacy_redirects() {
	if ( ! is_404() ) {
		return;
	}

	$page_id = isset( $_GET['page_id'] ) ? absint( wp_unslash( $_GET['page_id'] ) ) : 0;
	$map     = array(
		178 => 'teen-coaching',
		187 => 'adults',
	);

	if ( isset( $map[ $page_id ] ) ) {
		$page = get_page_by_path( $map[ $page_id ] );
		if ( $page instanceof WP_Post ) {
			wp_safe_redirect( get_permalink( $page ), 301 );
			exit;
		}
	}
}
add_action( 'template_redirect', 'rar_rescue_legacy_redirects' );

/**
 * Provide the original homepage description when no SEO plugin owns it.
 */
function rar_rescue_meta_description() {
	if ( ! is_front_page() ) {
		return;
	}

	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
		return;
	}

	echo '<meta name="description" content="' . esc_attr( 'Supporting families to grow with confidence, connection, and purpose.' ) . '">' . "\n";
}
add_action( 'wp_head', 'rar_rescue_meta_description', 1 );

/**
 * Load the site stylesheet.
 */
function rar_rescue_front_assets() {
	if ( ! function_exists( 'rar_redesign_is_site_page' ) || ! rar_redesign_is_site_page() ) {
		return;
	}

	wp_enqueue_style(
		'rar-rescue',
		plugins_url( 'assets/css/rescue.css', RAR_RESCUE_FILE ),
		array(),
		RAR_RESCUE_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'rar_rescue_front_assets', 20 );

/**
 * Show a one-time completion notice.
 */
function rar_rescue_activation_notice() {
	if ( ! get_transient( 'rar_rescue_activation_notice' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	delete_transient( 'rar_rescue_activation_notice' );
	?>
	<div class="notice notice-success is-dismissible">
		<p><strong><?php esc_html_e( 'Rise & Radiate rescue applied.', 'rise-and-radiate-rescue' ); ?></strong> <?php esc_html_e( 'Review the report under Tools → Rise & Radiate Rescue.', 'rise-and-radiate-rescue' ); ?></p>
	</div>
	<?php
}
add_action( 'admin_notices', 'rar_rescue_activation_notice' );

/**
 * Register the installation status page.
 */
function rar_rescue_admin_menu() {
	add_management_page(
		__( 'Rise & Radiate Rescue', 'rise-and-radiate-rescue' ),
		__( 'Rise & Radiate Rescue', 'rise-and-radiate-rescue' ),
		'manage_options',
		'rise-and-radiate-rescue',
		'rar_rescue_render_admin_page'
	);
}
add_action( 'admin_menu', 'rar_rescue_admin_menu' );

/**
 * Re-run the installation from the status page.
 */
function rar_rescue_admin_run() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to run this repair.', 'rise-and-radiate-rescue' ) );
	}

	check_admin_referer( 'rar_rescue_run' );
	rar_rescue_apply();
	flush_rewrite_rules();
	wp_safe_redirect( admin_url( 'tools.php?page=rise-and-radiate-rescue&updated=1' ) );
	exit;
}
add_action( 'admin_post_rar_rescue_run', 'rar_rescue_admin_run' );

/**
 * Render the status page.
 */
function rar_rescue_render_admin_page() {
	$report = get_option( 'rar_rescue_last_report', array() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Rise & Radiate Rescue', 'rise-and-radiate-rescue' ); ?></h1>
		<p><?php esc_html_e( 'The complete public site is installed. Original page content and global settings were saved before the first change.', 'rise-and-radiate-rescue' ); ?></p>

		<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'The site installation was run again.', 'rise-and-radiate-rescue' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Latest report', 'rise-and-radiate-rescue' ); ?></h2>
		<?php if ( ! empty( $report['time'] ) ) : ?>
			<p><?php echo esc_html( $report['time'] ); ?></p>
		<?php endif; ?>
		<ul style="list-style:disc;padding-left:1.4rem;">
			<?php foreach ( (array) ( $report['items'] ?? array() ) as $item ) : ?>
				<li><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ul>

		<h2><?php esc_html_e( 'What Aoife can edit', 'rise-and-radiate-rescue' ); ?></h2>
		<p><?php esc_html_e( 'Use Pages in the left-hand WordPress menu for ordinary wording changes. Preview the page before pressing Update.', 'rise-and-radiate-rescue' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rar_rescue_run">
			<?php wp_nonce_field( 'rar_rescue_run' ); ?>
			<?php submit_button( __( 'Run site installation again', 'rise-and-radiate-rescue' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}
