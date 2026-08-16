<?php
/**
 * Plugin Name: Rise & Radiate Rescue
 * Description: Repairs the current Rise & Radiate site, restores missing service pages, cleans template residue, and applies a light visual refresh.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Marcus Cheong
 * License: GPL-2.0-or-later
 * Text Domain: rise-and-radiate-rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RAR_RESCUE_VERSION', '0.1.0' );
define( 'RAR_RESCUE_FILE', __FILE__ );
define( 'RAR_RESCUE_DIR', plugin_dir_path( __FILE__ ) );

register_activation_hook( __FILE__, 'rar_rescue_activate' );

/**
 * Apply the rescue when the plugin is activated.
 */
function rar_rescue_activate() {
	rar_rescue_apply();
	set_transient( 'rar_rescue_activation_notice', 1, MINUTE_IN_SECONDS * 10 );
	flush_rewrite_rules();
}

/**
 * Run every idempotent rescue operation and retain a short report.
 *
 * @return array<string, string>
 */
function rar_rescue_apply() {
	$report = array();

	rar_rescue_capture_site_backup();
	rar_rescue_fix_site_identity( $report );
	rar_rescue_clean_legacy_slugs( $report );

	$teen_id  = rar_rescue_upsert_service_page( 'teen-coaching', 'Teen Coaching', rar_rescue_teen_content(), $report );
	$adult_id = rar_rescue_upsert_service_page( 'adults', 'Adult Coaching', rar_rescue_adult_content(), $report );

	rar_rescue_refresh_about_page( $report );
	rar_rescue_repair_home_links( $report );
	rar_rescue_link_contact_details( $report );
	rar_rescue_repair_navigation( $teen_id, $adult_id, $report );
	rar_rescue_install_brand_mark( $report );
	rar_rescue_create_privacy_draft( $report );

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
 * Preserve the original global settings once.
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
 * Save the original state of a page before the first modification.
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
 * Replace only obvious template-default identity values.
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
		update_option( 'blogdescription', 'Helping families grow with confidence, connection, and purpose.' );
		$report['tagline'] = 'Added a clear site tagline.';
	}
}

/**
 * Rename demo-template slugs while retaining WordPress revisions and old-slug redirects.
 *
 * @param array<string, string> $report Report items.
 */
function rar_rescue_clean_legacy_slugs( &$report ) {
	$changes = array(
		'web-agency-gb-about-us'  => 'about',
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
 * Find a page by slug regardless of its current status.
 *
 * @param string $slug Page slug.
 * @return WP_Post|null
 */
function rar_rescue_find_page_any_status( $slug ) {
	$posts = get_posts(
		array(
			'name'             => $slug,
			'post_type'        => 'page',
			'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
			'numberposts'      => 1,
			'suppress_filters' => true,
		)
	);

	return $posts ? $posts[0] : null;
}

/**
 * Restore or create a missing service page.
 *
 * @param string                $slug    Page slug.
 * @param string                $title   Page title.
 * @param string                $content Block content.
 * @param array<string, string> $report  Report items.
 * @return int
 */
function rar_rescue_upsert_service_page( $slug, $title, $content, &$report ) {
	$page = rar_rescue_find_page_any_status( $slug );

	if ( $page instanceof WP_Post ) {
		rar_rescue_backup_page( $page->ID );
		if ( 'trash' === $page->post_status ) {
			wp_untrash_post( $page->ID );
		}
		wp_save_post_revision( $page->ID );
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $page->ID,
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => $content,
					'post_status'  => 'publish',
				)
			),
			true
		);
	} else {
		$result = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => $content,
					'post_status'  => 'publish',
				)
			),
			true
		);
	}

	if ( is_wp_error( $result ) ) {
		$report[ 'page_' . $slug ] = 'Could not create the service page: ' . $result->get_error_message();
		return 0;
	}

	$report[ 'page_' . $slug ] = sprintf( 'Published the missing %s page.', $title );
	return (int) $result;
}

/**
 * Replace the duplicated About content with a concise, personal version.
 *
 * @param array<string, string> $report Report items.
 */
function rar_rescue_refresh_about_page( &$report ) {
	$page = get_page_by_path( 'about' );
	if ( ! $page instanceof WP_Post ) {
		return;
	}

	if ( false !== strpos( $page->post_content, 'rar-about-page' ) ) {
		return;
	}

	rar_rescue_backup_page( $page->ID );
	wp_save_post_revision( $page->ID );
	$result = wp_update_post(
		wp_slash(
			array(
				'ID'           => $page->ID,
				'post_title'   => 'About Aoife',
				'post_content' => rar_rescue_about_content(),
			)
		),
		true
	);

	if ( ! is_wp_error( $result ) ) {
		$report['about'] = 'Removed the duplicated About section and introduced Aoife clearly.';
	}
}

