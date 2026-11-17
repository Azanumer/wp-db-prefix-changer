# WP DB Prefix Changer

Safely rename the WordPress database table prefix — the step every security guide recommends but nobody wants to do by hand.

## What it does

- **Renames every table** with the current prefix in a single `RENAME TABLE` statement.
- **Fixes prefixed keys** — `option_name` rows in `wp_options` and `meta_key` rows in `wp_usermeta` (this is what keeps capabilities and user levels working after a rename).
- **Rewrites `wp-config.php`** — updates `$table_prefix` and saves a timestamped backup (`wp-config.php.bak-YYYYMMDD-HHMMSS`) first.
- **Dry-run preview** — shows every table rename and the exact counts of option/user-meta keys that will change before anything is touched.
- **Live-run gate** — the rename only runs after you type `I UNDERSTAND`, plus a backup reminder confirm dialog.

## Safety rules

- **Back up the database first.** This cannot be undone from WordPress (restore the backup, or rename the tables back and restore the `wp-config.php` backup).
- Refuses to run on **multisite** — subsites share tables this tool cannot safely rename.
- Refuses if any table already uses the target prefix, if the new prefix equals the old one, or if the new prefix is invalid (letters, digits and underscores only, must end with `_`).
- If `wp-config.php` is not writable it tells you so up front and, after the rename, warns you to edit `$table_prefix` by hand — otherwise the site breaks.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **Tools → DB Prefix Changer**, enter a new prefix (e.g. `wp_x7k9_`), preview, then run.

## Uninstall

Deleting the plugin removes its last-run record. Table names are not changed back — that is a separate, deliberate action.
