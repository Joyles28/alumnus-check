<?php
/**
 * Alumni Profile Shortcode
 * Usage: [alumni_profile] or [alumni_profile user_id="123"]
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Prevent WordPress canonical redirects from stripping our alumni_id query param.
 * Some environments/plugins may trigger a redirect that drops unknown query vars,
 * causing the page to reload without alumni_id and then fall back to the logged user.
 */
if ( ! function_exists( 'alumnus_preserve_alumni_id_canonical' ) ) {
	function alumnus_preserve_alumni_id_canonical( $redirect_url, $requested_url ) {
		if ( isset( $_GET['alumni_id'] ) && $_GET['alumni_id'] !== '' ) {
			return false; // disable canonical redirect to preserve query param
		}
		return $redirect_url;
	}
	add_filter( 'redirect_canonical', 'alumnus_preserve_alumni_id_canonical', 10, 2 );
}

/**
 * Enqueue profile styles and scripts
 */
function alumnus_enqueue_profile_styles() {
	$css_rel_path = 'assets/css/profile.css';
	$css_path     = plugin_dir_path( __FILE__ ) . $css_rel_path;
	$css_ver      = file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0';

	$js_rel_path  = 'assets/js/profile.js';
	$js_path      = plugin_dir_path( __FILE__ ) . $js_rel_path;
	$js_ver       = file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0';

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
		'alumnus-profile',
		plugin_dir_url( __FILE__ ) . $css_rel_path,
		array( 'wordpress-plugin-template-colors' ),
		$css_ver
	);

	// Enqueue profile JavaScript
	wp_enqueue_script(
		'alumnus-profile',
		plugin_dir_url( __FILE__ ) . $js_rel_path,
		array(),
		$js_ver,
		true // Load in footer
	);

	// Localize script with translatable strings
	wp_localize_script(
		'alumnus-profile',
		'alumnusProfileStrings',
		array(
			'saving'             => __( 'Saving…', 'alumnus' ),
			'saveChanges'        => __( 'Save Changes', 'alumnus' ),
			'errorBio'           => __( 'Failed to update bio.', 'alumnus' ),
			'errorSkills'        => __( 'Failed to update skills.', 'alumnus' ),
			'networkErrorBio'    => __( 'Network error updating bio.', 'alumnus' ),
			'networkErrorSkills' => __( 'Network error updating skills.', 'alumnus' ),
			'savingSkills'       => __( 'Saving…', 'alumnus' ),
			'savingExperience'   => __( 'Saving Experience…', 'alumnus' ),
			'errorExperience'    => __( 'Failed to add experience.', 'alumnus' ),
			'networkErrorExperience' => __( 'Network error adding experience.', 'alumnus' ),
			'search_skills_nonce' => wp_create_nonce('alumnus_search_skills'),
			'ajax_url'           => admin_url('admin-ajax.php'),
		)
	);

	// Enqueue jQuery if not already loaded
	wp_enqueue_script('jquery');
}

/**
 * Render the alumni profile shortcode
 *
 * @param array $atts Shortcode attributes
 * @return string
 */