/**
 * Repair the homepage dead button without replacing the existing page.
 *
 * @param array<string, string> $report Report items.
 */
function rar_rescue_repair_home_links( &$report ) {
	$front_page_id = (int) get_option( 'page_on_front' );
	if ( ! $front_page_id ) {
		$page = get_page_by_path( 'web-agency-gb-home' );
		$front_page_id = $page instanceof WP_Post ? $page->ID : 0;
	}

	$page = $front_page_id ? get_post( $front_page_id ) : null;
	if ( ! $page instanceof WP_Post ) {
		return;
	}

	$about_url = esc_url( home_url( '/about/' ) );
	$content   = str_replace( 'href="#"', 'href="' . $about_url . '"', $page->post_content );

	if ( $content === $page->post_content ) {
		return;
	}

	rar_rescue_backup_page( $page->ID );
	wp_save_post_revision( $page->ID );
	wp_update_post(
		wp_slash(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		)
	);
	$report['home_link'] = 'Connected the dead Learn More button to the About page.';
}

/**
 * Turn the plain contact details into useful links while preserving the form.
 *
 * @param array<string, string> $report Report items.
 */
function rar_rescue_link_contact_details( &$report ) {
	$page = get_page_by_path( 'contact' );
	if ( ! $page instanceof WP_Post ) {
		return;
	}

	$content = $page->post_content;
	if ( false === strpos( $content, 'mailto:hello@riseandradiate.net' ) ) {
		$content = str_replace(
			'hello@riseandradiate.net',
			'<a href="mailto:hello@riseandradiate.net">hello@riseandradiate.net</a>',
			$content
		);
	}
	if ( false === strpos( $content, 'wa.me/35677535096' ) ) {
		$content = str_replace(
			'(+356) 77 53 50 96',
			'<a href="https://wa.me/35677535096">(+356) 77 53 50 96</a>',
			$content
		);
	}

	if ( $content === $page->post_content ) {
		return;
	}

	rar_rescue_backup_page( $page->ID );
	wp_save_post_revision( $page->ID );
	wp_update_post(
		wp_slash(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		)
	);
	$report['contact_links'] = 'Made the email address and WhatsApp number clickable.';
}

/**
 * Point the existing menu entries at the repaired service pages.
 *
 * @param int                   $teen_id  Teen page ID.
 * @param int                   $adult_id Adult page ID.
 * @param array<string, string> $report   Report items.
 */
function rar_rescue_repair_navigation( $teen_id, $adult_id, &$report ) {
	$targets = array(
		'teen coaching' => $teen_id,
		'adults'        => $adult_id,
		'adult coaching' => $adult_id,
	);
	$updated = 0;

	foreach ( wp_get_nav_menus() as $menu ) {
		$items = wp_get_nav_menu_items( $menu->term_id );
		if ( ! is_array( $items ) ) {
			continue;
		}

		foreach ( $items as $item ) {
			$key = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
			if ( empty( $targets[ $key ] ) ) {
				continue;
			}

			$result = wp_update_nav_menu_item(
				$menu->term_id,
				$item->ID,
				array(
					'menu-item-title'      => $item->title,
					'menu-item-object-id'  => $targets[ $key ],
					'menu-item-object'     => 'page',
					'menu-item-type'       => 'post_type',
					'menu-item-status'     => 'publish',
					'menu-item-position'   => $item->menu_order,
					'menu-item-parent-id'  => $item->menu_item_parent,
					'menu-item-attr-title' => $item->attr_title,
					'menu-item-target'     => $item->target,
					'menu-item-classes'    => implode( ' ', (array) $item->classes ),
					'menu-item-xfn'        => $item->xfn,
					'menu-item-description'=> $item->description,
				)
			);

			if ( ! is_wp_error( $result ) ) {
				++$updated;
			}
		}
	}

	if ( $updated ) {
		$report['navigation'] = sprintf( 'Repaired %d broken service navigation links.', $updated );
	}
}

