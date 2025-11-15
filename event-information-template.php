<?php
/**
 * Event Information Template and Shortcode
 *
 * Provides a frontend display for a single event including: image, interested count,
 * organizers, date, location, description and action buttons.
 *
 * Usage: [alumnus_event_info id="123"]
 * If no id attribute is passed the current post ID is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Fetch event meta with sensible defaults.
 *
 * @param int $event_id Event post ID.
 * @return array
 */
function alumnus_get_event_info( $event_id ) {
	$fields = array(
		'image_id'        => 0,
		'interested_count'=> 0,
		'organizers'      => array(), // array of taxonomy term IDs or plain strings.
		'date'            => '',
		'location'        => '',
		'description'     => '',
	);

	// Core fields from post & meta.
	$thumbnail_id = get_post_thumbnail_id( $event_id );
	if ( $thumbnail_id ) {
		$fields['image_id'] = $thumbnail_id;
	}

	$fields['interested_count'] = (int) get_post_meta( $event_id, 'interested_count', true );
	$fields['date']             = get_post_meta( $event_id, 'event_date', true );
	$fields['location']         = get_post_meta( $event_id, 'event_location', true );
	$fields['description']      = get_post_meta( $event_id, 'event_description', true );

	// Organizers can be stored either as a taxonomy (e.g., organizer) or post meta (comma separated).
	$organizer_terms = get_the_terms( $event_id, 'organizer' );
	if ( ! is_wp_error( $organizer_terms ) && ! empty( $organizer_terms ) ) {
		$fields['organizers'] = wp_list_pluck( $organizer_terms, 'name' );
	} else {
		$raw_organizers = get_post_meta( $event_id, 'event_organizers', true );
		if ( $raw_organizers ) {
			$fields['organizers'] = array_map( 'trim', explode( ',', $raw_organizers ) );
		}
	}

	return $fields;
}

/**
 * Render badge helper.
 */
function alumnus_event_badge( $text, $color = 'default' ) {
	// Return a badge element which styling is handled in CSS by the color class.
	$allowed = array( 'green', 'orange', 'teal', 'default' );
	$color   = in_array( $color, $allowed, true ) ? $color : 'default';
	return '<span class="alumnus-badge alumnus-badge-' . esc_attr( $color ) . '">' . esc_html( $text ) . '</span>';
}

/**
 * Shortcode callback.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function alumnus_event_info_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'id' => get_the_ID(),
	), $atts, 'alumnus_event_info' );

	$event_id = absint( $atts['id'] );
	if ( ! $event_id || 'publish' !== get_post_status( $event_id ) ) {
		return '';
	}

	$event = get_post( $event_id );
	$data  = alumnus_get_event_info( $event_id );

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

	// Ensure stylesheet is loaded.
	wp_enqueue_style( 'alumnus-event-info', plugin_dir_url( __FILE__ ) . 'assets/css/event-information.css', array( 'wordpress-plugin-template-colors' ), '1.0.0' );

	$image_html = '';
	if ( $data['image_id'] ) {
		$image_html = wp_get_attachment_image( $data['image_id'], 'large', false, array( 'class' => 'alumnus-event-image' ) );
	} else {
		$image_html = '<div class="alumnus-event-image alumnus-event-image--placeholder">No Image</div>';
	}

	$organizers_html = '';
	if ( ! empty( $data['organizers'] ) ) {
		$badges = array();
		foreach ( $data['organizers'] as $org ) {
			$badges[] = alumnus_event_badge( $org, 'green' );
		}
		$organizers_html = implode( ' ', $badges );
	}

	$date_badge     = $data['date'] ? alumnus_event_badge( date_i18n( 'F j, Y', strtotime( $data['date'] ) ), 'orange' ) : '';
	$location_badge = $data['location'] ? alumnus_event_badge( $data['location'], 'teal' ) : '';

	$interested = intval( $data['interested_count'] );

	ob_start();
	?>
	<div class="alumnus-event-wrapper">
		<div class="alumnus-event-left">
			<div class="alumnus-event-image-wrapper">
				<?php echo $image_html; //phpcs:ignore ?>
			</div>
			<a href="#" class="alumnus-event-join">Join Event <span class="alumnus-icon">→</span></a>
		</div>
		<div class="alumnus-event-right">
			<h2 class="alumnus-event-title"><?php echo esc_html( get_the_title( $event ) ); ?></h2>
			<p class="alumnus-event-interested"><?php echo esc_html( $interested ); ?> people interested</p>
			<div class="alumnus-event-meta">
				<div class="alumnus-event-meta-label">Organizers:</div>
				<div class="alumnus-event-meta-value organizers"><?php echo $organizers_html; //phpcs:ignore ?></div>
				<div class="alumnus-event-meta-label">When:</div>
				<div class="alumnus-event-meta-value when"><?php echo $date_badge; //phpcs:ignore ?></div>
				<div class="alumnus-event-meta-label">Where:</div>
				<div class="alumnus-event-meta-value where"><?php echo $location_badge; //phpcs:ignore ?></div>
			</div>
			<div class="alumnus-event-description">
				<h3 class="alumnus-event-description-heading">About The Event</h3>
				<div class="alumnus-event-description-text">
					<?php echo wpautop( esc_html( $data['description'] ? $data['description'] : $event->post_excerpt ) ); ?>
				</div>
				<div class="alumnus-event-readmore-wrap">
					<a href="<?php echo esc_url( get_permalink( $event ) ); ?>" class="alumnus-event-readmore">Read More <span class="alumnus-icon">↓</span></a>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'alumnus_event_info', 'alumnus_event_info_shortcode' );
