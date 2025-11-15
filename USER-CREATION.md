# WordPress User Creation on Alumni Add

When adding an Alumni via the Alumnus admin page, the plugin now also creates a corresponding WordPress user so they appear under Users in the WP Admin.

Details:
- Username: the numeric Alumni User ID (from the form).
- Email: a placeholder `alumni<USER_ID>@example.invalid` is used since the form does not collect email.
- Password:
  - If you enter a plain password (min 6 chars), it will be used for the WordPress user.
  - If you paste a hashed password (detected as a hash), a strong temporary password is generated for the WordPress user. You can reset it later.
- Role: `subscriber`.
- User Meta: `alumnus_user_id`, `alumnus_course_id`, `alumnus_year` stored for cross-reference.

Failure handling:
- If WordPress username already exists, the plugin rolls back the inserts in the custom `alumni` and `user` tables and shows an error.
- If WordPress user creation fails for any reason, the custom inserts are rolled back to keep data consistent.

Notes:
- Consider extending the form to collect a real email address if you plan to send notifications or allow password resets.
- You can change the default role by editing the `role` field passed to `wp_insert_user` in `add-alumni.php`.
