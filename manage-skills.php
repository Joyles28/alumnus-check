<?php
/**
 * Skills Manager - Admin page to add, edit, and delete skills
 * Version: 1.0.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Add submenu page for Skills Manager
 */
function alumnus_skills_admin_menu() {
    add_submenu_page(
        'alumnus-add-alumni',           // Parent slug (existing Alumnus menu)
        __('Manage Skills', 'alumnus'), // Page title
        __('Skills', 'alumnus'),        // Menu title
        'manage_options',               // Capability
        'alumnus-manage-skills',        // Menu slug
        'alumnus_render_skills_page'    // Callback function
    );
}
add_action('admin_menu', 'alumnus_skills_admin_menu');

/**
 * Enqueue admin styles and scripts for Skills Manager
 */
function alumnus_skills_admin_scripts($hook) {
    if ($hook !== 'alumnus_page_alumnus-manage-skills') return;
    
    wp_add_inline_style('wp-admin', '
        .skills-manager-wrap { margin: 20px 20px 40px; }
        .skills-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .skills-header h1 { margin: 0; font-size: 23px; font-weight: 400; line-height: 1.3; }
        .skills-actions-group { display: flex; gap: 10px; }
        
        /* Search and Filters */
        .skills-filters { background: #fff; padding: 15px 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
        .search-box { display: flex; gap: 10px; align-items: center; }
        .search-box input { padding: 8px 12px; width: 350px; border: 1px solid #8c8f94; border-radius: 3px; font-size: 14px; }
        .search-box input:focus { border-color: #2271b1; outline: none; box-shadow: 0 0 0 1px #2271b1; }
        
        /* Table Styling */
        .skills-table-container { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); overflow: hidden; }
        .skills-table { width: 100%; background: #fff; border: none; }
        .skills-table thead th { background: #f6f7f7; padding: 14px 12px; text-align: left; font-weight: 600; color: #1d2327; border-bottom: 1px solid #c3c4c7; font-size: 14px; }
        .skills-table tbody tr { transition: background-color 0.1s ease; }
        .skills-table tbody tr:hover { background: #f6f7f7; }
        .skills-table tbody td { padding: 14px 12px; border-bottom: 1px solid #dcdcde; color: #1d2327; font-size: 14px; }
        .skills-table tbody tr:last-child td { border-bottom: none; }
        .skill-name-cell { font-weight: 500; color: #2271b1; }
        .skill-id-cell { color: #646970; font-family: monospace; }
        .skill-actions { display: flex; gap: 12px; }
        .skill-actions a { text-decoration: none; font-weight: 500; transition: color 0.1s ease; }
        .skill-actions .edit { color: #2271b1; }
        .skill-actions .edit:hover { color: #135e96; }
        .skill-actions .delete { color: #d63638; }
        .skill-actions .delete:hover { color: #b32d2e; }
        
        /* Empty State */
        .skills-empty-state { text-align: center; padding: 60px 20px; color: #646970; }
        .skills-empty-state .dashicons { font-size: 80px; width: 80px; height: 80px; color: #c3c4c7; margin-bottom: 20px; }
        .skills-empty-state h3 { font-size: 18px; margin-bottom: 10px; color: #1d2327; }
        .skills-empty-state p { margin-bottom: 20px; }
        
        /* Modal Improvements */
        .alumnus-modal { display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(2px); }
        .alumnus-modal-content { background-color: #fff; margin: 5% auto; padding: 0; border: 1px solid #c3c4c7; width: 90%; max-width: 600px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .alumnus-modal-header { padding: 20px 24px; border-bottom: 1px solid #dcdcde; display: flex; justify-content: space-between; align-items: center; background: #f6f7f7; border-radius: 8px 8px 0 0; }
        .alumnus-modal-header h2 { margin: 0; font-size: 20px; font-weight: 600; color: #1d2327; }
        .alumnus-modal-body { padding: 24px; }
        .alumnus-modal-footer { padding: 16px 24px; border-top: 1px solid #dcdcde; text-align: right; background: #f6f7f7; border-radius: 0 0 8px 8px; display: flex; justify-content: flex-end; gap: 10px; }
        .alumnus-close { color: #646970; font-size: 24px; font-weight: normal; cursor: pointer; line-height: 1; transition: color 0.1s ease; background: none; border: none; padding: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
        .alumnus-close:hover { color: #d63638; }
        
        /* Form Styling */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #1d2327; font-size: 14px; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px; transition: border-color 0.1s ease; }
        .form-group input:focus, .form-group textarea:focus { border-color: #2271b1; outline: none; box-shadow: 0 0 0 1px #2271b1; }
        .form-group textarea { resize: vertical; min-height: 120px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
        .form-hint { font-size: 13px; color: #646970; margin-top: 6px; font-style: italic; }
        
        /* Pagination */
        .pagination { margin-top: 20px; text-align: center; padding: 15px 0; }
        .pagination a, .pagination span { padding: 8px 14px; margin: 0 3px; border: 1px solid #c3c4c7; display: inline-block; text-decoration: none; border-radius: 3px; transition: all 0.1s ease; color: #2271b1; font-weight: 500; }
        .pagination a:hover { background: #f6f7f7; border-color: #2271b1; }
        .pagination .current { background: #2271b1; color: #fff; border-color: #2271b1; }
        
        /* Notices */
        .notice-success { background: #d7f0db; border-left: 4px solid #00a32a; padding: 12px 16px; margin: 15px 0; border-radius: 0 4px 4px 0; }
        .notice-error { background: #fcf0f1; border-left: 4px solid #d63638; padding: 12px 16px; margin: 15px 0; border-radius: 0 4px 4px 0; }
        
    ');
    
    wp_add_inline_script('jquery', '
        jQuery(document).ready(function($) {
            // Open Add Single Skill Modal
            $("#add-skill-btn").click(function() {
                $("#add-skill-modal").show();
                $("#skill-name-input").val("");
                $("#skill-name-input").focus();
            });
            
            // Open Bulk Add Modal
            $("#bulk-add-skill-btn").click(function() {
                $("#bulk-add-skill-modal").show();
                $("#bulk-skills-input").val("");
                $("#bulk-skills-input").focus();
            });
            
            // Open Edit Modal
            $(".edit-skill-btn").click(function() {
                var skillId = $(this).data("id");
                var skillName = $(this).data("name");
                $("#edit-skill-id").val(skillId);
                $("#edit-skill-name").val(skillName);
                $("#edit-skill-modal").show();
            });
            
            // Close Modal
            $(".alumnus-close, .cancel-btn").click(function() {
                $(".alumnus-modal").hide();
            });
            
            // Close modal when clicking outside
            $(window).click(function(e) {
                if ($(e.target).hasClass("alumnus-modal")) {
                    $(".alumnus-modal").hide();
                }
            });
            
            // Delete Skill Confirmation
            $(".delete-skill-btn").click(function(e) {
                var skillName = $(this).data("name");
                return confirm("Are you sure you want to delete the skill: " + skillName + "?");
            });
        });
    ');
}
add_action('admin_enqueue_scripts', 'alumnus_skills_admin_scripts');

/**
 * Handle Add Skill Form Submission
 */
function alumnus_handle_add_skill() {
    if (!isset($_POST['alumnus_add_skill_nonce']) || !wp_verify_nonce($_POST['alumnus_add_skill_nonce'], 'alumnus_add_skill_action')) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    global $wpdb;
    $skill_name = isset($_POST['skill_name']) ? sanitize_text_field(trim($_POST['skill_name'])) : '';
    
    if (empty($skill_name)) {
        add_settings_error('alumnus_skills', 'empty_skill', __('Skill name cannot be empty.', 'alumnus'), 'error');
        return;
    }
    
    // Check if skill already exists
    $exists = $wpdb->get_var($wpdb->prepare("SELECT skill_id FROM skills WHERE skill = %s", $skill_name));
    
    if ($exists) {
        add_settings_error('alumnus_skills', 'duplicate_skill', __('This skill already exists.', 'alumnus'), 'error');
        return;
    }
    
    // Insert new skill
    $result = $wpdb->insert('skills', array('skill' => $skill_name), array('%s'));
    
    if ($result) {
        add_settings_error('alumnus_skills', 'skill_added', __('Skill added successfully!', 'alumnus'), 'success');
    } else {
        add_settings_error('alumnus_skills', 'skill_error', __('Error adding skill. Please try again.', 'alumnus'), 'error');
    }
}

/**
 * Handle Bulk Add Skills Form Submission
 */
function alumnus_handle_bulk_add_skills() {
    if (!isset($_POST['alumnus_bulk_add_skills_nonce']) || !wp_verify_nonce($_POST['alumnus_bulk_add_skills_nonce'], 'alumnus_bulk_add_skills_action')) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    global $wpdb;
    $skills_raw = isset($_POST['bulk_skills']) ? $_POST['bulk_skills'] : '';
    
    if (empty(trim($skills_raw))) {
        add_settings_error('alumnus_skills', 'empty_bulk_skills', __('Please enter at least one skill.', 'alumnus'), 'error');
        return;
    }
    
    // Parse skills by comma, semicolon, or newline
    $skills_array = preg_split('/[,;\n]+/', $skills_raw);
    $added_count = 0;
    $skipped_count = 0;
    $duplicate_skills = array();
    
    foreach ($skills_array as $skill) {
        $skill_name = sanitize_text_field(trim($skill));
        
        // Skip empty entries
        if (empty($skill_name)) {
            continue;
        }
        
        // Limit skill name length
        if (strlen($skill_name) > 100) {
            $skill_name = substr($skill_name, 0, 100);
        }
        
        // Check if skill already exists
        $exists = $wpdb->get_var($wpdb->prepare("SELECT skill_id FROM skills WHERE skill = %s", $skill_name));
        
        if ($exists) {
            $duplicate_skills[] = $skill_name;
            $skipped_count++;
            continue;
        }
        
        // Insert new skill
        $result = $wpdb->insert('skills', array('skill' => $skill_name), array('%s'));
        
        if ($result) {
            $added_count++;
        }
    }
    
    // Build success/error message
    if ($added_count > 0) {
        $message = sprintf(
            _n('%d skill added successfully!', '%d skills added successfully!', $added_count, 'alumnus'),
            $added_count
        );
        
        if ($skipped_count > 0) {
            $message .= ' ' . sprintf(
                _n('%d duplicate skill was skipped.', '%d duplicate skills were skipped.', $skipped_count, 'alumnus'),
                $skipped_count
            );
        }
        
        add_settings_error('alumnus_skills', 'bulk_skills_added', $message, 'success');
    } elseif ($skipped_count > 0) {
        add_settings_error('alumnus_skills', 'all_duplicates', __('All skills already exist. No new skills were added.', 'alumnus'), 'error');
    } else {
        add_settings_error('alumnus_skills', 'no_valid_skills', __('No valid skills were found to add.', 'alumnus'), 'error');
    }
}

/**
 * Handle Edit Skill Form Submission
 */
function alumnus_handle_edit_skill() {
    if (!isset($_POST['alumnus_edit_skill_nonce']) || !wp_verify_nonce($_POST['alumnus_edit_skill_nonce'], 'alumnus_edit_skill_action')) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    global $wpdb;
    $skill_id = isset($_POST['skill_id']) ? intval($_POST['skill_id']) : 0;
    $skill_name = isset($_POST['skill_name']) ? sanitize_text_field(trim($_POST['skill_name'])) : '';
    
    if ($skill_id <= 0 || empty($skill_name)) {
        add_settings_error('alumnus_skills', 'invalid_data', __('Invalid skill data.', 'alumnus'), 'error');
        return;
    }
    
    // Check if new name already exists (but not for the same skill)
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT skill_id FROM skills WHERE skill = %s AND skill_id != %d", 
        $skill_name, 
        $skill_id
    ));
    
    if ($exists) {
        add_settings_error('alumnus_skills', 'duplicate_skill', __('This skill name already exists.', 'alumnus'), 'error');
        return;
    }
    
    // Update skill
    $result = $wpdb->update(
        'skills',
        array('skill' => $skill_name),
        array('skill_id' => $skill_id),
        array('%s'),
        array('%d')
    );
    
    if ($result !== false) {
        add_settings_error('alumnus_skills', 'skill_updated', __('Skill updated successfully!', 'alumnus'), 'success');
    } else {
        add_settings_error('alumnus_skills', 'skill_error', __('Error updating skill. Please try again.', 'alumnus'), 'error');
    }
}

/**
 * Handle Delete Skill
 */
function alumnus_handle_delete_skill() {
    if (!isset($_GET['alumnus_delete_skill_nonce']) || !wp_verify_nonce($_GET['alumnus_delete_skill_nonce'], 'alumnus_delete_skill_' . $_GET['skill_id'])) {
        wp_die(__('Security check failed', 'alumnus'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to perform this action', 'alumnus'));
    }
    
    $skill_id = isset($_GET['skill_id']) ? intval($_GET['skill_id']) : 0;
    
    if ($skill_id <= 0) {
        add_settings_error('alumnus_skills', 'invalid_id', __('Invalid skill ID.', 'alumnus'), 'error');
        return;
    }
    
    global $wpdb;
    
    // Delete skill (CASCADE will automatically remove from alumni_skills)
    $result = $wpdb->delete('skills', array('skill_id' => $skill_id), array('%d'));
    
    if ($result) {
        add_settings_error('alumnus_skills', 'skill_deleted', __('Skill deleted successfully!', 'alumnus'), 'success');
    } else {
        add_settings_error('alumnus_skills', 'skill_error', __('Error deleting skill. Please try again.', 'alumnus'), 'error');
    }
    
    // Redirect to remove query params
    wp_redirect(admin_url('admin.php?page=alumnus-manage-skills'));
    exit;
}

/**
 * Process form submissions
 */
function alumnus_skills_process_actions() {
    if (isset($_POST['alumnus_add_skill'])) {
        alumnus_handle_add_skill();
    }
    
    if (isset($_POST['alumnus_bulk_add_skills'])) {
        alumnus_handle_bulk_add_skills();
    }
    
    if (isset($_POST['alumnus_edit_skill'])) {
        alumnus_handle_edit_skill();
    }
    
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['skill_id'])) {
        alumnus_handle_delete_skill();
    }
}
add_action('admin_init', 'alumnus_skills_process_actions');

/**
 * Render the Skills Manager admin page
 */
function alumnus_render_skills_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    global $wpdb;
    
    // Get search query
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    
    // Pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Build query
    $where = '';
    $params = array();
    if (!empty($search)) {
        $where = "WHERE skill LIKE %s";
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    
    // Get total count
    $total_sql = "SELECT COUNT(*) FROM skills $where";
    $total_count = !empty($params) 
        ? $wpdb->get_var($wpdb->prepare($total_sql, $params))
        : $wpdb->get_var($total_sql);
    
    // Get skills
    $sql = "SELECT skill_id, skill
            FROM skills
            $where
            ORDER BY skill_id ASC
            LIMIT %d OFFSET %d";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $skills = $wpdb->get_results($wpdb->prepare($sql, $params));
    
    $total_pages = ceil($total_count / $per_page);
    
    ?>
    <div class="wrap skills-manager-wrap">
        <div class="skills-header">
            <h1><?php echo esc_html__('Manage Skills', 'alumnus'); ?></h1>
            <div class="skills-actions-group">
                <button type="button" id="add-skill-btn" class="button button-primary">
                    <?php echo esc_html__('Add Skill', 'alumnus'); ?>
                </button>
                <button type="button" id="bulk-add-skill-btn" class="button button-secondary">
                    <?php echo esc_html__('Bulk Add', 'alumnus'); ?>
                </button>
            </div>
        </div>
        
        <?php settings_errors('alumnus_skills'); ?>
        
        <!-- Search and Filters -->
        <div class="skills-filters">
            <div class="search-box">
                <form method="get" style="display: flex; gap: 10px; align-items: center; width: 100%;">
                    <input type="hidden" name="page" value="alumnus-manage-skills">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Search skills...', 'alumnus'); ?>">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Search', 'alumnus'); ?></button>
                    <?php if (!empty($search)): ?>
                        <a href="<?php echo admin_url('admin.php?page=alumnus-manage-skills'); ?>" class="button"><?php echo esc_html__('Clear', 'alumnus'); ?></a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Skills Table -->
        <?php if (empty($skills)): ?>
            <div class="skills-table-container">
                <div class="skills-empty-state">
                    <span class="dashicons dashicons-welcome-learn-more"></span>
                    <h3><?php echo esc_html__('No Skills Found', 'alumnus'); ?></h3>
                    <p><?php echo esc_html__('Start by adding your first skill using the buttons above.', 'alumnus'); ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="skills-table-container">
                <table class="wp-list-table widefat fixed striped skills-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;"><?php echo esc_html__('ID', 'alumnus'); ?></th>
                            <th><?php echo esc_html__('Skill Name', 'alumnus'); ?></th>
                            <th style="width: 150px;"><?php echo esc_html__('Actions', 'alumnus'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($skills as $skill): ?>
                            <tr>
                                <td class="skill-id-cell">#<?php echo esc_html($skill->skill_id); ?></td>
                                <td class="skill-name-cell"><?php echo esc_html($skill->skill); ?></td>
                                <td class="skill-actions">
                                    <a href="#" class="edit edit-skill-btn" 
                                       data-id="<?php echo esc_attr($skill->skill_id); ?>"
                                       data-name="<?php echo esc_attr($skill->skill); ?>">
                                        <?php echo esc_html__('Edit', 'alumnus'); ?>
                                    </a>
                                    <a href="<?php echo wp_nonce_url(
                                        admin_url('admin.php?page=alumnus-manage-skills&action=delete&skill_id=' . $skill->skill_id),
                                        'alumnus_delete_skill_' . $skill->skill_id,
                                        'alumnus_delete_skill_nonce'
                                    ); ?>" 
                                       class="delete delete-skill-btn"
                                       data-name="<?php echo esc_attr($skill->skill); ?>">
                                        <?php echo esc_html__('Delete', 'alumnus'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php
                    $base_url = admin_url('admin.php?page=alumnus-manage-skills');
                    if (!empty($search)) {
                        $base_url .= '&s=' . urlencode($search);
                    }
                    
                    for ($i = 1; $i <= $total_pages; $i++):
                        if ($i === $current_page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo $base_url . '&paged=' . $i; ?>"><?php echo $i; ?></a>
                        <?php endif;
                    endfor;
                    ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Add Skill Modal -->
        <div id="add-skill-modal" class="alumnus-modal">
            <div class="alumnus-modal-content">
                <div class="alumnus-modal-header">
                    <h2><?php echo esc_html__('Add New Skill', 'alumnus'); ?></h2>
                    <span class="alumnus-close">&times;</span>
                </div>
                <form method="post" action="">
                    <?php wp_nonce_field('alumnus_add_skill_action', 'alumnus_add_skill_nonce'); ?>
                    <div class="alumnus-modal-body">
                        <div class="form-group">
                            <label for="skill-name-input"><?php echo esc_html__('Skill Name', 'alumnus'); ?> <span style="color: red;">*</span></label>
                            <input type="text" id="skill-name-input" name="skill_name" required maxlength="100">
                        </div>
                    </div>
                    <div class="alumnus-modal-footer">
                        <button type="button" class="button cancel-btn"><?php echo esc_html__('Cancel', 'alumnus'); ?></button>
                        <button type="submit" name="alumnus_add_skill" class="button button-primary"><?php echo esc_html__('Add Skill', 'alumnus'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Bulk Add Skills Modal -->
        <div id="bulk-add-skill-modal" class="alumnus-modal">
            <div class="alumnus-modal-content">
                <div class="alumnus-modal-header">
                    <h2><?php echo esc_html__('Bulk Add Skills', 'alumnus'); ?></h2>
                    <span class="alumnus-close">&times;</span>
                </div>
                <form method="post" action="">
                    <?php wp_nonce_field('alumnus_bulk_add_skills_action', 'alumnus_bulk_add_skills_nonce'); ?>
                    <div class="alumnus-modal-body">
                        <div class="form-group">
                            <label for="bulk-skills-input"><?php echo esc_html__('Skills', 'alumnus'); ?> <span style="color: red;">*</span></label>
                            <textarea id="bulk-skills-input" name="bulk_skills" required rows="10" placeholder="<?php echo esc_attr__('Enter skills separated by commas, semicolons, or new lines...', 'alumnus'); ?>"></textarea>
                            <p class="form-hint">
                                <?php echo esc_html__('Example: JavaScript, Python, Project Management, Data Analysis, Communication', 'alumnus'); ?>
                            </p>
                        </div>
                    </div>
                    <div class="alumnus-modal-footer">
                        <button type="button" class="button cancel-btn"><?php echo esc_html__('Cancel', 'alumnus'); ?></button>
                        <button type="submit" name="alumnus_bulk_add_skills" class="button button-primary"><?php echo esc_html__('Add Skills', 'alumnus'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Edit Skill Modal -->
        <div id="edit-skill-modal" class="alumnus-modal">
            <div class="alumnus-modal-content">
                <div class="alumnus-modal-header">
                    <h2><?php echo esc_html__('Edit Skill', 'alumnus'); ?></h2>
                    <span class="alumnus-close">&times;</span>
                </div>
                <form method="post" action="">
                    <?php wp_nonce_field('alumnus_edit_skill_action', 'alumnus_edit_skill_nonce'); ?>
                    <input type="hidden" id="edit-skill-id" name="skill_id">
                    <div class="alumnus-modal-body">
                        <div class="form-group">
                            <label for="edit-skill-name"><?php echo esc_html__('Skill Name', 'alumnus'); ?> <span style="color: red;">*</span></label>
                            <input type="text" id="edit-skill-name" name="skill_name" required maxlength="100">
                        </div>
                    </div>
                    <div class="alumnus-modal-footer">
                        <button type="button" class="button cancel-btn"><?php echo esc_html__('Cancel', 'alumnus'); ?></button>
                        <button type="submit" name="alumnus_edit_skill" class="button button-primary"><?php echo esc_html__('Save Changes', 'alumnus'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}