function alumnus_render_profile_shortcode($atts = array()) {
	// Enqueue CSS
	alumnus_enqueue_profile_styles();

	// Parse shortcode attributes
	$atts = shortcode_atts(array(
		'user_id' => '', // Can be set via shortcode attribute
	), $atts);

	// Choose which profile to display
	// 1) If alumni_id is present, always respect it (viewing someone else's profile is allowed for display)
	// 2) Else, if an alumni session exists, default to that user (own profile)
	// 3) Else, no identity -> show login prompt (do not fall back to WP user)
	if (isset($_GET['alumni_id']) && $_GET['alumni_id'] !== '') {
		$user_id = sanitize_text_field(wp_unslash($_GET['alumni_id']));
	} elseif (!empty($atts['user_id'])) {
		$user_id = sanitize_text_field((string) $atts['user_id']);
	} else {
		$user_id = '';
		if (function_exists('alumnus_is_logged_in') && alumnus_is_logged_in() && function_exists('alumnus_current_username')) {
			$user_id = (string) alumnus_current_username();
		}
	}

	// If no user ID, show login message
	if (empty($user_id)) {
		return '<div class="alumnus-profile-error"><p>' . esc_html__('Please log in to view your profile.', 'alumnus') . '</p></div>';
	}

	global $wpdb;

	// Fetch alumni data from database, and aggregate skills from normalized tables
	$sql = "SELECT 
				a.user_id, a.year, a.course_id, a.firstname, a.lastname, a.email, a.contact_info, a.bio_note,
				GROUP_CONCAT(DISTINCT sk.skill ORDER BY sk.skill SEPARATOR ', ') AS skills,
				c.course AS course_name 
			FROM alumni a
			LEFT JOIN course c ON a.course_id = c.course_id
			LEFT JOIN alumni_skills aks ON aks.user_id = a.user_id
			LEFT JOIN skills sk ON sk.skill_id = aks.skill_id
			WHERE a.user_id = %s
			GROUP BY a.user_id";
	
	$alumni_data = $wpdb->get_row($wpdb->prepare($sql, $user_id));

	if (!$alumni_data) {
		// Debug: Show what user_id we're looking for
		$debug_msg = sprintf(
			__('Profile not found for user ID: %s', 'alumnus'),
			esc_html($user_id)
		);
		
		// Check if there are any alumni in the database
		$total_alumni = $wpdb->get_var("SELECT COUNT(*) FROM alumni");
		
		if ($total_alumni == 0) {
			$debug_msg .= '<br><br>' . __('Note: There are no alumni records in the database yet.', 'alumnus');
		} else {
			$debug_msg .= '<br><br>' . sprintf(__('There are %d alumni in the database.', 'alumnus'), $total_alumni);
			// Show a sample of user_ids to help debug
			$sample_ids = $wpdb->get_col("SELECT user_id FROM alumni LIMIT 5");
			if (!empty($sample_ids)) {
				$debug_msg .= '<br>' . __('Sample user IDs: ', 'alumnus') . implode(', ', array_map('esc_html', $sample_ids));
			}
		}
		
		return '<div class="alumnus-profile-error"><p>' . $debug_msg . '</p></div>';
	}

	// Check if viewing own profile.
	// Only an active alumni session grants "own profile" privileges (Edit button, etc.).
	$is_own_profile = false;
	if ( function_exists('alumnus_is_logged_in') && alumnus_is_logged_in() ) {
		$session_user = function_exists('alumnus_current_username') ? alumnus_current_username() : '';
		if ($session_user !== '') {
			$is_own_profile = ((string)$user_id === (string)$session_user);
		}
	}

	// Feature flag: control Recent Posts visibility (disabled by default; enable via filter)
	$alumnus_show_recent_posts = apply_filters('alumnus_profile_show_posts', false, $alumni_data, $is_own_profile);

	// Generate initials for avatar
	$initials = '';
	if (!empty($alumni_data->firstname)) {
		$initials .= strtoupper(substr($alumni_data->firstname, 0, 1));
	}
	if (!empty($alumni_data->lastname)) {
		$initials .= strtoupper(substr($alumni_data->lastname, 0, 1));
	}

	// Full name
	$full_name = trim(($alumni_data->firstname ?? '') . ' ' . ($alumni_data->lastname ?? ''));
	if (empty($full_name)) {
		$full_name = 'Alumni User';
	}

	// Experiences now link directly to alumni user_id (no WP user dependency)

	// Build skills array once for reuse
	$skills_array = array();
	if ( ! empty( $alumni_data->skills ) ) {
		$skills_array = preg_split('/[,\n]+/', (string) $alumni_data->skills);
		$skills_array = array_values( array_filter( array_map( 'trim', (array) $skills_array ) ) );
	}

	// Fetch user posts (from community feed or custom posts table if exists)
	// For now, we'll show placeholder posts. You can integrate with your posts table later
	$posts = array(); // This can be populated from your database

	// Fetch Experience rows by alumni user_id (string)
	$experiences = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT experience_id, company_name, title, location, start_date, end_date
			 FROM experience WHERE user_id = %s ORDER BY start_date DESC",
			$user_id
		)
	);

	// Helper to format date range like: Aug 2025 - Present · 4 mos
	$format_range = function( $start, $end ) {
		if ( empty( $start ) ) { return ''; }
		try {
			// Append -01 to YYYY-MM format dates for DateTime parsing
			$start_date = ( preg_match('/^\d{4}-\d{2}$/', $start) ) ? $start . '-01' : $start;
			$startDt = new DateTime( $start_date );
			$startStr = $startDt->format( 'M Y' );
			$endStr = 'Present';
			$endDt = null;
			if ( ! empty( $end ) ) {
				$end_date = ( preg_match('/^\d{4}-\d{2}$/', $end) ) ? $end . '-01' : $end;
				$endDt = new DateTime( $end_date );
				$endStr = $endDt->format( 'M Y' );
			} else {
				$endDt = new DateTime();
			}
			$diff = $startDt->diff( $endDt );
			$months = ( $diff->y * 12 ) + $diff->m;
			if ( $months <= 0 ) { $months = 1; }
			$dur  = sprintf( _n( '%d mo', '%d mos', $months, 'alumnus' ), $months );
			return $startStr . ' - ' . $endStr . ' · ' . $dur;
		} catch ( Exception $e ) {
			return '';
		}
	};

	ob_start();
	?>
		<div class="alumnus-profile-wrapper" id="alumnus-profile-root" data-ajax-url="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>" data-nonce-bio="<?php echo esc_attr( wp_create_nonce('alumnus_update_bio_note') ); ?>" data-nonce-skills="<?php echo esc_attr( wp_create_nonce('alumnus_update_skills') ); ?>" data-nonce-exp="<?php echo esc_attr( wp_create_nonce('alumnus_add_experience') ); ?>" data-user-id="<?php echo esc_attr( (string) $user_id ); ?>">
		<div class="alumnus-profile-header">
			<div class="aph-gradient-bg"></div>
		</div>


		<div class="alumnus-profile-container">
			<div class="alumnus-profile-card">
				<?php if ($is_own_profile): ?>
					<button type="button" class="apc-edit-btn" onclick="alumnus_openModal()"><?php echo esc_html__('Edit', 'alumnus'); ?></button>
				<?php endif; ?>
				<div class="apc-header">
					<div class="apc-avatar-wrapper">
						<div class="apc-avatar">
							<span class="apc-initials"><?php echo esc_html($initials); ?></span>
						</div>
					</div>
					
				<div class="apc-main-content">
					<div class="apc-left-column">
						<div class="apc-info">
							<h1 class="apc-name"><?php echo esc_html($full_name); ?></h1>
							<?php if (!empty($alumni_data->email)): ?>
								<p class="apc-email"><?php echo esc_html($alumni_data->email); ?></p>
							<?php endif; ?>
						</div>

						<div class="apc-info">
							<p class="apc-subtitle">
								<?php 
								$subtitle_parts = array();
								if (!empty($alumni_data->year)) {
									$subtitle_parts[] = 'Batch ' . esc_html($alumni_data->year);
								}
								if (!empty($alumni_data->course_name)) {
									$subtitle_parts[] = esc_html($alumni_data->course_name);
								}
								echo implode(' | ', $subtitle_parts);
								?>
							</p>
							<?php if (!empty($alumni_data->contact_info)): ?>
								<p class="apc-contact"><?php echo esc_html($alumni_data->contact_info); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<div class="apc-right-column">
						<!-- Bio Note Section -->
						<div class="apc-info-section apc-bio-section">
							<h2 class="apc-section-title">Bio</h2>
							<div class="apc-info-content" id="alumnus-bio-view">
								<?php if (!empty($alumni_data->bio_note)): ?>
									<?php echo wp_kses_post(nl2br($alumni_data->bio_note)); ?>
								<?php else: ?>
									<p class="apc-placeholder"><?php echo esc_html__('No bio provided yet.', 'alumnus'); ?></p>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Skills Card (Separate) -->
		<div class="alumnus-skills-container">
			<div class="alumnus-skills-card">
				<div class="apc-section-header-row">
					<h2 class="apc-section-title">Skills</h2>
					<?php if ( $is_own_profile ): ?>
						<button type="button" class="apc-add-btn" id="alumnus-skills-add-btn" aria-haspopup="dialog" aria-controls="alumnus-skills-modal-overlay">Add Skills</button>
					<?php endif; ?>
				</div>
				<div id="alumnus-skills-view">
					<?php if (!empty($skills_array)): ?>
						<div class="apc-skills-list">
							<?php foreach ($skills_array as $skill): ?>
								<span class="apc-skill-tag"><?php echo esc_html($skill); ?></span>
							<?php endforeach; ?>
						</div>
					<?php else: ?>
						<div class="apc-info-content"><p class="apc-placeholder"><?php echo esc_html__('No skills listed yet.', 'alumnus'); ?></p></div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Experience Card (Separate) -->
		<div class="alumnus-experience-container">
			<div class="alumnus-experience-card">
				<div class="apc-section-header-row">
					<h2 class="apc-section-title">Experience</h2>
					<?php if ( $is_own_profile ): ?>
						<button type="button" class="apc-add-btn" id="alumnus-exp-add-btn" aria-haspopup="dialog" aria-controls="alumnus-exp-modal-overlay">Add Experience</button>
					<?php endif; ?>
				</div>
				<div id="alumnus-experience-view">
					<?php if ( ! empty( $experiences ) ): ?>
						<ul class="apc-exp-list">
							<?php foreach ( $experiences as $exp ): ?>
								<li class="apc-exp-item" data-exp-id="<?php echo esc_attr( $exp->experience_id ); ?>" data-start="<?php echo esc_attr( $exp->start_date ); ?>" data-end="<?php echo esc_attr( $exp->end_date ); ?>" data-company="<?php echo esc_attr( $exp->company_name ); ?>" data-location="<?php echo esc_attr( $exp->location ?? '' ); ?>">
									<div class="apc-exp-header">
										<div class="apc-exp-title"><?php echo esc_html( $exp->title ); ?></div>
										<div class="apc-exp-company">
											<?php echo esc_html( $exp->company_name ); ?>
										</div>
									</div>
									<div class="apc-exp-meta">
										<div class="apc-exp-dates"><?php echo esc_html( $format_range( $exp->start_date, $exp->end_date ) ); ?></div>
										<?php if ( ! empty( $exp->location ) ): ?>
											<div class="apc-exp-dates"><?php echo esc_html( $exp->location ); ?></div>
										<?php endif; ?>
									</div>
									<?php if ( $is_own_profile ): ?>
										<div class="apc-exp-actions">
											<button type="button" class="apc-exp-action-btn apc-exp-edit" data-exp-id="<?php echo esc_attr( $exp->experience_id ); ?>">Edit</button>
											<button type="button" class="apc-exp-action-btn apc-exp-delete" data-exp-id="<?php echo esc_attr( $exp->experience_id ); ?>">Delete</button>
										</div>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else: ?>
						<div class="apc-info-content"><p class="apc-placeholder"><?php echo esc_html__('No experience added yet.', 'alumnus'); ?></p></div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<?php if ( $is_own_profile ): ?>
			<!-- Add Skills Modal -->
			<div id="alumnus-skills-modal-overlay" class="alumnus-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="alumnus-skills-modal-title">
				<div class="alumnus-modal-card">
					<button id="alumnus-skills-modal-close" class="alumnus-modal-close-btn" type="button" aria-label="Close">&times;</button>
					<h2 class="alumnus-modal-header" id="alumnus-skills-modal-title"><?php echo esc_html__('Edit Skills', 'alumnus'); ?></h2>
					<div class="alumnus-modal-body">
						<div class="alumnus-modal-field">
							<label for="alumnus-skills-modal-input" class="alumnus-modal-label"><?php echo esc_html__('Skills (comma-separated)', 'alumnus'); ?></label>
							<textarea id="alumnus-skills-modal-input" name="skills" rows="5" class="alumnus-modal-textarea" placeholder="<?php echo esc_attr__('e.g., Project Management, Problem Solving, Data Analysis', 'alumnus'); ?>"><?php echo esc_textarea( (string) $alumni_data->skills ); ?></textarea>
							<p class="alumnus-modal-hint"><?php echo esc_html__('Enter skills separated by commas. Each skill will appear as a tag.', 'alumnus'); ?></p>
						</div>
					</div>
					<div class="alumnus-modal-footer">
						<button type="button" class="aph-nav-btn alumnus-modal-btn-save" id="alumnus-skills-save"><?php echo esc_html__('Save', 'alumnus'); ?></button>
						<button type="button" class="aph-nav-btn alumnus-modal-btn-cancel" id="alumnus-skills-cancel"><?php echo esc_html__('Cancel', 'alumnus'); ?></button>
					</div>
				</div>
			</div>

			<!-- Add Experience Modal -->
			<div id="alumnus-exp-modal-overlay" class="alumnus-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="alumnus-exp-modal-title">
				<div class="alumnus-modal-card">
					<button id="alumnus-exp-modal-close" class="alumnus-modal-close-btn" type="button" aria-label="Close">&times;</button>
					<h2 class="alumnus-modal-header" id="alumnus-exp-modal-title"><?php echo esc_html__('Add Experience', 'alumnus'); ?></h2>
					<div class="alumnus-modal-body">
						<div class="alumnus-modal-field">
							<label for="alumnus-exp-title" class="alumnus-modal-label"><?php echo esc_html__('Title', 'alumnus'); ?></label>
							<input type="text" id="alumnus-exp-title" class="alumnus-modal-input" maxlength="40">
						</div>
						<div class="alumnus-modal-field">
							<label for="alumnus-exp-company" class="alumnus-modal-label"><?php echo esc_html__('Company', 'alumnus'); ?></label>
							<input type="text" id="alumnus-exp-company" class="alumnus-modal-input" maxlength="40">
						</div>
						<div class="alumnus-modal-field">
							<label class="alumnus-modal-label"><?php echo esc_html__('Dates', 'alumnus'); ?></label>
							<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
								<input type="month" id="alumnus-exp-start" class="alumnus-modal-input" style="max-width:220px;">
								<span>—</span>
								<input type="month" id="alumnus-exp-end" class="alumnus-modal-input" style="max-width:220px;">
								<label style="display:flex; gap:6px; align-items:center; font-size:14px;">
									<input type="checkbox" id="alumnus-exp-current"> <?php echo esc_html__('I currently work here', 'alumnus'); ?>
								</label>
							</div>
						</div>
						<div class="alumnus-modal-field">
							<label for="alumnus-exp-location" class="alumnus-modal-label"><?php echo esc_html__('Location', 'alumnus'); ?></label>
							<input type="text" id="alumnus-exp-location" class="alumnus-modal-input" maxlength="40">
						</div>
					</div>
					<div class="alumnus-modal-footer">
						<button type="button" class="aph-nav-btn alumnus-modal-btn-save" id="alumnus-exp-save"><?php echo esc_html__('Save', 'alumnus'); ?></button>
						<button type="button" class="aph-nav-btn alumnus-modal-btn-cancel" id="alumnus-exp-cancel"><?php echo esc_html__('Cancel', 'alumnus'); ?></button>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $alumnus_show_recent_posts ) : ?>
			<div class="alumnus-profile-container">
					<!-- Recent Posts Section -->
					<div class="apc-posts-section">
						<h2 class="apc-section-title">Recent Posts</h2>
						<?php if (empty($posts)): ?>
							<div class="apc-empty-state">
								<div class="apc-empty-icon">📝</div>
								<p class="apc-empty-text"><?php echo esc_html__('No posts yet.', 'alumnus'); ?></p>
								<?php if ($is_own_profile): ?>
									<p class="apc-empty-subtext"><?php echo esc_html__('Share your thoughts with the community!', 'alumnus'); ?></p>
								<?php endif; ?>
							</div>
						<?php else: ?>
							<?php foreach ($posts as $post): ?>
								<div class="apc-post">
									<div class="apc-post-header">
										<div class="apc-post-title"><?php echo esc_html($post['title']); ?></div>
										<div class="apc-post-time"><?php echo esc_html($post['time']); ?></div>
									</div>
									<?php if (!empty($post['content'])): ?>
										<div class="apc-post-content"><?php echo wp_kses_post($post['content']); ?></div>
									<?php endif; ?>
									<div class="apc-post-actions">
										<button class="apc-action-btn" onclick="alumnus_toggleLike(this)">
											<span class="apc-action-icon">👍</span>
											<span class="apc-action-text">Like</span>
										</button>
										<button class="apc-action-btn" onclick="alert('Comment feature coming soon')">
											<span class="apc-action-icon">💬</span>
											<span class="apc-action-text">Comment</span>
										</button>
										<button class="apc-action-btn" onclick="alert('Share feature coming soon')">
											<span class="apc-action-icon">🔗</span>
											<span class="apc-action-text">Share</span>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

		<?php if ($is_own_profile): ?>
			<!-- Edit Profile Modal -->
			<div id="alumnus-modal-overlay" class="alumnus-modal-overlay">
				<div class="alumnus-modal-card">
					<button id="alumnus-modal-close" class="alumnus-modal-close-btn" type="button">&times;</button>
					<h2 class="alumnus-modal-header"><?php echo esc_html__('Edit Bio', 'alumnus'); ?></h2>
			
					<div class="alumnus-modal-body">
						<div class="alumnus-modal-field">
							<label for="alumnus-modal-bio-input" class="alumnus-modal-label"><?php echo esc_html__('Bio', 'alumnus'); ?></label>
							<textarea id="alumnus-modal-bio-input" name="bio_note" rows="8" maxlength="250" class="alumnus-modal-textarea" placeholder="<?php echo esc_attr__('Tell us about yourself…', 'alumnus'); ?>"><?php echo esc_textarea( (string) $alumni_data->bio_note ); ?></textarea>
							<div class="alumnus-char-counter">
								<span id="alumnus-bio-char-count">0</span> / 250 <?php echo esc_html__('characters', 'alumnus'); ?>
							</div>
						</div>
					</div>

					<div class="alumnus-modal-footer">
						<button type="button" class="aph-nav-btn alumnus-modal-btn-save" id="alumnus-modal-save"><?php echo esc_html__('Save Changes', 'alumnus'); ?></button>
						<button type="button" class="aph-nav-btn alumnus-modal-btn-cancel" id="alumnus-modal-cancel"><?php echo esc_html__('Cancel', 'alumnus'); ?></button>
					</div>
				</div>
			</div>
		</div>

			<!-- Edit Experience Modal -->
			<div id="alumnus-exp-edit-modal-overlay" class="alumnus-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="alumnus-exp-edit-modal-title">
				<div class="alumnus-modal-card">
					<button id="alumnus-exp-edit-modal-close" class="alumnus-modal-close-btn" type="button" aria-label="Close">&times;</button>
					<h2 class="alumnus-modal-header" id="alumnus-exp-edit-modal-title"><?php echo esc_html__('Edit Experience', 'alumnus'); ?></h2>
					<div class="alumnus-modal-body">
						<input type="hidden" id="alumnus-exp-edit-id" value="" />
						<div class="alumnus-modal-field">
							<label for="alumnus-exp-edit-title" class="alumnus-modal-label"><?php echo esc_html__('Title', 'alumnus'); ?></label>
							<input type="text" id="alumnus-exp-edit-title" class="alumnus-modal-input" maxlength="40">
						</div>
						<div class="alumnus-modal-field">
							<label for="alumnus-exp-edit-company" class="alumnus-modal-label"><?php echo esc_html__('Company', 'alumnus'); ?></label>
							<input type="text" id="alumnus-exp-edit-company" class="alumnus-modal-input" maxlength="40">
						</div>
						<div class="alumnus-modal-field">
							<label class="alumnus-modal-label"><?php echo esc_html__('Dates', 'alumnus'); ?></label>
							<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
								<input type="month" id="alumnus-exp-edit-start" class="alumnus-modal-input" style="max-width:220px;">
								<span>—</span>
								<input type="month" id="alumnus-exp-edit-end" class="alumnus-modal-input" style="max-width:220px;">
								<label style="display:flex; gap:6px; align-items:center; font-size:14px;">
									<input type="checkbox" id="alumnus-exp-edit-current"> <?php echo esc_html__('I currently work here', 'alumnus'); ?>
								</label>
							</div>
						</div>
						<div class="alumnus-modal-field">
							<label for="alumnus-exp-edit-location" class="alumnus-modal-label"><?php echo esc_html__('Location', 'alumnus'); ?></label>
							<input type="text" id="alumnus-exp-edit-location" class="alumnus-modal-input" maxlength="40">
						</div>
					</div>
					<div class="alumnus-modal-footer">
						<button type="button" class="aph-nav-btn alumnus-modal-btn-save" id="alumnus-exp-edit-save"><?php echo esc_html__('Update', 'alumnus'); ?></button>
						<button type="button" class="aph-nav-btn alumnus-modal-btn-cancel" id="alumnus-exp-edit-cancel"><?php echo esc_html__('Cancel', 'alumnus'); ?></button>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<?php
	return ob_get_clean();
}

