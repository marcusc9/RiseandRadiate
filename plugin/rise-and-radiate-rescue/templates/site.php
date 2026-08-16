<?php
/**
 * Theme-independent template for the complete Rise & Radiate site.
 *
 * @package RiseAndRadiateRescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$navigation = rar_redesign_navigation();
$mark_url   = plugins_url( 'assets/images/rise-radiate-mark.png', RAR_RESCUE_FILE );
$current_url = get_permalink();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'rar-redesign' ); ?>>
<?php wp_body_open(); ?>
<header class="rar-site-header" data-site-header>
	<a class="rar-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<span class="rar-brand-mark" aria-hidden="true"><img src="<?php echo esc_url( $mark_url ); ?>" alt=""></span>
		<span class="rar-brand-name">Rise &amp; Radiate</span>
	</a>
	<button class="rar-menu-toggle" type="button" aria-expanded="false" aria-controls="rar-site-navigation" aria-label="Menu">
		<span></span><span></span>
	</button>
	<nav id="rar-site-navigation" class="rar-site-navigation" aria-label="Navigation">
		<?php foreach ( $navigation as $label => $url ) : ?>
			<a href="<?php echo esc_url( $url ); ?>"<?php echo untrailingslashit( $url ) === untrailingslashit( $current_url ) ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
</header>

<main id="main" class="rar-site-main">
	<?php
	while ( have_posts() ) :
		the_post();
		echo do_shortcode( get_the_content() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	endwhile;
	?>
</main>

<footer class="rar-site-footer">
	<div class="rar-footer-brand">
		<span class="rar-brand-mark" aria-hidden="true"><img src="<?php echo esc_url( $mark_url ); ?>" alt=""></span>
		<h2>Rise &amp; Radiate</h2>
		<p>Supporting families to grow with confidence, connection, and purpose.</p>
	</div>
	<div class="rar-footer-contact">
		<p><a href="mailto:hello@riseandradiate.net">hello@riseandradiate.net</a></p>
		<p><a href="https://wa.me/35677535096">(+356) 77 53 50 96</a></p>
	</div>
	<nav class="rar-footer-nav" aria-label="Navigation">
		<?php foreach ( $navigation as $label => $url ) : ?>
			<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
</footer>
<?php wp_footer(); ?>
</body>
</html>
