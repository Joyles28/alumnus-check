<?php
/**
 * Event List View Shortcode
 *
 * Displays a responsive list (cards) of published event posts with key details and a left sidebar profile card (design only):
 *  - Featured image (or placeholder)
 *  - Title (linked)
 *  - Date & Location badges
 *  - Excerpt / short description
 *  - Interested count (if meta present)
 *  - Join / Read More actions (non functional placeholders for now)
 *
 * Usage: [alumnus_event_list posts_per_page="6" order="ASC" orderby="event_date"]
 * Attributes:
 *  - posts_per_page: Number of events to show (default 6, use -1 for all)
 *  - order: ASC | DESC (default DESC)
 *  - orderby: Any valid WP_Query orderby value (default date). For custom meta event_date use 'meta_value' with meta_key attr.
 *  - meta_key: Meta key to use when ordering by meta (default empty)
 *  - show_past: true|false (default true) if false will try to filter out past events based on 'event_date' meta (Y-m-d)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'alumnus_get_event_info' ) ) {
	// Safety: if single template helper not loaded for some reason.
	require_once 'event-information-template.php';
}

/**
 * Build WP_Query args from shortcode atts.
 */
function alumnus_event_list_build_query_args( $atts ) {
	$args = array(
		'post_type'      => 'post', // Adjust if a dedicated event CPT slug exists.
		'post_status'    => 'publish',
		'posts_per_page' => intval( $atts['posts_per_page'] ),
		'orderby'        => sanitize_text_field( $atts['orderby'] ),
		'order'          => ( 'ASC' === strtoupper( $atts['order'] ) ) ? 'ASC' : 'DESC',
	);

	if ( ! empty( $atts['meta_key'] ) ) {
		$args['meta_key'] = sanitize_key( $atts['meta_key'] );
	}

	// Basic future filtering when using event_date meta (expects Y-m-d or similar parsable date).
	if ( 'false' === strtolower( $atts['show_past'] ) ) {
		$args['meta_query'] = array(
			array(
				'key'     => 'event_date',
				'value'   => current_time( 'Y-m-d' ),
				'compare' => '>=',
				'type'    => 'DATE',
			),
		);
	}
	return $args;
}

/**
 * Render a single event card.
 */