add_shortcode( 'alumni_profile', 'alumnus_render_profile_shortcode' );
/**
 * AJAX: Add an experience entry for the logged-in alumni user.
 * POST: user_id, title, company_name, location, start_date, end_date, _ajax_nonce
 */
function alumnus_add_experience_ajax() {
	// Nonce check
	if ( ! isset($_POST['_ajax_nonce']) || ! wp_verify_nonce( (string) $_POST['_ajax_nonce'], 'alumnus_add_experience' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed.', 'alumnus' ) ), 403 );
	}

	// Must have alumni session and match user_id
	if ( ! function_exists('alumnus_is_logged_in') || ! alumnus_is_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'alumnus' ) ), 401 );
	}

	$session_user = function_exists('alumnus_current_username') ? alumnus_current_username() : '';
	$user_id = isset($_POST['user_id']) ? sanitize_text_field( wp_unslash($_POST['user_id']) ) : '';
	if ( $user_id === '' || (string) $user_id !== (string) $session_user ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied for this user.', 'alumnus' ) ), 403 );
	}

	// Inputs
	$title   = isset($_POST['title']) ? sanitize_text_field( wp_unslash($_POST['title']) ) : '';
	$company = isset($_POST['company_name']) ? sanitize_text_field( wp_unslash($_POST['company_name']) ) : '';
	$location= isset($_POST['location']) ? sanitize_text_field( wp_unslash($_POST['location']) ) : '';
	$start   = isset($_POST['start_date']) ? sanitize_text_field( wp_unslash($_POST['start_date']) ) : '';
	$end     = isset($_POST['end_date']) ? sanitize_text_field( wp_unslash($_POST['end_date']) ) : '';

	if ( $title === '' || $company === '' || $start === '' ) {
		wp_send_json_error( array( 'message' => __( 'Please provide Title, Company, and Start date.', 'alumnus' ) ), 400 );
	}

    global $wpdb; // direct alumni linkage; no WP user mapping required

	// Validate date strings (YYYY-MM format from month input)
	$start_ok = preg_match('/^\d{4}-\d{2}$/', $start);
	$end_ok = ($end === '' || preg_match('/^\d{4}-\d{2}$/', $end));
	if ( ! $start_ok || ! $end_ok ) {
		wp_send_json_error( array( 'message' => __( 'Invalid date format. Use YYYY-MM (e.g., 2025-10).', 'alumnus' ) ), 400 );
	}

	// Convert YYYY-MM to YYYY-MM-01 for MySQL DATE column
	$start_db = $start . '-01';
	$end_db = $end ? $end . '-01' : null;

	// Insert row
	$ins = $wpdb->insert(
		'experience',
		array(
			'user_id'      => $user_id,
			'company_name' => $company,
			'title'        => $title,
			'location'     => $location,
			'start_date'   => $start_db,
			'end_date'     => $end_db,
		),
		array( '%s','%s','%s','%s','%s','%s' )
	);

	if ( $ins === false ) {
		wp_send_json_error( array( 'message' => sprintf( __( 'Database error: %s', 'alumnus' ), $wpdb->last_error ) ), 500 );
	}

	// Rebuild experience HTML like in the profile render
	$experiences = $wpdb->get_results( $wpdb->prepare(
		"SELECT experience_id, company_name, title, location, start_date, end_date FROM experience WHERE user_id = %s ORDER BY start_date DESC",
		$user_id
	) );

	$skills_csv = '';
	$skills_arr = array();
	$row2 = $wpdb->get_row( $wpdb->prepare(
		"SELECT GROUP_CONCAT(DISTINCT sk.skill ORDER BY sk.skill SEPARATOR ', ') AS skills
		 FROM alumni a LEFT JOIN alumni_skills aks ON aks.user_id = a.user_id
		 LEFT JOIN skills sk ON sk.skill_id = aks.skill_id WHERE a.user_id = %s GROUP BY a.user_id",
		$user_id
	) );
	if ( $row2 && ! empty( $row2->skills ) ) {
		$skills_csv = (string) $row2->skills;
		$skills_arr = array_values( array_filter( array_map( 'trim', preg_split('/[,\n]+/', $skills_csv) ) ) );
	}

	$format_range = function( $start, $end ) {
		if ( empty( $start ) ) return '';
		try {
			// Append -01 to YYYY-MM format dates for DateTime parsing
			$start_date = ( preg_match('/^\d{4}-\d{2}$/', $start) ) ? $start . '-01' : $start;
			$s = new DateTime($start_date);
			$end_date = '';
			if ( $end ) {
				$end_date = ( preg_match('/^\d{4}-\d{2}$/', $end) ) ? $end . '-01' : $end;
			}
			$e = $end_date ? new DateTime($end_date) : new DateTime();
			$months = $s->diff($e);
			$m = ($months->y * 12) + $months->m;
			if ($m <= 0) { $m = 1; }
			$dur = sprintf( _n('%d mo','%d mos',$m,'alumnus'), $m );
			return $s->format('M Y') . ' - ' . ($end ? (new DateTime($end_date))->format('M Y') : 'Present') . ' · ' . $dur;
		} catch (Exception $ex) { return ''; }
	};

	ob_start();
	if ( ! empty( $experiences ) ) {
		echo '<ul class="apc-exp-list">';
		foreach ( $experiences as $exp ) {
			echo '<li class="apc-exp-item" data-exp-id="'.esc_attr($exp->experience_id).'" data-start="'.esc_attr($exp->start_date).'" data-end="'.esc_attr($exp->end_date).'" data-company="'.esc_attr($exp->company_name).'" data-location="'.esc_attr($exp->location ?? '').'">';
			echo '<div class="apc-exp-header">';
			echo '<div class="apc-exp-title">' . esc_html($exp->title) . '</div>';
			echo '<div class="apc-exp-company">' . esc_html($exp->company_name) . '</div>';
			echo '</div>';
		echo '<div class="apc-exp-meta">';
		echo '<div class="apc-exp-dates">' . esc_html( $format_range($exp->start_date, $exp->end_date) ) . '</div>';
		if ( ! empty( $exp->location ) ) {
			echo '<div class="apc-exp-dates">' . esc_html( $exp->location ) . '</div>';
		}
		echo '</div>';
			// Since only the owner can add, show actions
			echo '<div class="apc-exp-actions">';
			echo '<button type="button" class="apc-exp-action-btn apc-exp-edit" data-exp-id="'.esc_attr($exp->experience_id).'">'.esc_html__('Edit','alumnus').'</button>';
			echo '<button type="button" class="apc-exp-action-btn apc-exp-delete" data-exp-id="'.esc_attr($exp->experience_id).'">'.esc_html__('Delete','alumnus').'</button>';
			echo '</div>';
			echo '</li>';
		}
		echo '</ul>';
	} else {
		echo '<div class="apc-info-content"><p class="apc-placeholder">' . esc_html__('No experience added yet.', 'alumnus') . '</p></div>';
	}
	$html = ob_get_clean();

	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_alumnus_add_experience', 'alumnus_add_experience_ajax' );
