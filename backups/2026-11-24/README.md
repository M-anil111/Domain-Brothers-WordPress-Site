# Backup taken 2026-11-24 before applying the designer's 23 Nov changes

- `db-ui-fixes-1.2.1/`: the designer's "Domain Brothers UI Fixes" add-on exactly as it ran on beta
  (`wp-content/plugins/db-ui-fixes/`, version 1.2.1, edited by hand on 2026-11-23). Checksums matched the server.
- Server copy of both plugins (code only, no data): `wp-content/db-backups/pre-claude-20261124T0230Z/`
  (`db-ui-fixes` 1.2.1 and `db-custom-blocks` 3.150.44, 1,470 files).
- The database is not backed up here: a dump must not be stored in `wp-content` (web-readable on this host) or in git
  (the options table holds API keys). Use a Hostinger backup (hPanel > Websites > beta > Backups).

Restore the add-on: copy `db-ui-fixes-1.2.1/` over `wp-content/plugins/db-ui-fixes/`.
Restore the plugin: copy the server folder's `db-custom-blocks/` over `wp-content/plugins/db-custom-blocks/`.
