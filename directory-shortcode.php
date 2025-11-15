<?php
/**
 * Alumni Directory Shortcode
 * Usage: [alumni_directory]
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Enqueue directory styles and scripts
 */
function alumnus_enqueue_directory_styles() {
	// Cache-bust styles/scripts by using file modification time as the version
	$css_rel_path = 'assets/css/directory.css';
	$js_rel_path  = 'assets/js/directory-filters.js';
	$css_path     = plugin_dir_path( __FILE__ ) . $css_rel_path;
	$js_path      = plugin_dir_path( __FILE__ ) . $js_rel_path;
	$css_ver      = file_exists( $css_path ) ? filemtime( $css_path ) : '1.1.0';
	$js_ver       = file_exists( $js_path ) ? filemtime( $js_path ) : '1.1.0';

	// Ensure color-variables.css is loaded
	if ( ! wp_style_is( 'wordpress-plugin-template-colors', 'registered' ) ) {
		wp_register_style(
			'wordpress-plugin-template-colors',
			plugin_dir_url( __FILE__ ) . 'assets/css/color-variables.css',
			array(),
			$css_ver
		);
	}
	wp_enqueue_style( 'wordpress-plugin-template-colors' );

	wp_enqueue_style(
		'alumnus-directory',
		plugin_dir_url( __FILE__ ) . $css_rel_path,
		array( 'wordpress-plugin-template-colors' ),
		$css_ver
	);

	wp_enqueue_script(
		'alumnus-directory-filters',
		plugin_dir_url( __FILE__ ) . $js_rel_path,
		array('jquery'),
		$js_ver,
		true
	);

	// Determine the profile page URL
	// Prefer resolving the page that contains [alumni_profile]; allow override via filter
	if ( function_exists('alumnus_resolve_profile_page_url') ) {
		$profile_page_url = alumnus_resolve_profile_page_url();
	} else {
		$profile_page_url = apply_filters('alumnus_profile_page_url', home_url('/'));
	}
	
	// Localize AJAX settings
	wp_localize_script('alumnus-directory-filters', 'AlumnusDirectory', array(
		'ajax_url' => admin_url('admin-ajax.php'),
		'nonce'    => wp_create_nonce('alumnus_directory'),
		'profile_url' => $profile_page_url, // URL for building profile links
		'i18n'     => array(
			'loading' => __('Loading alumni...', 'alumnus'),
			'search'  => __('Search', 'alumnus'),
			'noResults' => __('No alumni found matching your filters.', 'alumnus'),
		)
	));
}

/**
 * Render the alumni directory markup with search interface
 *
 * @return string
 */