add_action( 'wp_ajax_nopriv_alumnus_add_experience', 'alumnus_add_experience_ajax' );

/**
 * AJAX: Update an existing experience
 */
function alumnus_update_experience_ajax() {
	if ( ! isset($_POST['_ajax_nonce']) || ! wp_verify_nonce( (string) $_POST['_ajax_nonce'], 'alumnus_add_experience' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed.', 'alumnus' ) ), 403 );
	}
	if ( ! function_exists('alumnus_is_logged_in') || ! alumnus_is_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'alumnus' ) ), 401 );
	}
	$session_user = function_exists('alumnus_current_username') ? alumnus_current_username() : '';
	$user_id = isset($_POST['user_id']) ? sanitize_text_field( wp_unslash($_POST['user_id']) ) : '';
	if ( $user_id === '' || (string) $user_id !== (string) $session_user ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied for this user.', 'alumnus' ) ), 403 );
	}

	$exp_id  = isset($_POST['experience_id']) ? intval($_POST['experience_id']) : 0;
	$title   = isset($_POST['title']) ? sanitize_text_field( wp_unslash($_POST['title']) ) : '';
	$company = isset($_POST['company_name']) ? sanitize_text_field( wp_unslash($_POST['company_name']) ) : '';
	$location= isset($_POST['location']) ? sanitize_text_field( wp_unslash($_POST['location']) ) : '';
	$start   = isset($_POST['start_date']) ? sanitize_text_field( wp_unslash($_POST['start_date']) ) : '';
	$end     = isset($_POST['end_date']) ? sanitize_text_field( wp_unslash($_POST['end_date']) ) : '';
	if (!$exp_id || $title === '' || $company === '') {
		wp_send_json_error( array( 'message' => __( 'Missing fields.', 'alumnus' ) ), 400 );
	}
	$start_ok = ($start === '' || preg_match('/^\d{4}-\d{2}$/', $start));
	$end_ok = ($end === '' || preg_match('/^\d{4}-\d{2}$/', $end));
	if (!$start_ok || !$end_ok) {
		wp_send_json_error( array( 'message' => __( 'Invalid date format. Use YYYY-MM (e.g., 2025-10).', 'alumnus' ) ), 400 );
	}

	// Convert YYYY-MM to YYYY-MM-01 for MySQL DATE column
	$start_db = $start ? $start . '-01' : null;
	$end_db = $end ? $end . '-01' : null;

	global $wpdb;
	// Ensure the row belongs to this user
	$owner = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM experience WHERE experience_id = %d AND user_id = %s", $exp_id, $user_id) );
	if (!$owner) {
		wp_send_json_error( array( 'message' => __( 'Experience not found.', 'alumnus' ) ), 404 );
	}

	$wpdb->update(
		'experience',
		array(
			'title' => $title,
			'company_name' => $company,
			'location' => $location,
			'start_date' => $start_db,
			'end_date' => $end_db,
		),
		array('experience_id' => $exp_id, 'user_id' => $user_id),
		array('%s','%s','%s','%s','%s'),
		array('%d','%s')
	);

	$experiences = $wpdb->get_results( $wpdb->prepare(
		"SELECT experience_id, company_name, title, location, start_date, end_date FROM experience WHERE user_id = %s ORDER BY start_date DESC",
		$user_id
	) );

	$skills_arr = array();
	$row2 = $wpdb->get_row( $wpdb->prepare(
		"SELECT GROUP_CONCAT(DISTINCT sk.skill ORDER BY sk.skill SEPARATOR ', ') AS skills FROM alumni a LEFT JOIN alumni_skills aks ON aks.user_id = a.user_id LEFT JOIN skills sk ON sk.skill_id = aks.skill_id WHERE a.user_id = %s GROUP BY a.user_id",
		$user_id
	) );
	if ($row2 && !empty($row2->skills)) {
		$skills_arr = array_values( array_filter( array_map( 'trim', preg_split('/[,\n]+/', (string)$row2->skills) ) ) );
	}

	$format_range = function( $start, $end ) {
		if ( empty( $start ) ) return '';
		try { 
			// Append -01 to YYYY-MM format dates for DateTime parsing
			$start_date = ( preg_match('/^\d{4}-\d{2}$/', $start) ) ? $start . '-01' : $start;
			$end_date = '';
			if ( $end ) {
				$end_date = ( preg_match('/^\d{4}-\d{2}$/', $end) ) ? $end . '-01' : $end;
			}
			$s=new DateTime($start_date); $e=$end_date?new DateTime($end_date):new DateTime(); $m=$s->diff($e); $mm=($m->y*12)+$m->m; if($mm<=0){$mm=1;} $dur=sprintf(_n('%d mo','%d mos',$mm,'alumnus'),$mm); return $s->format('M Y').' - '.($end?(new DateTime($end_date))->format('M Y'):'Present').' · '.$dur; 
		} catch(Exception $x){ return ''; }
	};

	ob_start();
	if ( ! empty( $experiences ) ) {
		echo '<ul class="apc-exp-list">';
		foreach ( $experiences as $exp ) {
			echo '<li class="apc-exp-item" data-exp-id="'.esc_attr($exp->experience_id).'" data-start="'.esc_attr($exp->start_date).'" data-end="'.esc_attr($exp->end_date).'" data-company="'.esc_attr($exp->company_name).'" data-location="'.esc_attr($exp->location ?? '').'">';
			echo '<div class="apc-exp-header">';
			echo '<div class="apc-exp-title">'.esc_html($exp->title).'</div>';
			echo '<div class="apc-exp-company">'.esc_html($exp->company_name).'</div>';
			echo '</div>';
		echo '<div class="apc-exp-meta">';
		echo '<div class="apc-exp-dates">'.esc_html($format_range($exp->start_date,$exp->end_date)).'</div>';
		if (!empty($exp->location)) {
			echo '<div class="apc-exp-dates">'.esc_html($exp->location).'</div>';
		}
		echo '</div>';
			echo '<div class="apc-exp-actions">';
			echo '<button type="button" class="apc-exp-action-btn apc-exp-edit" data-exp-id="'.esc_attr($exp->experience_id).'">'.esc_html__('Edit','alumnus').'</button>';
			echo '<button type="button" class="apc-exp-action-btn apc-exp-delete" data-exp-id="'.esc_attr($exp->experience_id).'">'.esc_html__('Delete','alumnus').'</button>';
			echo '</div>';
			echo '</li>';
		}
		echo '</ul>';
	} else {
		echo '<div class="apc-info-content"><p class="apc-placeholder">' . esc_html__('No experience added yet.', 'alumnus') . '</p></div>';
	}
	$html = ob_get_clean();
	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_alumnus_update_experience', 'alumnus_update_experience_ajax' );
