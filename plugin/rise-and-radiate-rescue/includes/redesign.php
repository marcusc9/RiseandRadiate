<?php
/**
 * Complete site rebuild layered over the existing WordPress installation.
 *
 * @package RiseAndRadiateRescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply a new content release once while leaving later editor changes alone.
 */
function rar_redesign_maybe_upgrade() {
	if ( RAR_RESCUE_VERSION !== get_option( 'rar_redesign_version' ) ) {
		rar_redesign_apply();
		flush_rewrite_rules( false );
	}
}
add_action( 'init', 'rar_redesign_maybe_upgrade', 30 );

/**
 * Create the complete page set from the wording already published by the site.
 *
 * @return array<string, int>
 */
function rar_redesign_apply() {
	if ( function_exists( 'rar_rescue_capture_site_backup' ) ) {
		rar_rescue_capture_site_backup();
	}

	$front_id = (int) get_option( 'page_on_front' );
	$pages    = array(
		'home'                      => rar_redesign_upsert_page( 'home', 'Homepage', rar_redesign_home_content(), $front_id ),
		'about'                     => rar_redesign_upsert_page( 'about', 'About Us', rar_redesign_about_content() ),
		'parent-support'            => rar_redesign_upsert_page( 'parent-support', 'Parent Support', rar_redesign_parent_content() ),
		'teen-coaching'             => rar_redesign_upsert_page( 'teen-coaching', 'Teen Coaching', rar_redesign_teen_content() ),
		'adults'                    => rar_redesign_upsert_page( 'adults', 'Adults', rar_redesign_adults_content() ),
		'organisations-employers'   => rar_redesign_upsert_page( 'organisations-employers', 'Organisations & Employers', rar_redesign_organisations_content() ),
		'contact'                   => rar_redesign_upsert_page( 'contact', 'Contact Us', rar_redesign_contact_content() ),
	);

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $pages['home'] );
	update_option( 'blogname', 'Rise & Radiate' );
	update_option( 'blogdescription', 'Supporting families to grow with confidence, connection, and purpose.' );
	update_option( 'rar_redesign_page_ids', $pages, false );
	update_option( 'rar_redesign_version', RAR_RESCUE_VERSION, false );

	return $pages;
}

/**
 * Insert or update one editable WordPress page.
 *
 * @param string $slug        Page slug.
 * @param string $title       Page title.
 * @param string $content     Page content.
 * @param int    $preferred_id Optional existing page ID.
 * @return int
 */
function rar_redesign_upsert_page( $slug, $title, $content, $preferred_id = 0 ) {
	$page = $preferred_id ? get_post( $preferred_id ) : null;

	if ( ! $page || 'page' !== $page->post_type ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
	}

	if ( ! $page ) {
		$legacy = array(
			'home'    => 'web-agency-gb-home',
			'about'   => 'web-agency-gb-about-us',
			'contact' => 'web-agency-gb-contact-us',
		);

		if ( isset( $legacy[ $slug ] ) ) {
			$page = get_page_by_path( $legacy[ $slug ], OBJECT, 'page' );
		}
	}

	$postarr = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => wp_slash( $content ),
	);

	if ( $page ) {
		if ( function_exists( 'rar_rescue_backup_page' ) ) {
			rar_rescue_backup_page( $page->ID );
		}
		wp_save_post_revision( $page->ID );
		$postarr['ID'] = (int) $page->ID;
		wp_update_post( $postarr );
		return (int) $page->ID;
	}

	return (int) wp_insert_post( $postarr );
}

/**
 * Use the plugin-owned site shell for the rebuilt page set.
 *
 * @param string $template Theme template path.
 * @return string
 */
function rar_redesign_template( $template ) {
	if ( is_admin() || ! is_singular( 'page' ) ) {
		return $template;
	}

	$page_ids = array_map( 'intval', (array) get_option( 'rar_redesign_page_ids', array() ) );
	if ( ! in_array( get_queried_object_id(), $page_ids, true ) ) {
		return $template;
	}

	return RAR_RESCUE_DIR . 'templates/site.php';
}
add_filter( 'template_include', 'rar_redesign_template', 99 );