function alumnus_render_directory_shortcode() {
	// Enqueue CSS/JS
	alumnus_enqueue_directory_styles();

	// Fetch filter data from DB
	global $wpdb;
	// Using actual tables from schema: course, alumni
	$courses = $wpdb->get_results( "SELECT course_id, course FROM course ORDER BY course ASC" );
	$years   = $wpdb->get_col( "SELECT DISTINCT year FROM alumni WHERE year IS NOT NULL ORDER BY year DESC" );

	ob_start();
	?>
	<div class="alumnus-directory-wrapper">
		<div class="alumnus-directory-hero">
			<div class="adh-icon-bg">
				<!-- Network connection icon overlay -->
				<?php for ( $i = 1; $i <= 6; $i++ ) : ?>
				<svg class="adh-icon adh-icon-<?php echo $i; ?>" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
					<circle cx="50" cy="50" r="42" fill="white" opacity="0.9"/>
					<circle cx="50" cy="38" r="18" fill="#04324d"/>
					<path d="M 20 80 Q 50 50 80 80" stroke="#04324d" stroke-width="4" fill="none"/>
				</svg>
				<?php endfor; ?>
			</div>
			
			<div class="adh-content">
				<h1 class="adh-title">Directory</h1>
				<p class="adh-tagline">Stay connected! Find your peers.</p>
				
				<div class="alumnus-search-box">
					<div class="asb-inner">

						<input 
							type="text" 
							class="asb-input" 
							placeholder="Search Alumni" 
							id="alumnus-directory-search"
						/>
						<button type="button" id="alumnus-directory-submit-btn" class="asb-button"><?php echo esc_html__('Search', 'alumnus'); ?></button>
					</div>
				</div>
			</div>
		</div>
		
		<!-- Alumni Grid -->
		<div class="alumnus-grid-container">
			<!-- Filters -->
			<div class="alumnus-filters">
				<div class="af-filter-group">
					<label for="filter-year" class="af-label">
						<svg class="af-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="4" width="18" height="18" rx="2" stroke="var(--alumnus-primary)" stroke-width="2" fill="none"/>
							<line x1="3" y1="9" x2="21" y2="9" stroke="var(--alumnus-primary)" stroke-width="2"/>
							<line x1="8" y1="2" x2="8" y2="6" stroke="var(--alumnus-primary)" stroke-width="2" stroke-linecap="round"/>
							<line x1="16" y1="2" x2="16" y2="6" stroke="var(--alumnus-primary)" stroke-width="2" stroke-linecap="round"/>
						</svg>
						Year
					</label>
					<select id="filter-year" class="af-select">
						<option value=""><?php echo esc_html__('All Years', 'alumnus'); ?></option>
						<?php if (!empty($years)) : foreach ($years as $year) : ?>
							<option value="<?php echo esc_attr((string) $year); ?>"><?php echo esc_html((string) $year); ?></option>
						<?php endforeach; endif; ?>
					</select>
				</div>
				
				<div class="af-filter-group">
					<label for="filter-course" class="af-label">
						<svg class="af-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M12 14l9-5-9-5-9 5 9 5z" stroke="var(--alumnus-primary)" stroke-width="2" stroke-linejoin="round" fill="none"/>
							<path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" stroke="var(--alumnus-primary)" stroke-width="2" stroke-linejoin="round" fill="none"/>
						</svg>
						Course
					</label>
					<select id="filter-course" class="af-select">
						<option value=""><?php echo esc_html__('All Courses', 'alumnus'); ?></option>
						<?php if (!empty($courses)) : foreach ($courses as $course) : ?>
							<option value="<?php echo esc_attr((string) $course->course_id); ?>"><?php echo esc_html($course->course); ?></option>
						<?php endforeach; endif; ?>
					</select>
				</div>
			</div>
			
			<div class="alumnus-grid" id="alumnus-grid">
				<div class="no-results-message" data-initial="1">
					<p><?php echo esc_html__('Adjust filters and press Search to see results.', 'alumnus'); ?></p>
				</div>
			</div>
			
			<!-- Pagination -->
			<div id="alumnus-pagination" class="alumnus-pagination"></div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'alumni_directory', 'alumnus_render_directory_shortcode' );

/**
 * Helper: Render a single alumni card as HTML
 */
function alumnus_render_alumni_card($row, $base_profile_url = '') {
	$full_name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
	$initials = '';
	if (!empty($row->firstname)) { $initials .= strtoupper(substr($row->firstname, 0, 1)); }
	if (!empty($row->lastname)) { $initials .= strtoupper(substr($row->lastname, 0, 1)); }
	if ($initials === '' && $full_name !== '') { $initials = strtoupper(substr($full_name, 0, 1)); }

	// Generate profile URL using helper function
	// If base_profile_url is provided (from AJAX), use it; otherwise resolve the profile page
	if (empty($base_profile_url)) {
		$base_profile_url = function_exists('alumnus_resolve_profile_page_url')
			? alumnus_resolve_profile_page_url()
			: get_permalink();
	}
	$profile_url = alumnus_get_profile_url($row->user_id, $base_profile_url);

	ob_start();
	?>
	<a href="<?php echo esc_url($profile_url); ?>" class="alumni-card" data-user-id="<?php echo esc_attr($row->user_id); ?>">
		<div class="ac-avatar"><span class="ac-initials"><?php echo esc_html($initials); ?></span></div>
		<div class="ac-content">
			<h3 class="ac-name"><?php echo esc_html($full_name ?: ($row->user_id ?? '')); ?></h3>
			<?php if (!empty($row->year)) : ?>
				<p class="ac-year"><?php echo esc_html(sprintf(__('Class of %d', 'alumnus'), (int)$row->year)); ?></p>
			<?php endif; ?>
			<?php if (!empty($row->course_name)) : ?>
				<p class="ac-degree"><?php echo esc_html($row->course_name); ?></p>
			<?php endif; ?>
		</div>
	</a>
	<?php
	return ob_get_clean();
}