add_action( 'wp_ajax_nopriv_alumnus_update_experience', 'alumnus_update_experience_ajax' );

/**
 * AJAX: Delete experience
 */
function alumnus_delete_experience_ajax() {
	if ( ! isset($_POST['_ajax_nonce']) || ! wp_verify_nonce( (string) $_POST['_ajax_nonce'], 'alumnus_add_experience' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed.', 'alumnus' ) ), 403 );
	}
	if ( ! function_exists('alumnus_is_logged_in') || ! alumnus_is_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'alumnus' ) ), 401 );
	}
	$session_user = function_exists('alumnus_current_username') ? alumnus_current_username() : '';
	$user_id = isset($_POST['user_id']) ? sanitize_text_field( wp_unslash($_POST['user_id']) ) : '';
	if ( $user_id === '' || (string) $user_id !== (string) $session_user ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied for this user.', 'alumnus' ) ), 403 );
	}
	$exp_id = isset($_POST['experience_id']) ? intval($_POST['experience_id']) : 0;
	if (!$exp_id) {
		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'alumnus' ) ), 400 );
	}
	global $wpdb;
	$wpdb->delete('experience', array('experience_id' => $exp_id, 'user_id' => $user_id), array('%d','%s'));
	$experiences = $wpdb->get_results( $wpdb->prepare(
		"SELECT experience_id, company_name, title, location, start_date, end_date FROM experience WHERE user_id = %s ORDER BY start_date DESC",
		$user_id
	) );

	ob_start();
	if ( ! empty( $experiences ) ) {
		echo '<ul class="apc-exp-list">';
		foreach ( $experiences as $exp ) {
			echo '<li class="apc-exp-item" data-exp-id="'.esc_attr($exp->experience_id).'" data-company="'.esc_attr($exp->company_name).'" data-location="'.esc_attr($exp->location ?? '').'">';
			echo '<div class="apc-exp-header">';
			echo '<div class="apc-exp-title">'.esc_html($exp->title).'</div>';
			echo '<div class="apc-exp-company">'.esc_html($exp->company_name).'</div>';
			echo '</div>';
			echo '<div class="apc-exp-meta">';
			echo '<div class="apc-exp-dates">'.esc_html($exp->start_date).' - '.esc_html($exp->end_date ?: 'Present').'</div>';
			if (!empty($exp->location)) {
				echo '<div class="apc-exp-dates">'.esc_html($exp->location).'</div>';
			}
			echo '</div>';
			echo '<div class="apc-exp-actions">';
			echo '<button type="button" class="apc-exp-action-btn apc-exp-edit" data-exp-id="'.esc_attr($exp->experience_id).'">'.esc_html__('Edit','alumnus').'</button>';
			echo '<button type="button" class="apc-exp-action-btn apc-exp-delete" data-exp-id="'.esc_attr($exp->experience_id).'">'.esc_html__('Delete','alumnus').'</button>';
			echo '</div>';
			echo '</li>';
		}
		echo '</ul>';
	} else {
		echo '<div class="apc-info-content"><p class="apc-placeholder">' . esc_html__('No experience added yet.', 'alumnus') . '</p></div>';
	}
	$html = ob_get_clean();
	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_alumnus_delete_experience', 'alumnus_delete_experience_ajax' );