/**
 * Add the redesign script after the shared stylesheet.
 */
function rar_redesign_assets() {
	if ( ! rar_redesign_is_site_page() ) {
		return;
	}

	wp_enqueue_script(
		'rar-redesign',
		plugins_url( 'assets/js/site.js', RAR_RESCUE_FILE ),
		array(),
		RAR_RESCUE_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'rar_redesign_assets', 100 );

/**
 * Determine whether the current request belongs to the rebuilt site.
 *
 * @return bool
 */
function rar_redesign_is_site_page() {
	if ( ! is_singular( 'page' ) ) {
		return false;
	}

	$page_ids = array_map( 'intval', (array) get_option( 'rar_redesign_page_ids', array() ) );
	return in_array( get_queried_object_id(), $page_ids, true );
}

/**
 * Provide navigation data to the plugin template.
 *
 * @return array<string, string>
 */
function rar_redesign_navigation() {
	return array(
		'Home'           => home_url( '/' ),
		'About'          => home_url( '/about/' ),
		'Parent Support' => home_url( '/parent-support/' ),
		'Teen Coaching'  => home_url( '/teen-coaching/' ),
		'Adults'         => home_url( '/adults/' ),
		'Organisations'  => home_url( '/organisations-employers/' ),
		'Contact'        => home_url( '/contact/' ),
	);
}

/**
 * Render the contact form from the existing Contact page labels and wording.
 *
 * @return string
 */
function rar_redesign_contact_form() {
	$sent = isset( $_GET['sent'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['sent'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	ob_start();
	?>
	<form class="rar-contact-form<?php echo $sent ? ' is-sent' : ''; ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="rar_contact_submit">
		<?php wp_nonce_field( 'rar_contact_submit', 'rar_contact_nonce' ); ?>
		<div class="rar-form-field">
			<label for="rar-name">Name <span aria-hidden="true">*</span></label>
			<input id="rar-name" name="name" type="text" autocomplete="name" required>
		</div>
		<div class="rar-form-field">
			<label for="rar-email">Email <span aria-hidden="true">*</span></label>
			<input id="rar-email" name="email" type="email" autocomplete="email" required>
		</div>
		<div class="rar-form-field rar-form-field-wide">
			<label for="rar-message">Message</label>
			<textarea id="rar-message" name="message" rows="7" required></textarea>
		</div>
		<div class="rar-form-field rar-form-field-wide rar-consent">
			<label><input name="consent" type="checkbox" value="1" required> <span>You agree to receive email communication from us by submitting this form and understand that your contact information will be stored with us.</span></label>
		</div>
		<div class="rar-form-trap" aria-hidden="true">
			<label for="rar-website">Website</label>
			<input id="rar-website" name="website" type="text" tabindex="-1" autocomplete="off">
		</div>
		<button class="rar-button rar-button-dark" type="submit">Submit</button>
	</form>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'rar_contact_form', 'rar_redesign_contact_form' );

/**
 * Deliver the contact form through the site's normal WordPress mail transport.
 */
function rar_redesign_contact_submit() {
	if (
		! isset( $_POST['rar_contact_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rar_contact_nonce'] ) ), 'rar_contact_submit' )
	) {
		wp_safe_redirect( home_url( '/contact/' ) );
		exit;
	}

	if ( ! empty( $_POST['website'] ) ) {
		wp_safe_redirect( home_url( '/contact/' ) );
		exit;
	}

	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$consent = isset( $_POST['consent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['consent'] ) );

	if ( $name && is_email( $email ) && $message && $consent ) {
		$body = "Name: {$name}\nEmail: {$email}\n\nMessage:\n{$message}";
		wp_mail(
			'hello@riseandradiate.net',
			'Rise & Radiate',
			$body,
			array( 'Reply-To: ' . $name . ' <' . $email . '>' )
		);
		wp_safe_redirect( add_query_arg( 'sent', '1', home_url( '/contact/' ) ) );
		exit;
	}

	wp_safe_redirect( home_url( '/contact/' ) );
	exit;
}
add_action( 'admin_post_nopriv_rar_contact_submit', 'rar_redesign_contact_submit' );
add_action( 'admin_post_rar_contact_submit', 'rar_redesign_contact_submit' );

/**
 * Homepage content using only wording already published on Rise & Radiate.
 *
 * @return string
 */
function rar_redesign_home_content() {
	return <<<'HTML'
<div class="rar-page rar-home-page">
<section class="rar-hero rar-home-hero">
<div class="rar-hero-inner">
<h1>Rise and Radiate</h1>
<p class="rar-hero-lead">Supporting families to grow with confidence, connection, and purpose.</p>
<p>Rooted in a belief in the inherent dignity, potential, and growth of every person.</p>
<a class="rar-button rar-button-light" href="/parent-support/">Parent Support</a>
</div>
</section>

<section class="rar-section rar-services" aria-label="Support">
<a class="rar-service" href="/parent-support/">
<h2>Parents</h2>
<p>Support for parents who want to lead with calm confidence, connection, and clarity — without harshness, guilt, or power struggles.</p>
<span class="rar-text-link">Parent Support</span>
</a>
<a class="rar-service" href="/teen-coaching/">
<h2>Teens</h2>
<p>Strengths-based coaching to help teens build confidence, emotional resilience, and a strong sense of identity and purpose.</p>
<span class="rar-text-link">Teen Coaching</span>
</a>
<a class="rar-service" href="/adults/">
<h2>Adults</h2>
<p>Life coaching for adults and fathers seeking balance, emotional strength, and values-aligned living in work, family, and relationships.</p>
<span class="rar-text-link">Life Coaching</span>
</a>
<a class="rar-service" href="/organisations-employers/">
<h2>Organisations</h2>
<p>Workshops and coaching to support working parents, wellbeing, and people-centred leadership within organisations.</p>
<span class="rar-text-link">Workplace Support</span>
</a>
</section>

<section class="rar-section rar-approach">
<div class="rar-approach-image" role="img" aria-label=""></div>
<div class="rar-prose rar-prose-large">
<h2>Our Approach</h2>
<p>At Rise & Radiate, we believe that strong families are built through connection, guidance, and shared values — not perfection or pressure. Every person has innate worth, capacity, and the ability to contribute meaningfully to family and community life.</p>
<p>Our work is informed by education, coaching, and <strong>timeless human principles shared across cultures</strong>. We support parents, teens, and adults to grow in character, emotional strength, and purpose — both individually and together.</p>
<a class="rar-button rar-button-dark" href="/about/">Learn More</a>
</div>
</section>

<section class="rar-section rar-testimonials">
<blockquote>“Fantastic parenting course! Definitely a big commitment in a busy family schedule, but such a truly worthwhile investment – it has really helped our home run more harmoniously”</blockquote>
<blockquote>“It was really useful and the fact that there was no pressure to be a “perfect” parent was so refreshing. I was able to apply my learning from week 1 – thank you!”</blockquote>
<blockquote>“Great facilitation. I loved the safe space for vulnerable conversations. The insights I gained have been enlightening and invaluable”</blockquote>
</section>
</div>
HTML;
}

/**
 * About page content.
 *
 * @return string
 */
function rar_redesign_about_content() {
	return <<<'HTML'
<div class="rar-page rar-about-page">
<section class="rar-page-hero"><div class="rar-page-hero-inner"><h1>About Rise and Radiate</h1><p>Parenting is one of the most meaningful — and often most challenging — journeys we experience.</p><p>If you’ve found yourself here, you might be feeling overwhelmed, stuck in patterns of conflict, or simply wanting a calmer, more connected relationship with your child.</p><p>You’re not alone.</p></div></section>
<section class="rar-section rar-content-grid">
<div class="rar-section-title"><h2>My background</h2></div>
<div class="rar-prose"><p>My path into this work has grown through years of supporting children, young people, and families in different settings.</p><p>I have a background in education, youth work, and community development, with a focus on:</p><ul><li>identity and empowerment</li><li>building strong relationships</li><li>creating environments where people can thrive</li></ul><p>Alongside this, I am a certified Positive Discipline parent educator, supporting families with practical tools that can be applied in everyday life.</p><p>I’m also a parent myself — so I understand both the theory <em>and</em> the reality of what it means to raise children.</p></div>
</section>
<section class="rar-section rar-content-grid rar-ink-section">
<div class="rar-section-title"><h2>My Approach</h2></div>
<div class="rar-prose"><p>I support parents to move away from constant power struggles, shouting, or uncertainty — and toward relationships built on connection, mutual respect, and trust.</p><p>My work is grounded in a Positive Discipline approach, which means:</p><ul><li>supporting children to become capable and confident</li><li>setting kind and firm boundaries</li><li>nurturing cooperation rather than control</li></ul><p>This isn’t about “perfect parenting.”<br>It’s about building something sustainable, respectful, and real in your family life.</p></div>
</section>
<section class="rar-section rar-content-grid">
<div class="rar-section-title"><h2>What it’s like to work together</h2></div>
<div class="rar-prose"><p>In our sessions, you’ll find a space that is:</p><ul><li>supportive and non-judgemental</li><li>practical and grounded</li><li>focused on real-life situations you’re facing</li></ul><p>We’ll look at what’s happening in your family, understand what’s underneath behaviours, and explore tools you can use straight away.</p><p>Small shifts can lead to meaningful change — in how your child responds, and how you feel as a parent.</p></div>
</section>
<section class="rar-section rar-content-grid rar-sand-section">
<div class="rar-section-title"><h2>You might be in the right place if…</h2></div>
<div class="rar-prose"><ul><li>You want a stronger, more connected relationship with your child</li><li>You’re tired of repeating yourself and not being heard</li><li>You want to handle challenging behaviour more calmly</li><li>You’re looking for alternatives to punishment or shouting</li></ul><a class="rar-button rar-button-dark" href="/contact/">LET’S TALK</a></div>
</section>
</div>
HTML;
}

/**
 * Parent Support page content.
 *
 * @return string
 */
function rar_redesign_parent_content() {
	return <<<'HTML'
<div class="rar-page rar-parent-page">
<section class="rar-page-hero"><div class="rar-page-hero-inner"><h1>Parent Support</h1><p>Calm, practical guidance for parents who want to lead with confidence, connection, and clarity</p><p>Support rooted in respect, dignity, and the belief that families grow together.</p></div></section>
<section class="rar-section rar-content-grid">
<div class="rar-section-title"><h2>Who is this for?</h2></div>
<div class="rar-prose"><p>This support is for parents who care deeply about their children and want to parent with intention — even when things feel challenging or uncertain.</p><p>You might be here because you are:</p><ul><li>experiencing power struggles, big emotions, or repeated conflicts</li><li>unsure how to respond without being harsh or permissive</li><li>feeling stretched, tired, or disconnected</li><li>wanting tools that feel respectful and realistic</li><li>seeking a values-led approach to family life</li></ul><p>You don’t need to be at a crisis point to seek support. Many parents come simply because they want to grow.</p></div>
</section>
<section class="rar-section rar-content-grid rar-ink-section">
<div class="rar-section-title"><h2>Our Approach</h2></div>
<div class="rar-prose"><p>At Rise & Radiate, our parent support is grounded in <strong>Positive Discipline</strong>, a respectful, evidence-informed approach to parenting that supports both connection and clear boundaries.</p><p>As a <strong>Certified Positive Discipline Parent Educator</strong>, we work with parents to:</p><ul><li>understand the needs behind behaviour</li><li>build cooperation through connection rather than control</li><li>develop practical tools that support responsibility, respect, and belonging</li></ul><p>Alongside Positive Discipline, our work is informed by child development, coaching, and reflective practice — supporting parents to grow <em>with</em> their children over time.</p></div>
</section>
<section class="rar-section rar-stack-section">
<div class="rar-section-heading"><h2>Ways we can work together</h2><p>All parent education and coaching is grounded in Positive Discipline principles and adapted to the needs of each family.</p></div>
<div class="rar-three-up"><article><h3>Parent Education Courses</h3><p>Practical, supportive learning spaces where parents build understanding, confidence, and skills alongside others.</p></article><article><h3>1:1 Parent Coaching</h3><p>Personalised support tailored to your family’s needs, challenges, and values.</p></article><article><h3>Workshops & Group Sessions</h3><p>For schools, communities, or organisations supporting parents collectively.</p></article></div>
</section>
<section class="rar-section rar-content-grid rar-sand-section">
<div class="rar-section-title"><h2>What parents can expect</h2></div>
<div class="rar-prose"><ul><li>greater calm and clarity in challenging moments</li><li>stronger connection and mutual respect at home</li><li>practical tools they can return to again and again</li><li>confidence in their role as a parent and guide</li><li>a sense of shared purpose within the family</li></ul><p>Change happens gradually — we focus on sustainable growth, not quick fixes.</p></div>
</section>
<section class="rar-section rar-closing"><h2>Ready to explore support?</h2><p>If you’d like to talk through what support might look like for your family, you’re warmly invited to get in touch.</p><a class="rar-button rar-button-light" href="/contact/">Enquire About Parent Support</a></section>
</div>
HTML;
}

/**
 * Teen Coaching page content, limited to copy already present on the site.
 *
 * @return string
 */
function rar_redesign_teen_content() {
	return <<<'HTML'
<div class="rar-page rar-teen-page">
<section class="rar-page-hero rar-service-hero"><div class="rar-page-hero-inner"><h1>Teen Coaching</h1><p>Strengths-based coaching to help teens build confidence, emotional resilience, and a strong sense of identity and purpose.</p></div></section>
<section class="rar-section rar-contact-prompt"><div><h2>Get in touch</h2><p>If you’re interested in support, have a question, or would like to explore whether Rise & Radiate is a good fit, you’re warmly invited to get in touch.</p><p>You don’t need to know exactly what you’re looking for — a conversation is often the best place to begin.</p></div><a class="rar-button rar-button-dark" href="/contact/">Contact</a></section>
</div>
HTML;
}

/**
 * Adults page content, limited to copy already present on the site.
 *
 * @return string
 */
function rar_redesign_adults_content() {
	return <<<'HTML'
<div class="rar-page rar-adults-page">
<section class="rar-page-hero rar-service-hero"><div class="rar-page-hero-inner"><h1>Adults</h1><p>Life coaching for adults and fathers seeking balance, emotional strength, and values-aligned living in work, family, and relationships.</p></div></section>
<section class="rar-section rar-contact-prompt"><div><h2>Get in touch</h2><p>If you’re interested in support, have a question, or would like to explore whether Rise & Radiate is a good fit, you’re warmly invited to get in touch.</p><p>You don’t need to know exactly what you’re looking for — a conversation is often the best place to begin.</p></div><a class="rar-button rar-button-dark" href="/contact/">Contact</a></section>
</div>
HTML;
}

/**
 * Organisations page content.
 *
 * @return string
 */
function rar_redesign_organisations_content() {
	return <<<'HTML'
<div class="rar-page rar-organisations-page">
<section class="rar-page-hero"><div class="rar-page-hero-inner"><h1>Organisations & Employers</h1><p>Supporting organisations to strengthen wellbeing, family life, and people-centred leadership.</p><p>Practical, values-led programmes for working parents, teams, and leaders.</p></div></section>
<section class="rar-section rar-content-grid">
<div class="rar-section-title"><h2>Who is this for?</h2></div>
<div class="rar-prose"><p>This work is for organisations and employers who recognise that people thrive — and contribute more fully — when their wellbeing, family life, and sense of purpose are supported.</p><p>We work with:</p><ul><li>employers supporting working parents</li><li>organisations seeking to strengthen staff wellbeing</li><li>schools, community groups, and NGOs</li><li>values-led teams navigating growth or change</li></ul><p>Whether you are responding to specific challenges or taking a proactive approach, this support helps create healthier, more resilient workplaces.</p></div>
</section>
<section class="rar-section rar-content-grid rar-ink-section">
<div class="rar-section-title"><h2>Our approach</h2></div>
<div class="rar-prose"><p>At Rise & Radiate, we work from the understanding that wellbeing is relational — shaped by family life, workplace culture, and shared values.</p><p>Our approach is:</p><ul><li>practical and grounded</li><li>informed by education, coaching, and community development</li><li>respectful of diverse family and cultural contexts</li><li>rooted in <strong>timeless human values such as dignity, responsibility, and care for others</strong></li></ul><p>Rather than one-off initiatives, we support organisations to build <strong>sustainable practices</strong> that benefit individuals, families, and the wider community.</p></div>
</section>
<section class="rar-section rar-stack-section">
<div class="rar-section-heading"><h2>Areas of support</h2></div>
<div class="rar-two-up"><article><h3>Working Parent Support</h3><p>Workshops and programmes that support parents to navigate family life alongside professional responsibilities, strengthening confidence, communication, and balance.</p></article><article><h3>Wellbeing & Mental Fitness</h3><p>Coaching-informed sessions that build emotional resilience, self-awareness, and constructive responses to stress and pressure.</p></article><article><h3>Leadership & Team Development</h3><p>Support for leaders and teams seeking to foster trust, responsibility, and people-centred cultures.</p></article><article><h3>Community & Education Partnerships</h3><p>Collaborative initiatives with schools, communities, or organisations working with families and young people.</p></article></div>
</section>
<section class="rar-section rar-content-grid rar-sand-section">
<div class="rar-section-title"><h2>How we work</h2></div>
<div class="rar-prose"><p>Support is offered through:</p><ul><li>workshops and short courses</li><li>group or team-based programmes</li><li>tailored coaching or consultancy</li><li>collaborative programme design</li></ul><p>All work is shaped in conversation with the organisation, ensuring relevance, clarity, and alignment with values and context.</p></div>
</section>
<section class="rar-section rar-content-grid">
<div class="rar-section-title"><h2>What organisations can expect</h2></div>
<div class="rar-prose"><ul><li>increased staff confidence and wellbeing</li><li>improved communication and emotional awareness</li><li>stronger engagement and sense of belonging</li><li>greater understanding of the needs of working parents</li><li>a more thoughtful, people-centred organisational culture</li></ul><p>Change is approached as a process — supporting long-term benefit rather than quick fixes.</p></div>
</section>
<section class="rar-section rar-closing"><h2>Interested in working together?</h2><p>If you’d like to explore how Rise & Radiate could support your organisation, we welcome a conversation to understand your context and needs.</p><a class="rar-button rar-button-light" href="/contact/">Enquire about Organisational Support</a></section>
</div>
HTML;
}

/**
 * Contact page content.
 *
 * @return string
 */
function rar_redesign_contact_content() {
	return <<<'HTML'
<div class="rar-page rar-contact-page">
<section class="rar-page-hero"><div class="rar-page-hero-inner"><h1>Get in touch</h1><p>If you’re interested in support, have a question, or would like to explore whether Rise & Radiate is a good fit, you’re warmly invited to get in touch.</p><p>You don’t need to know exactly what you’re looking for — a conversation is often the best place to begin.</p></div></section>
<section class="rar-section rar-contact-layout">
<div class="rar-contact-form-wrap">[rar_contact_form]</div>
<aside class="rar-contact-details"><p>Alternatively, you’re welcome to contact us directly by email, phone or WhatsApp</p><h2>Call us / WhatsApp</h2><p><a href="https://wa.me/35677535096">(+356) 77 53 50 96</a></p><h2>Email</h2><p><a href="mailto:hello@riseandradiate.net">hello@riseandradiate.net</a></p></aside>
</section>
</div>
HTML;
}