/**
 * Import the existing Rise & Radiate brand mark and replace the template logo.
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

	$upload = wp_upload_bits( 'rise-radiate-mark.png', null, file_get_contents( $source ) );
	if ( ! empty( $upload['error'] ) ) {
		$report['logo'] = 'The brand mark could not be imported: ' . $upload['error'];
		return;
	}

	$filetype = wp_check_filetype( $upload['file'] );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => 'Rise & Radiate brand mark',
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
	$report['logo'] = 'Replaced the Agency template logo with the Rise & Radiate mark.';
}

/**
 * Create an unpublished privacy starter for professional review.
 *
 * @param array<string, string> $report Report items.
 */
function rar_rescue_create_privacy_draft( &$report ) {
	if ( get_page_by_path( 'privacy' ) || get_page_by_path( 'privacy-policy' ) ) {
		return;
	}

	$page_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Privacy Notice — Draft',
				'post_name'    => 'privacy',
				'post_content' => rar_rescue_privacy_draft_content(),
				'post_status'  => 'draft',
			)
		),
		true
	);

	if ( ! is_wp_error( $page_id ) ) {
		update_option( 'wp_page_for_privacy_policy', (int) $page_id );
		$report['privacy'] = 'Created an unpublished privacy-notice draft for review.';
	}
}

/**
 * Redirect legacy numeric URLs if WordPress still receives them.
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
 * Add a concise homepage description when no SEO plugin is already responsible.
 */
function rar_rescue_meta_description() {
	if ( ! is_front_page() ) {
		return;
	}

	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
		return;
	}

	echo '<meta name="description" content="' . esc_attr( 'Practical, compassionate coaching and education for parents, teenagers, adults, and organisations.' ) . '">' . "\n";
}
add_action( 'wp_head', 'rar_rescue_meta_description', 1 );

/**
 * Scope the rescue stylesheet and provide its portable image URL.
 */
function rar_rescue_front_assets() {
	wp_enqueue_style(
		'rar-rescue',
		plugins_url( 'assets/css/rescue.css', RAR_RESCUE_FILE ),
		array(),
		RAR_RESCUE_VERSION
	);
	wp_add_inline_style(
		'rar-rescue',
		':root{--rar-ocean-image:url("' . esc_url( plugins_url( 'assets/images/family-connection.jpg', RAR_RESCUE_FILE ) ) . '");}'
	);
}
add_action( 'wp_enqueue_scripts', 'rar_rescue_front_assets', 20 );

/**
 * Add a body class so the CSS remains isolated.
 *
 * @param string[] $classes Existing body classes.
 * @return string[]
 */