add_action( 'wp_ajax_nopriv_alumnus_delete_experience', 'alumnus_delete_experience_ajax' );

/**
 * AJAX handler to update skills (CSV string) of the logged-in alumni user.
 * Accepts POST: user_id, skills, _ajax_nonce
 */
function alumnus_update_skills_ajax() {
	// Nonce check
	if ( ! isset($_POST['_ajax_nonce']) || ! wp_verify_nonce( (string) $_POST['_ajax_nonce'], 'alumnus_update_skills' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed.', 'alumnus' ) ), 403 );
	}

	// Must have our custom alumni session and match user_id
	if ( ! function_exists('alumnus_is_logged_in') || ! alumnus_is_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'alumnus' ) ), 401 );
	}

	$session_user = function_exists('alumnus_current_username') ? alumnus_current_username() : '';
	$user_id = isset($_POST['user_id']) ? sanitize_text_field( wp_unslash($_POST['user_id']) ) : '';
	if ( $user_id === '' || (string) $user_id !== (string) $session_user ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied for this user.', 'alumnus' ) ), 403 );
	}

	$skills_raw = isset($_POST['skills']) ? (string) wp_unslash($_POST['skills']) : '';
	// Parse into array by commas/newlines, trim, dedupe, limit size and length
	$parts = preg_split('/[,\n]+/', $skills_raw);
	$clean = array();
	if ( is_array($parts) ) {
		foreach ($parts as $p) {
			$p = trim( wp_strip_all_tags( $p ) ); // no HTML in skills
			if ($p === '') continue;
			// Limit individual skill length
			if ( strlen($p) > 64 ) { $p = substr($p, 0, 64); }
			$clean[] = $p;
		}
		// Dedupe and cap total skills
		$clean = array_values( array_unique( $clean ) );
		if ( count($clean) > 50 ) {
			$clean = array_slice($clean, 0, 50);
		}
	}
	$csv = implode(', ', $clean);

	global $wpdb;
	// Replace user's skills with the new set in pivot table
	// Delete existing links
	$wpdb->delete('alumni_skills', array('user_id' => $user_id), array('%s'));

	// Insert new links (and upsert skills)
	foreach ($clean as $s) {
		// Ensure skill exists
		$skill_id = $wpdb->get_var($wpdb->prepare("SELECT skill_id FROM skills WHERE skill = %s", $s));
		if (empty($skill_id)) {
			$ins = $wpdb->insert('skills', array('skill' => $s), array('%s'));
			if ($ins !== false) {
				$skill_id = $wpdb->insert_id;
			} else {
				// If insert failed due to race/duplicate, fetch again
				$skill_id = $wpdb->get_var($wpdb->prepare("SELECT skill_id FROM skills WHERE skill = %s", $s));
			}
		}
		if (!empty($skill_id)) {
			$wpdb->insert('alumni_skills', array('user_id' => $user_id, 'skill_id' => (int)$skill_id), array('%s','%d'));
		}
	}

	// Build refreshed HTML for the skills view with skill tags
	if ( ! empty($clean) ) {
		$html = '<div class="apc-skills-list">';
		foreach ($clean as $s) {
			$html .= '<span class="apc-skill-tag">' . esc_html( $s ) . '</span>';
		}
		$html .= '</div>';
	} else {
		$html = '<div class="apc-info-content"><p class="apc-placeholder">' . esc_html__( 'No skills listed yet.', 'alumnus' ) . '</p></div>';
	}

	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_alumnus_update_skills', 'alumnus_update_skills_ajax' );
