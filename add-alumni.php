<?php
/*
Plugin Name: Alumnus Alumni Manager
Description: Admin page to add Courses and Alumni records with list view, edit/delete, and bulk JSON import.
Version: 2.0.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/**
 * Resolve actual table names. Prefer prefixed tables if found; otherwise fall back to unprefixed.
 * Supports both legacy `courses` and new `course` table; returns keys: 'courses' (alias for actual course table), 'alumni', 'user'.
 */
function alumnus_get_table_names() {
	global $wpdb;
	$tables = [
		'courses' => 'courses', // alias; may map to 'course'
		'alumni'  => 'alumni',
		'user'    => 'user',
	];

	// Courses table detection: try wp_courses, then wp_course, then courses, then course
	$candidates_courses = [ $wpdb->prefix . 'courses', $wpdb->prefix . 'course', 'courses', 'course' ];
	foreach ($candidates_courses as $cand) {
		$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cand));
		if (!empty($exists)) { $tables['courses'] = $cand; break; }
	}

	// Alumni table detection: try prefixed then plain
	$candidates_alumni = [ $wpdb->prefix . 'alumni', 'alumni' ];
	foreach ($candidates_alumni as $cand) {
		$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cand));
		if (!empty($exists)) { $tables['alumni'] = $cand; break; }
	}

	// User account table detection: try prefixed then plain
	$candidates_user = [ $wpdb->prefix . 'user', 'user' ];
	foreach ($candidates_user as $cand) {
		$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cand));
		if (!empty($exists)) { $tables['user'] = $cand; break; }
	}

	return $tables;
}

/**
 * Generate a random 6-digit password
 */
function alumnus_generate_password() {
	return str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Build a base username from first and last names using rules:
 * - Take only the first word of the first name
 * - Combine all words of the last name (remove spaces)
 * - Sanitize to alphanumeric and lowercase
 */
function alumnus_build_base_username($first_name, $last_name) {
	$first = trim((string)$first_name);
	$last  = trim((string)$last_name);
	$first_token = preg_split('/\s+/', $first);
	$first_token = isset($first_token[0]) ? $first_token[0] : '';
	$last_combined = preg_replace('/\s+/', '', $last);
	$base = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $first_token . $last_combined));
	if ($base === '') {
		$base = strtolower(wp_generate_password(6, false));
	}
	return $base;
}

/**
 * Generate a unique username for the user table. Appends a numeric suffix starting at 2 if needed.
 */
function alumnus_generate_unique_username($first_name, $last_name, $user_table) {
	global $wpdb;
	$base = alumnus_build_base_username($first_name, $last_name);
	$candidate = $base;
	$suffix = 2;
	while (true) {
		$exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$user_table} WHERE username = %s LIMIT 1", $candidate));
		if (!$exists) break;
		$candidate = $base . $suffix;
		$suffix++;
		if ($suffix > 1000) { // safety guard
			$candidate = $base . '-' . uniqid();
			break;
		}
	}
	return $candidate;
}

/**
 * Check if `username` column exists in the given user table
 */
function alumnus_user_table_has_username($user_table) {
	global $wpdb;
	$col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$user_table} LIKE %s", 'username'));
	return !empty($col);
}

/**
 * Generate the next unique alumni user_id for a given year using the format: {year}{count3}
 * - Count is per-year and zero-padded to 3 digits (e.g., 2024 + 1 => 2024001)
 * - Uses MAX suffix among IDs starting with the year to avoid reuse when deletions exist
 */
function alumnus_generate_yearly_user_id($year, $alumni_table) {
	global $wpdb;
	$year = (int) $year;
	// Try to find the max numeric suffix for the given year among IDs that start with the year
	$max_suffix = $wpdb->get_var($wpdb->prepare(
		"SELECT MAX(CAST(SUBSTRING(user_id, 5) AS UNSIGNED))
		 FROM {$alumni_table}
		 WHERE `year` = %d AND user_id LIKE %s",
		$year,
		$year . '%'
	));
	if ($max_suffix === null) {
		// Fallback: count existing rows for the year, then add 1
		$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$alumni_table} WHERE `year` = %d", $year));
		$next = $count + 1;
	} else {
		$next = ((int) $max_suffix) + 1;
	}
	// Build candidate ID
	$candidate = sprintf('%d%03d', $year, max(1, (int)$next));
	return $candidate;
}