function rar_rescue_body_class( $classes ) {
	$classes[] = 'rar-rescue-active';
	return $classes;
}
add_filter( 'body_class', 'rar_rescue_body_class' );

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
 * Register a small status page for Marcus and Aoife.
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
 * Re-run the repair from the status page.
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
		<p><?php esc_html_e( 'This focused upgrade repairs the existing site. Original page content was saved before the first change, and WordPress revisions were created before replacements.', 'rise-and-radiate-rescue' ); ?></p>

		<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'The rescue checks were run again.', 'rise-and-radiate-rescue' ); ?></p></div>
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
		<p><?php esc_html_e( 'Use Pages in the left-hand WordPress menu. Open a page, click the text or image, make the change, preview it, and press Update. No code is required.', 'rise-and-radiate-rescue' ); ?></p>
		<p><strong><?php esc_html_e( 'Privacy reminder:', 'rise-and-radiate-rescue' ); ?></strong> <?php esc_html_e( 'A draft was created but intentionally left unpublished until the wording has been reviewed.', 'rise-and-radiate-rescue' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rar_rescue_run">
			<?php wp_nonce_field( 'rar_rescue_run' ); ?>
			<?php submit_button( __( 'Run rescue checks again', 'rise-and-radiate-rescue' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}

/**
 * About-page block content.
 *
 * @return string
 */
function rar_rescue_about_content() {
	$contact_url = esc_url( home_url( '/contact/' ) );
	return <<<HTML
<!-- wp:group {"align":"full","className":"rar-page-hero rar-about-page","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull rar-page-hero rar-about-page"><!-- wp:paragraph {"className":"rar-eyebrow"} -->
<p class="rar-eyebrow">About Rise &amp; Radiate</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Support that begins with dignity, connection, and possibility.</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">Rise &amp; Radiate is led by Aoife, a Certified Positive Discipline Parent Educator who supports people to make practical, lasting changes without judgement or pressure to be perfect.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"rar-content-section","layout":{"type":"constrained"}} -->
<div class="wp-block-group rar-content-section"><!-- wp:heading -->
<h2 class="wp-block-heading">A calm, practical approach</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Parenting and family life can be deeply meaningful and deeply challenging. Aoife helps parents move away from repeated conflict, shouting, or uncertainty and towards relationships built on connection, mutual respect, and trust.</p>
<!-- /wp:paragraph -->
<!-- wp:list -->
<ul><li>Understand the needs underneath behaviour</li><li>Set boundaries that are both kind and firm</li><li>Build cooperation through connection rather than control</li><li>Use realistic tools in everyday family situations</li></ul>
<!-- /wp:list -->
<!-- wp:paragraph -->
<p>This is not about perfect parenting. It is about creating something sustainable, respectful, and real for your family.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"wide","className":"rar-callout","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide rar-callout"><!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Wondering whether this support is right for you?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>You do not need to know exactly what you need before getting in touch.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{$contact_url}">Talk to Aoife</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
HTML;
}

/**
 * Teen-coaching page block content.
 *
 * @return string
 */
function rar_rescue_teen_content() {
	$contact_url = esc_url( add_query_arg( 'service', 'teen-coaching', home_url( '/contact/' ) ) );
	return <<<HTML
<!-- wp:group {"align":"full","className":"rar-page-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull rar-page-hero"><!-- wp:paragraph {"className":"rar-eyebrow"} -->
<p class="rar-eyebrow">Teen coaching</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">A supportive space for confidence, resilience, and direction.</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">Strengths-based coaching helps teenagers understand themselves, navigate challenges, and take thoughtful steps towards the person they want to become.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"rar-service-columns"} -->
<div class="wp-block-columns alignwide rar-service-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2 class="wp-block-heading">Coaching can support</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul><li>confidence and self-understanding</li><li>emotional resilience</li><li>healthy decision-making</li><li>a stronger sense of identity and purpose</li></ul>
<!-- /wp:list --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2 class="wp-block-heading">A respectful first step</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Every teenager and family is different. An initial conversation helps clarify what is happening, what support may be useful, and whether coaching is an appropriate fit.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{$contact_url}">Enquire about teen coaching</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
HTML;
}

/**
 * Adult-coaching page block content.
 *
 * @return string
 */
function rar_rescue_adult_content() {
	$contact_url = esc_url( add_query_arg( 'service', 'adult-coaching', home_url( '/contact/' ) ) );
	return <<<HTML
<!-- wp:group {"align":"full","className":"rar-page-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull rar-page-hero"><!-- wp:paragraph {"className":"rar-eyebrow"} -->
<p class="rar-eyebrow">Adult coaching</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Create more balance, clarity, and strength in everyday life.</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">A thoughtful coaching space for adults and fathers navigating work, family life, relationships, or a period of change.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"rar-service-columns"} -->
<div class="wp-block-columns alignwide rar-service-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2 class="wp-block-heading">You may be looking for</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul><li>greater calm and emotional resilience</li><li>clarity about values and priorities</li><li>healthier patterns in work and relationships</li><li>support through transition or uncertainty</li></ul>
<!-- /wp:list --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2 class="wp-block-heading">Start with a conversation</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>You do not need to arrive with everything worked out. A first conversation can help clarify what you want to change and whether coaching is a good fit.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{$contact_url}">Enquire about adult coaching</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
HTML;
}

/**
 * Privacy starter content that is never published automatically.
 *
 * @return string
 */
function rar_rescue_privacy_draft_content() {
	return <<<'HTML'
<!-- wp:paragraph {"backgroundColor":"pale-pink","className":"rar-draft-warning"} -->
<p class="has-pale-pink-background-color has-background rar-draft-warning"><strong>Draft for review:</strong> Do not publish this page until Aoife has confirmed the services used by the website and the wording has been checked for the business.</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Information we collect</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Describe the information collected through the contact form, email, telephone, WhatsApp, analytics, cookies, bookings, and any mailing list.</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Why we use it</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Explain the purpose and lawful basis for each use, including responding to enquiries and providing agreed services.</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Storage, sharing, and retention</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>List the hosting, email, form, analytics, booking, payment, and communications providers involved. State how long information is retained and where it is processed.</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Your choices and rights</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Explain how a person can ask about, correct, delete, restrict, or obtain a copy of their information, and how to raise a concern with the relevant authority.</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Contact</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Email <a href="mailto:hello@riseandradiate.net">hello@riseandradiate.net</a> with questions about this notice or personal information.</p>
<!-- /wp:paragraph -->
HTML;
}