add_action( 'wp_ajax_nopriv_alumnus_update_skills', 'alumnus_update_skills_ajax' );

/**
 * AJAX handler to search/suggest skills as user types
 * Optimized to prevent server overload with debouncing and limits
 */
function alumnus_search_skills_ajax() {
	check_ajax_referer('alumnus_search_skills', 'nonce');
	
	$search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
	
	// Require minimum 2 characters to prevent excessive queries
	if (strlen($search_term) < 2) {
		wp_send_json_success(array('skills' => array()));
		return;
	}
	
	global $wpdb;
	
	// Search for skills that match the input (case-insensitive)
	// Limit to 20 results to keep response fast
	$like = '%' . $wpdb->esc_like($search_term) . '%';
	$results = $wpdb->get_col($wpdb->prepare(
		"SELECT DISTINCT skill FROM skills 
		 WHERE skill LIKE %s 
		 ORDER BY skill ASC 
		 LIMIT 20",
		$like
	));
	
	wp_send_json_success(array('skills' => $results));
}
add_action('wp_ajax_alumnus_search_skills', 'alumnus_search_skills_ajax');
add_action('wp_ajax_nopriv_alumnus_search_skills', 'alumnus_search_skills_ajax');

/**
 * AJAX handler to update bio_note of the logged-in alumni user.
 * Accepts POST: user_id, bio_note, _ajax_nonce
 */