function alumnus_event_list_render_card( $post_id ) {
	$data        = alumnus_get_event_info( $post_id );
	$title       = get_the_title( $post_id );
	$permalink   = get_permalink( $post_id );
	$interested  = intval( $data['interested_count'] );
	$date_badge  = $data['date'] ? alumnus_event_badge( date_i18n( 'M j, Y', strtotime( $data['date'] ) ), 'orange' ) : '';
	$loc_badge   = $data['location'] ? alumnus_event_badge( $data['location'], 'teal' ) : '';
	$excerpt_src = $data['description'] ? $data['description'] : get_the_excerpt( $post_id );
	$excerpt     = wp_trim_words( wp_strip_all_tags( $excerpt_src ), 25, '…' );

	$image_html = '';
	if ( $data['image_id'] ) {
		$image_html = wp_get_attachment_image( $data['image_id'], 'medium', false, array( 'class' => 'alumnus-event-card-image' ) );
	} else {
		$image_html = '<div class="alumnus-event-card-image alumnus-event-card-image--placeholder">No Image</div>';
	}

	$organizers_html = '';
	if ( ! empty( $data['organizers'] ) ) {
		$badges = array();
		foreach ( $data['organizers'] as $org ) {
			$badges[] = alumnus_event_badge( $org, 'green' );
		}
		$organizers_html = '<div class="alumnus-event-card-orgs">' . implode( ' ', $badges ) . '</div>';
	}

	ob_start();
	?>
	<article class="alumnus-event-card">
		<div class="alumnus-event-card-media">
			<a href="<?php echo esc_url( $permalink ); ?>" class="alumnus-event-card-media-link" aria-label="<?php echo esc_attr( $title ); ?>">
				<?php echo $image_html; // phpcs:ignore ?>
			</a>
		</div>
		<div class="alumnus-event-card-body">
			<header class="alumnus-event-card-header">
				<h3 class="alumnus-event-card-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h3>
			</header>
			<div class="alumnus-event-card-meta">
				<?php echo $date_badge; // phpcs:ignore ?>
				<?php echo $loc_badge; // phpcs:ignore ?>
			</div>
			<?php echo $organizers_html; // phpcs:ignore ?>
			<p class="alumnus-event-card-excerpt"><?php echo esc_html( $excerpt ); ?></p>
			<div class="alumnus-event-card-footer">
				<span class="alumnus-event-card-interested" title="People interested">👥 <?php echo esc_html( $interested ); ?></span>
				<div class="alumnus-event-card-actions">
					<a class="alumnus-btn alumnus-btn-secondary" href="<?php echo esc_url( $permalink ); ?>">Details</a>
					<a class="alumnus-btn alumnus-btn-primary" href="#" aria-disabled="true">Join</a>
				</div>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode callback.
 */
function alumnus_event_list_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'posts_per_page' => 6,
		'order'          => 'DESC',
		'orderby'        => 'date',
		'meta_key'       => '',
		'show_past'      => 'true',
	), $atts, 'alumnus_event_list' );

	// Ensure color-variables.css is loaded
	if ( ! wp_style_is( 'wordpress-plugin-template-colors', 'registered' ) ) {
		wp_register_style(
			'wordpress-plugin-template-colors',
			plugin_dir_url( __FILE__ ) . 'assets/css/color-variables.css',
			array(),
			'1.0.0'
		);
	}
	wp_enqueue_style( 'wordpress-plugin-template-colors' );

	// Enqueue CSS.
	wp_enqueue_style( 'alumnus-event-list', plugin_dir_url( __FILE__ ) . 'assets/css/event-list.css', array( 'wordpress-plugin-template-colors' ), '1.0.0' );
	// Reuse badges styling from event info CSS (ensures consistent badge styles if that file not yet loaded elsewhere).
	wp_enqueue_style( 'alumnus-event-info', plugin_dir_url( __FILE__ ) . 'assets/css/event-information.css', array( 'wordpress-plugin-template-colors' ), '1.0.0' );

	$query_args = alumnus_event_list_build_query_args( $atts );
	$events     = new WP_Query( $query_args );

	$no_events_message = '';
	if ( ! $events->have_posts() ) {
		$no_events_message = '<div class="alumnus-event-list-wrapper"><p class="alumnus-event-list-empty">No events found.</p></div>';
	}

	// Build profile card (design only) using existing community feed styles for consistency.
	$current_user = wp_get_current_user();
	$has_user     = ( $current_user && $current_user->ID );

	$avatar = $has_user ? get_avatar( $current_user->ID, 72 ) : '<img src="https://via.placeholder.com/72" alt="Guest" />';
	$name   = $has_user ? esc_html( $current_user->display_name ) : 'Guest User';
	$role_badge = '';
	if ( $has_user ) {
		$user_roles = (array) $current_user->roles;
		if ( ! empty( $user_roles ) ) {
			$role_badge = '<span class="apc-role">' . esc_html( ucfirst( str_replace( array( '-', '_' ), ' ', $user_roles[0] ) ) ) . '</span>';
		}
	} else {
		$role_badge = '<span class="apc-role">Visitor</span>';
	}

	ob_start();
	?>
	<div class="alumnus-event-list-layout">
		<aside class="alumnus-event-list-sidebar">
			<div class="alumnus-profile-card">
				<div class="apc-header">
					<div class="apc-avatar"><?php echo $avatar; // phpcs:ignore ?></div>
					<div class="apc-meta">
						<h3 class="apc-name"><?php echo $name; // phpcs:ignore ?></h3>
						<?php echo $role_badge; // phpcs:ignore ?>
						<p class="apc-since">Member since – UI Placeholder</p>
					</div>
				</div>
				<ul class="apc-stats">
					<li><strong>Events Joined:</strong> 0</li>
					<li><strong>Interested:</strong> 0</li>
				</ul>
				<div class="apc-section">
					<h4>Quick Actions</h4>
					<p class="apc-placeholder">(Design only)</p>
				</div>
			</div>
		</aside>
		<main class="alumnus-event-list-main">
		<?php if ( $no_events_message ) : ?>
			<?php echo $no_events_message; // phpcs:ignore ?>
		<?php else : ?>
		<div class="alumnus-event-list-wrapper">
			<div class="alumnus-event-list-grid">
			<?php
			while ( $events->have_posts() ) :
				$events->the_post();
				echo alumnus_event_list_render_card( get_the_ID() ); // phpcs:ignore
			endwhile;
			wp_reset_postdata();
			?>
			</div>
		</div>
		<?php endif; ?>
		</main>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'alumnus_event_list', 'alumnus_event_list_shortcode' );