/**
 * AJAX: Fetch alumni with filters
 */
function alumnus_directory_fetch_alumni() {
	check_ajax_referer('alumnus_directory', 'nonce');

	global $wpdb;

	$year      = isset($_POST['year']) ? intval($_POST['year']) : 0;
	$course_id = isset($_POST['course_id']) && $_POST['course_id'] !== '' ? intval($_POST['course_id']) : 0;
	$search    = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
	$profile_url = isset($_POST['profile_url']) ? esc_url_raw(wp_unslash($_POST['profile_url'])) : '';
	$page      = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;

	$per_page = 15;
	$offset = ($page - 1) * $per_page;

	$where = array();
	$params = array();

	if ($year > 0) {
		$where[] = "a.year = %d";
		$params[] = $year;
	}
	if ($course_id > 0) {
		$where[] = "a.course_id = %d";
		$params[] = $course_id;
	}

	$search_sql = '';
	if ($search !== '') {
		$like = '%' . $wpdb->esc_like($search) . '%';
		// Restrict search to names only (firstname, lastname, and full name)
		$search_sql = "(a.firstname LIKE %s OR a.lastname LIKE %s OR CONCAT_WS(' ', a.firstname, a.lastname) LIKE %s)";
		array_push($params, $like, $like, $like);
		$where[] = $search_sql;
	}

	$where_clause = '';
	if (!empty($where)) {
		$where_clause = 'WHERE ' . implode(' AND ', $where);
	}

	// Get total count first
	$count_sql = "SELECT COUNT(*) FROM alumni a LEFT JOIN course c ON a.course_id = c.course_id $where_clause";
	$total_count = !empty($params) ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql);
	$total_pages = ceil($total_count / $per_page);

	// Get paginated results
	$sql = "SELECT a.user_id, a.firstname, a.lastname, a.`year`, a.email, a.contact_info, c.course AS course_name
			FROM alumni a
			LEFT JOIN course c ON a.course_id = c.course_id
			$where_clause
			ORDER BY a.`year` DESC, a.lastname ASC, a.firstname ASC
			LIMIT %d OFFSET %d";

	$params[] = $per_page;
	$params[] = $offset;

	$rows = $wpdb->get_results($wpdb->prepare($sql, $params));

	if (empty($rows)) {
		wp_send_json_success(array(
			'html' => '<div class="no-results-message"><p>'. esc_html__('No alumni found matching your filters.', 'alumnus') .'</p></div>',
			'pagination' => array(
				'current_page' => 1,
				'total_pages' => 0,
				'total_count' => 0
			)
		));
	}

	$html = '';
	foreach ($rows as $row) {
		$html .= alumnus_render_alumni_card($row, $profile_url);
	}

	wp_send_json_success(array(
		'html' => $html,
		'pagination' => array(
			'current_page' => $page,
			'total_pages' => (int)$total_pages,
			'total_count' => (int)$total_count
		)
	));
}
add_action('wp_ajax_alumnus_fetch_alumni', 'alumnus_directory_fetch_alumni');
add_action('wp_ajax_nopriv_alumnus_fetch_alumni', 'alumnus_directory_fetch_alumni');