function alumnus_update_bio_note_ajax() {
	// Nonce check
	if ( ! isset($_POST['_ajax_nonce']) || ! wp_verify_nonce( (string) $_POST['_ajax_nonce'], 'alumnus_update_bio_note' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed.', 'alumnus' ) ), 403 );
	}

	// Must have our custom alumni session and match user_id
	if ( ! function_exists('alumnus_is_logged_in') || ! alumnus_is_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'alumnus' ) ), 401 );
	}

	$session_user = function_exists('alumnus_current_username') ? alumnus_current_username() : '';
	$user_id = isset($_POST['user_id']) ? sanitize_text_field( wp_unslash($_POST['user_id']) ) : '';
	if ( $user_id === '' || (string) $user_id !== (string) $session_user ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied for this user.', 'alumnus' ) ), 403 );
	}

	// Sanitize and normalize input
	$bio_raw = isset($_POST['bio_note']) ? (string) wp_unslash($_POST['bio_note']) : '';
	// Allow basic HTML similar to wp_kses_post; store cleaned HTML
	$bio_clean = wp_kses_post( $bio_raw );

	global $wpdb;
	$updated = $wpdb->update(
		'alumni',
		array( 'bio_note' => $bio_clean ),
		array( 'user_id' => $user_id ),
		array( '%s' ),
		array( '%s' )
	);

	if ( $updated === false ) {
		wp_send_json_error( array( 'message' => sprintf( __( 'Database error: %s', 'alumnus' ), $wpdb->last_error ) ), 500 );
	}

	// Prepare HTML for the view block
	$html = $bio_clean !== '' ? nl2br( $bio_clean ) : '<p class="apc-placeholder">' . esc_html__( 'No bio provided yet.', 'alumnus' ) . '</p>';

	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_alumnus_update_bio_note', 'alumnus_update_bio_note_ajax' );
add_action( 'wp_ajax_nopriv_alumnus_update_bio_note', 'alumnus_update_bio_note_ajax' );

/**
 * Helper function to get profile URL for an alumni
 * 
 * @param string $user_id The alumni user ID
 * @param string $profile_page_url Optional. The URL of the page with [alumni_profile] shortcode. 
 *                                 If not provided, uses current page.
 * @return string The profile URL
 */
if ( ! function_exists( 'alumnus_get_profile_url' ) ) {
	function alumnus_get_profile_url( $user_id, $profile_page_url = '' ) {
		if ( empty( $profile_page_url ) ) {
			$profile_page_url = get_permalink();
		}
		return add_query_arg( 'alumni_id', rawurlencode( $user_id ), $profile_page_url );
	}
}

?>