/**
 * Add top-level admin menu
 */
function alumnus_admin_menu() {
	add_menu_page(
		__('Alumnus', 'alumnus'),
		__('Alumnus', 'alumnus'),
		'manage_options',
		'alumnus-add-alumni',
		'alumnus_render_admin_page',
		'dashicons-welcome-learn-more',
		30
	);
}
add_action('admin_menu', 'alumnus_admin_menu');

/**
 * Enqueue admin styles and scripts
 */
function alumnus_admin_scripts($hook) {
	if ($hook !== 'toplevel_page_alumnus-add-alumni') return;
	
	wp_add_inline_style('wp-admin', '
		.alumnus-table { margin-top: 20px; }
		.alumnus-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
		.alumnus-modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 5px; }
		.alumnus-close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
		.alumnus-close:hover { color: #000; }
		.alumnus-tabs { border-bottom: 1px solid #ccc; margin-bottom: 20px; }
		.alumnus-tab { display: inline-block; padding: 10px 20px; cursor: pointer; border: 1px solid transparent; margin-bottom: -1px; }
		.alumnus-tab.active { border: 1px solid #ccc; border-bottom-color: white; background: white; }
		.alumnus-tab-content { display: none; }
		.alumnus-tab-content.active { display: block; }
		.alumnus-password-display { background: #ffffcc; padding: 5px 10px; border-radius: 3px; font-family: monospace; font-weight: bold; }
		.alumnus-json-textarea { width: 100%; min-height: 200px; font-family: monospace; }
	');
	
	wp_add_inline_script('jquery', '
		jQuery(document).ready(function($) {
			$(".alumnus-tab").click(function() {
				var tab = $(this).data("tab");
				$(".alumnus-tab").removeClass("active");
				$(".alumnus-tab-content").removeClass("active");
				$(this).addClass("active");
				$("#tab-" + tab).addClass("active");
			});
			
			$(".edit-course-btn").click(function() {
				var id = $(this).data("id");
				var name = $(this).data("name");
				$("#edit_course_id").val(id);
				$("#edit_course_name").val(name);
				$("#editCourseModal").show();
			});
			
			$(".delete-course-btn").click(function() {
				var id = $(this).data("id");
				if (confirm("Are you sure you want to delete this course?")) {
					$("#delete_course_id").val(id);
					$("#deleteCourseForm").submit();
				}
			});
			
			$(".edit-alumni-btn").click(function() {
				var id = $(this).data("id");
				var courseId = $(this).data("course");
				var firstName = $(this).data("firstname");
				var lastName = $(this).data("lastname");
				var year = $(this).data("year");
				
				$("#edit_alumni_id").val(id);
				$("#edit_alumni_course_id").val(courseId);
				$("#edit_alumni_first_name").val(firstName);
				$("#edit_alumni_last_name").val(lastName);
				$("#edit_alumni_batch_year").val(year);
				$("#editAlumniModal").show();
			});
			
			$(".delete-alumni-btn").click(function() {
				var id = $(this).data("id");
				if (confirm("Are you sure you want to delete this alumni record?")) {
					$("#delete_alumni_id").val(id);
					$("#deleteAlumniForm").submit();
				}
			});
			
			$(".alumnus-close").click(function() {
				$(".alumnus-modal").hide();
			});
			
			$(window).click(function(event) {
				if ($(event.target).hasClass("alumnus-modal")) {
					$(".alumnus-modal").hide();
				}
			});
		});
	');
}
add_action('admin_enqueue_scripts', 'alumnus_admin_scripts');

/**
 * Handle form submissions
 */
function alumnus_handle_post() {
	if (!is_admin() || !current_user_can('manage_options') || !isset($_POST['alumnus_action'])) return;
	
	$tables = alumnus_get_table_names();
	global $wpdb;
	
	// Add Course
	if ($_POST['alumnus_action'] === 'add_course') {
		check_admin_referer('alumnus_add_course');
		$course_id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
		$course_name = isset($_POST['course_name']) ? sanitize_text_field(wp_unslash($_POST['course_name'])) : '';
		
		if ($course_id <= 0 || $course_name === '') {
			add_settings_error('alumnus', 'course_empty', __('Course ID and name are required.', 'alumnus'), 'error');
			return;
		}
		
		$exists_id = $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$tables['courses']} WHERE course_id = %d LIMIT 1", $course_id));
		if ($exists_id) {
			add_settings_error('alumnus', 'course_id_exists', __('Course ID already exists.', 'alumnus'), 'error');
			return;
		}
		
		$inserted = $wpdb->insert($tables['courses'], ['course_id' => $course_id, 'course' => $course_name], ['%d', '%s']);
		if ($inserted === false) {
			add_settings_error('alumnus', 'course_insert_fail', sprintf(__('Failed to add course. DB error: %s', 'alumnus'), esc_html($wpdb->last_error)), 'error');
		} else {
			add_settings_error('alumnus', 'course_insert_ok', __('Course added successfully.', 'alumnus'), 'updated');
		}
	}
	
	// Edit Course
	if ($_POST['alumnus_action'] === 'edit_course') {
		check_admin_referer('alumnus_edit_course');
		$course_id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
		$course_name = isset($_POST['course_name']) ? sanitize_text_field(wp_unslash($_POST['course_name'])) : '';
		
		if ($course_name === '') {
			add_settings_error('alumnus', 'course_empty', __('Course name is required.', 'alumnus'), 'error');
			return;
		}
		
		$updated = $wpdb->update($tables['courses'], ['course' => $course_name], ['course_id' => $course_id], ['%s'], ['%d']);
		if ($updated === false) {
			add_settings_error('alumnus', 'course_update_fail', __('Failed to update course.', 'alumnus'), 'error');
		} else {
			add_settings_error('alumnus', 'course_update_ok', __('Course updated successfully.', 'alumnus'), 'updated');
		}
	}
	
	// Delete Course
	if ($_POST['alumnus_action'] === 'delete_course') {
		check_admin_referer('alumnus_delete_course');
		$course_id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
		$deleted = $wpdb->delete($tables['courses'], ['course_id' => $course_id], ['%d']);
		add_settings_error('alumnus', 'course_delete_ok', __('Course deleted successfully.', 'alumnus'), 'updated');
	}
	
	// Add Alumni
	if ($_POST['alumnus_action'] === 'add_alumni') {
		check_admin_referer('alumnus_add_alumni');
		$course_id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
		$first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
		$last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
		$batch_year = isset($_POST['batch_year']) ? intval($_POST['batch_year']) : 0;
		
		$errors = [];
		if ($course_id <= 0) $errors[] = __('Course is required.', 'alumnus');
		if ($first_name === '' || $last_name === '') $errors[] = __('First and last name are required.', 'alumnus');
		if ($batch_year < 1900 || $batch_year > date('Y')) $errors[] = __('Invalid batch year.', 'alumnus');
		
		if (!empty($errors)) {
			foreach ($errors as $e) add_settings_error('alumnus', 'alumni_error', $e, 'error');
			return;
		}
		
		$plain_password = alumnus_generate_password();
		$password_hash = function_exists('wp_hash_password') ? wp_hash_password($plain_password) : password_hash($plain_password, PASSWORD_DEFAULT);
		
		// Get alumni table columns to ensure we only insert fields that exist
		$alumni_columns = $wpdb->get_results("SHOW COLUMNS FROM {$tables['alumni']}");
		$alumni_column_names = array_column($alumni_columns, 'Field');
        
			// Prepare alumni data; generate user_id per-year and attempt insert with retry on collision
			$alumni_data_core = [
				'year' => $batch_year,
				'course_id' => $course_id,
				'firstname' => $first_name,
				'lastname' => $last_name,
				'email' => '',
				'contact_info' => 0,
				'bio_note' => ''
			];
			// Remove fields that don't exist in the table
			$alumni_data_core = array_filter($alumni_data_core, function($key) use ($alumni_column_names) {
				return in_array($key, $alumni_column_names);
			}, ARRAY_FILTER_USE_KEY);

			$max_attempts = 10;
			$attempt = 0;
			$alumni_id = '';
			$insert_alumni = false;
			while ($attempt < $max_attempts) {
				$attempt++;
				$candidate_id = alumnus_generate_yearly_user_id($batch_year, $tables['alumni']);
				$alumni_data = array_merge(['user_id' => $candidate_id], $alumni_data_core);
				// Build formats (treat user_id as string)
				$alumni_formats = [];
				foreach (array_keys($alumni_data) as $key) {
					$alumni_formats[] = in_array($key, ['year', 'course_id', 'contact_info']) ? '%d' : '%s';
				}
				$insert_alumni = $wpdb->insert($tables['alumni'], $alumni_data, $alumni_formats);
				if ($insert_alumni !== false) {
					$alumni_id = $candidate_id;
					break; // success
				}
				// If duplicate key, retry; otherwise, fail
				$err = (string) $wpdb->last_error;
				if (stripos($err, 'Duplicate') === false && stripos($err, 'duplicate') === false) {
					break;
				}
			}

			if ($insert_alumni === false) {
				add_settings_error('alumnus', 'alumni_insert_fail', sprintf(__('Failed to add alumni. Error: %s', 'alumnus'), esc_html($wpdb->last_error)), 'error');
				return;
			}
        
			// Compute username after alumni row is added
			$user_insert_data = [
				'user' => $alumni_id,
				'course_id' => $course_id,
				'year' => $batch_year,
				'password' => $password_hash,
			];
			$user_insert_formats = ['%s', '%d', '%d', '%s'];
		if (alumnus_user_table_has_username($tables['user'])) {
			$username = alumnus_generate_unique_username($first_name, $last_name, $tables['user']);
			$user_insert_data['username'] = $username;
			$user_insert_formats[] = '%s';
		}
		$insert_user = $wpdb->insert($tables['user'], $user_insert_data, $user_insert_formats);
		
		if ($insert_user === false) {
				$wpdb->delete($tables['alumni'], ['user_id' => $alumni_id], ['%s']);
			add_settings_error('alumnus', 'user_insert_fail', __('Failed to add user credentials.', 'alumnus'), 'error');
			return;
		}
		
		set_transient('alumnus_new_password_' . $alumni_id, $plain_password, 300);
		add_settings_error('alumnus', 'alumni_insert_ok', sprintf(__('Alumni added successfully. Password: <span class="alumnus-password-display">%s</span>', 'alumnus'), $plain_password), 'updated');
	}
	
	// Edit Alumni
	if ($_POST['alumnus_action'] === 'edit_alumni') {
		check_admin_referer('alumnus_edit_alumni');
		$alumni_id = isset($_POST['alumni_id']) ? intval($_POST['alumni_id']) : 0;
		$course_id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
		$first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
		$last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
		$batch_year = isset($_POST['batch_year']) ? intval($_POST['batch_year']) : 0;
		
		$wpdb->update($tables['alumni'], [
			'year' => $batch_year, 'course_id' => $course_id, 'firstname' => $first_name, 'lastname' => $last_name
		], ['user_id' => $alumni_id], ['%d', '%d', '%s', '%s'], ['%s']);
		
		$wpdb->update($tables['user'], ['course_id' => $course_id, 'year' => $batch_year], ['user' => $alumni_id], ['%d', '%d'], ['%s']);
		add_settings_error('alumnus', 'alumni_update_ok', __('Alumni updated successfully.', 'alumnus'), 'updated');
	}
	
	// Delete Alumni
	if ($_POST['alumnus_action'] === 'delete_alumni') {
		check_admin_referer('alumnus_delete_alumni');
		$alumni_id = isset($_POST['alumni_id']) ? intval($_POST['alumni_id']) : 0;
		$wpdb->delete($tables['user'], ['user' => $alumni_id], ['%s']);
		$wpdb->delete($tables['alumni'], ['user_id' => $alumni_id], ['%s']);
		add_settings_error('alumnus', 'alumni_delete_ok', __('Alumni deleted successfully.', 'alumnus'), 'updated');
	}
	
	// Bulk Import JSON
	if ($_POST['alumnus_action'] === 'bulk_import') {
		check_admin_referer('alumnus_bulk_import');
		$json_data = isset($_POST['json_data']) ? wp_unslash($_POST['json_data']) : '';
		$data = json_decode($json_data, true);
		
		if (json_last_error() !== JSON_ERROR_NONE) {
			add_settings_error('alumnus', 'json_error', __('Invalid JSON format.', 'alumnus'), 'error');
			return;
		}
		
		$success_count = 0;
		$error_count = 0;
		$passwords = [];
		
		foreach ($data as $index => $record) {
			$type = isset($record['type']) ? $record['type'] : '';
			
			if ($type === 'course') {
				$course_id = isset($record['course_id']) ? intval($record['course_id']) : 0;
				$course_name = isset($record['course_name']) ? sanitize_text_field($record['course_name']) : '';
				
				if ($course_id <= 0 || $course_name === '') {
					$error_count++;
					continue;
				}
				
				$exists = $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$tables['courses']} WHERE course_id = %d LIMIT 1", $course_id));
				if ($exists) {
					$error_count++;
					continue;
				}
				
				$inserted = $wpdb->insert($tables['courses'], ['course_id' => $course_id, 'course' => $course_name], ['%d', '%s']);
				if ($inserted) $success_count++; else $error_count++;
				
			} elseif ($type === 'alumni') {
				$alumni_id = isset($record['alumni_id']) ? (string)$record['alumni_id'] : '';
				$course_id = isset($record['course_id']) ? intval($record['course_id']) : 0;
				$first_name = isset($record['first_name']) ? sanitize_text_field($record['first_name']) : '';
				$last_name = isset($record['last_name']) ? sanitize_text_field($record['last_name']) : '';
				$batch_year = isset($record['batch_year']) ? intval($record['batch_year']) : 0;
				
				if ($course_id <= 0 || $first_name === '' || $last_name === '' || $batch_year < 1900) {
					$error_count++;
					continue;
				}
				
				// If alumni_id is empty/missing, generate per-year; else, honor provided value if not taken
				if ($alumni_id === '' || $alumni_id === '0') {
					$alumni_id = alumnus_generate_yearly_user_id($batch_year, $tables['alumni']);
				} else {
					$exists = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$tables['alumni']} WHERE user_id = %s LIMIT 1", $alumni_id));
					if ($exists) {
						$error_count++;
						continue;
					}
				}
				
				$plain_password = alumnus_generate_password();
				$password_hash = function_exists('wp_hash_password') ? wp_hash_password($plain_password) : password_hash($plain_password, PASSWORD_DEFAULT);
				
				// Get alumni table columns
				$alumni_columns = $wpdb->get_results("SHOW COLUMNS FROM {$tables['alumni']}");
				$alumni_column_names = array_column($alumni_columns, 'Field');
				
			$alumni_data = [
				'user_id' => $alumni_id, 'year' => $batch_year, 'course_id' => $course_id,
				'firstname' => $first_name, 'lastname' => $last_name, 'email' => '',
				'contact_info' => 0, 'bio_note' => ''
			];
				
				// Remove fields that don't exist
				$alumni_data = array_filter($alumni_data, function($key) use ($alumni_column_names) {
					return in_array($key, $alumni_column_names);
				}, ARRAY_FILTER_USE_KEY);
				
				$alumni_formats = [];
				foreach (array_keys($alumni_data) as $key) {
					$alumni_formats[] = in_array($key, ['year', 'course_id', 'contact_info']) ? '%d' : '%s';
				}
				
				$insert_alumni = $wpdb->insert($tables['alumni'], $alumni_data, $alumni_formats);

				// Prepare user insert with possible username
				$user_insert_data = [
					'user' => $alumni_id,
					'course_id' => $course_id,
					'year' => $batch_year,
					'password' => $password_hash,
				];
				$user_insert_formats = ['%s', '%d', '%d', '%s'];
				if (alumnus_user_table_has_username($tables['user'])) {
					$username = alumnus_generate_unique_username($first_name, $last_name, $tables['user']);
					$user_insert_data['username'] = $username;
					$user_insert_formats[] = '%s';
				}
				$insert_user = $wpdb->insert($tables['user'], $user_insert_data, $user_insert_formats);
				
				if ($insert_alumni && $insert_user) {
					$success_count++;
					$passwords[] = "ID {$alumni_id}: {$plain_password}";
				} else {
					$error_count++;
				}
			}
		}
		
		$message = sprintf(__('Import complete. Success: %d, Errors: %d', 'alumnus'), $success_count, $error_count);
		if (!empty($passwords)) {
			$message .= '<br><strong>Generated Passwords:</strong><br>' . implode('<br>', $passwords);
		}
		add_settings_error('alumnus', 'bulk_import_complete', $message, $error_count > 0 ? 'error' : 'updated');
	}
}
add_action('admin_init', 'alumnus_handle_post');

/**
 * Render admin page
 */
function alumnus_render_admin_page() {
	if (!current_user_can('manage_options')) {
		wp_die(__('You do not have sufficient permissions to access this page.'));
	}
	
	$tables = alumnus_get_table_names();
	global $wpdb;
	$courses = $wpdb->get_results("SELECT course_id, course FROM {$tables['courses']} ORDER BY course ASC");
	// Detect if username column exists to include it in list
	$has_username = alumnus_user_table_has_username($tables['user']);
	if ($has_username) {
		$alumni_list = $wpdb->get_results("
			SELECT a.user_id, a.firstname, a.lastname, a.year, a.course_id, c.course, u.username
			FROM {$tables['alumni']} a
			LEFT JOIN {$tables['courses']} c ON a.course_id = c.course_id
			LEFT JOIN {$tables['user']} u ON u.user = a.user_id
			ORDER BY a.user_id ASC
		");
	} else {
		$alumni_list = $wpdb->get_results("
			SELECT a.user_id, a.firstname, a.lastname, a.year, a.course_id, c.course 
			FROM {$tables['alumni']} a
			LEFT JOIN {$tables['courses']} c ON a.course_id = c.course_id
			ORDER BY a.user_id ASC
		");
	}
	
	echo '<div class="wrap">';
	echo '<h1>' . esc_html__('Alumnus Manager', 'alumnus') . '</h1>';
	settings_errors('alumnus');
	
	// Tabs
	echo '<div class="alumnus-tabs">';
	echo '<span class="alumnus-tab active" data-tab="courses">Courses</span>';
	echo '<span class="alumnus-tab" data-tab="alumni">Alumni</span>';
	echo '<span class="alumnus-tab" data-tab="import">Bulk Import</span>';
	echo '</div>';
	
	// Courses Tab
	echo '<div id="tab-courses" class="alumnus-tab-content active">';
	echo '<h2>Add Course</h2>';
	echo '<form method="post">';
	wp_nonce_field('alumnus_add_course');
	echo '<input type="hidden" name="alumnus_action" value="add_course" />';
	echo '<table class="form-table"><tr><th><label for="course_id">Course ID</label></th>';
	echo '<td><input name="course_id" id="course_id" type="number" class="small-text" required /></td></tr>';
	echo '<tr><th><label for="course_name">Course Name</label></th>';
	echo '<td><input name="course_name" id="course_name" type="text" class="regular-text" required /></td></tr></table>';
	submit_button('Add Course');
	echo '</form>';
	
	echo '<h2>Courses List</h2>';
	if (!empty($courses)) {
		echo '<table class="wp-list-table widefat fixed striped alumnus-table">';
		echo '<thead><tr><th>ID</th><th>Course Name</th><th>Actions</th></tr></thead><tbody>';
		foreach ($courses as $course) {
			echo '<tr><td>' . esc_html($course->course_id) . '</td>';
			echo '<td>' . esc_html($course->course) . '</td>';
			echo '<td><button class="button edit-course-btn" data-id="' . esc_attr($course->course_id) . '" data-name="' . esc_attr($course->course) . '">Edit</button> ';
			echo '<button class="button delete-course-btn" data-id="' . esc_attr($course->course_id) . '">Delete</button></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p>No courses found.</p>';
	}
	echo '</div>';
	
	// Alumni Tab
	echo '<div id="tab-alumni" class="alumnus-tab-content">';
	echo '<h2>Add Alumni</h2>';
	echo '<form method="post">';
	wp_nonce_field('alumnus_add_alumni');
	echo '<input type="hidden" name="alumnus_action" value="add_alumni" />';
	echo '<table class="form-table">';
	// User ID is now auto-generated based on Year + per-year count
	echo '<tr><th>User ID</th><td><em>Will be generated after save (format: Year + sequential number, e.g., 2024001)</em></td></tr>';
	echo '<tr><th><label for="course_id_alumni">Course</label></th><td>';
	if (!empty($courses)) {
		echo '<select name="course_id" id="course_id_alumni" required><option value="">Select a course</option>';
		foreach ($courses as $course) {
			echo '<option value="' . esc_attr($course->course_id) . '">' . esc_html($course->course) . '</option>';
		}
		echo '</select>';
	} else {
		echo '<em>No courses yet. Add a course first.</em>';
	}
	echo '</td></tr>';
	echo '<tr><th><label for="first_name">First Name</label></th><td><input name="first_name" id="first_name" type="text" class="regular-text" required /></td></tr>';
	echo '<tr><th><label for="last_name">Last Name</label></th><td><input name="last_name" id="last_name" type="text" class="regular-text" required /></td></tr>';
	echo '<tr><th><label for="batch_year">Year</label></th><td><input name="batch_year" id="batch_year" type="number" min="1900" max="' . esc_attr(date('Y')) . '" class="small-text" required /></td></tr>';
	echo '</table>';
	if (!empty($courses)) submit_button('Add Alumni');
	echo '</form>';
	
	echo '<h2>Alumni List</h2>';
	if (!empty($alumni_list)) {
		echo '<table class="wp-list-table widefat fixed striped alumnus-table">';
		echo '<thead><tr><th>User ID</th><th>Name</th>' . ($has_username ? '<th>Username</th>' : '') . '<th>Course</th><th>Year</th><th>Default Password</th><th>Actions</th></tr></thead><tbody>';
		foreach ($alumni_list as $alumni) {
			$password = get_transient('alumnus_new_password_' . $alumni->user_id);
			echo '<tr><td>' . esc_html($alumni->user_id) . '</td>';
			echo '<td>' . esc_html($alumni->firstname . ' ' . $alumni->lastname) . '</td>';
			if ($has_username) { echo '<td>' . esc_html($alumni->username) . '</td>'; }
			echo '<td>' . esc_html($alumni->course) . '</td>';
			echo '<td>' . esc_html($alumni->year) . '</td>';
			echo '<td>' . ($password ? '<span class="alumnus-password-display">' . esc_html($password) . '</span>' : '<em>Not available</em>') . '</td>';
			echo '<td><button class="button edit-alumni-btn" data-id="' . esc_attr($alumni->user_id) . '" data-course="' . esc_attr($alumni->course_id) . '" data-firstname="' . esc_attr($alumni->firstname) . '" data-lastname="' . esc_attr($alumni->lastname) . '" data-year="' . esc_attr($alumni->year) . '">Edit</button> ';
			echo '<button class="button delete-alumni-btn" data-id="' . esc_attr($alumni->user_id) . '">Delete</button></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p>No alumni found.</p>';
	}
	echo '</div>';
	
	// Import Tab
	echo '<div id="tab-import" class="alumnus-tab-content">';
	echo '<h2>Bulk Import JSON</h2>';
	echo '<p>Import courses and alumni in JSON format. Each record should have a "type" field ("course" or "alumni").</p>';
	echo '<h3>Example JSON Format:</h3>';
	echo '<pre>[
	{
		"type": "course",
		"course_id": 1,
		"course_name": "Computer Science"
	},
	{
		"type": "alumni",
		"course_id": 1,
		"first_name": "John",
		"last_name": "Doe",
		"batch_year": 2020
		// alumni_id is optional; if omitted, it will be generated as {year}{count}
	}
]</pre>';
	echo '<form method="post">';
	wp_nonce_field('alumnus_bulk_import');
	echo '<input type="hidden" name="alumnus_action" value="bulk_import" />';
	echo '<textarea name="json_data" class="alumnus-json-textarea" required></textarea>';
	submit_button('Import JSON');
	echo '</form>';
	echo '</div>';
	
	echo '</div>'; // wrap
	
	// Edit Course Modal
	echo '<div id="editCourseModal" class="alumnus-modal">';
	echo '<div class="alumnus-modal-content">';
	echo '<span class="alumnus-close">&times;</span>';
	echo '<h2>Edit Course</h2>';
	echo '<form method="post">';
	wp_nonce_field('alumnus_edit_course');
	echo '<input type="hidden" name="alumnus_action" value="edit_course" />';
	echo '<input type="hidden" name="course_id" id="edit_course_id" />';
	echo '<table class="form-table"><tr><th><label for="edit_course_name">Course Name</label></th>';
	echo '<td><input name="course_name" id="edit_course_name" type="text" class="regular-text" required /></td></tr></table>';
	submit_button('Update Course');
	echo '</form></div></div>';
	
	// Delete Course Form
	echo '<form id="deleteCourseForm" method="post" style="display:none;">';
	wp_nonce_field('alumnus_delete_course');
	echo '<input type="hidden" name="alumnus_action" value="delete_course" />';
	echo '<input type="hidden" name="course_id" id="delete_course_id" />';
	echo '</form>';
	
	// Edit Alumni Modal
	echo '<div id="editAlumniModal" class="alumnus-modal">';
	echo '<div class="alumnus-modal-content">';
	echo '<span class="alumnus-close">&times;</span>';
	echo '<h2>Edit Alumni</h2>';
	echo '<form method="post">';
	wp_nonce_field('alumnus_edit_alumni');
	echo '<input type="hidden" name="alumnus_action" value="edit_alumni" />';
	echo '<input type="hidden" name="alumni_id" id="edit_alumni_id" />';
	echo '<table class="form-table">';
	echo '<tr><th><label for="edit_alumni_course_id">Course</label></th><td><select name="course_id" id="edit_alumni_course_id" required>';
	foreach ($courses as $course) {
		echo '<option value="' . esc_attr($course->course_id) . '">' . esc_html($course->course) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th><label for="edit_alumni_first_name">First Name</label></th><td><input name="first_name" id="edit_alumni_first_name" type="text" class="regular-text" required /></td></tr>';
	echo '<tr><th><label for="edit_alumni_last_name">Last Name</label></th><td><input name="last_name" id="edit_alumni_last_name" type="text" class="regular-text" required /></td></tr>';
	echo '<tr><th><label for="edit_alumni_batch_year">Year</label></th><td><input name="batch_year" id="edit_alumni_batch_year" type="number" min="1900" max="' . esc_attr(date('Y')) . '" class="small-text" required /></td></tr>';
	echo '</table>';
	submit_button('Update Alumni');
	echo '</form></div></div>';
	
	// Delete Alumni Form
	echo '<form id="deleteAlumniForm" method="post" style="display:none;">';
	wp_nonce_field('alumnus_delete_alumni');
	echo '<input type="hidden" name="alumnus_action" value="delete_alumni" />';
	echo '<input type="hidden" name="alumni_id" id="delete_alumni_id" />';
	echo '</form>';
}

?>
