# Protected Downloads

Restrict files in the Media Library to certain roles and keep a log of who downloaded them.

Useful for intranet documents like policies or HR forms, where you need to know who already has the latest version.

## How it works

- Open a file in the Media Library and tick the roles under **Who can download**.
- Share the protected link (`/?pd_download=ID`) or use the shortcode:
  `[protected_download id="123"]HR policy 2026[/protected_download]`
- Logged-out visitors are sent to the login page. Users without one of the roles get a 403.
- Every download is written to `wp_pd_log` with the user and their role at that moment (IP is stored as a salted hash, not in plain text).
- **Media > Download report** shows recent downloads (who, role, when), the most downloaded files, and per file who downloaded it and who hasn't yet.

## Tables

- `wp_pd_rules`: which roles can download which attachment
- `wp_pd_log`: one row per download

Both are created with `dbDelta()` on activation and dropped on uninstall.

## Note

The plugin protects the download link. To block direct access to the file in `wp-content/uploads`, add a server rule (nginx `location` or `.htaccess`) for the folder where protected files are kept.
