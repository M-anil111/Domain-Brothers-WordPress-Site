# Backup taken 2026-11-24 before applying the designer's 23 Nov changes

- `db-ui-fixes-1.2.1/`: the designer's "Domain Brothers UI Fixes" add-on exactly as it ran on beta
  (`wp-content/plugins/db-ui-fixes/`, version 1.2.1, edited by hand on 2026-11-23). Checksums matched the server.
- Server copy of both plugins (code only) and of the database: `wp-content/db-backups/vault-<random>/pre-claude-20261124T0230Z/`
  (`db-ui-fixes` 1.2.1 and `db-custom-blocks` 3.150.44, 1,470 files).
- The database is not backed up here: a dump must not be stored in `wp-content` (web-readable on this host) or in git
  (the options table holds API keys). Use a Hostinger backup (hPanel > Websites > beta > Backups).

Restore the add-on: copy `db-ui-fixes-1.2.1/` over `wp-content/plugins/db-ui-fixes/`.
Restore the plugin: copy the server folder's `db-custom-blocks/` over `wp-content/plugins/db-custom-blocks/`.

2026-11-24: all backup folders under `wp-content/db-backups/` (the designer's two add-on backups and this one) were moved
into one randomly named `vault-…` folder. This host does not apply `.htaccess` there, so files at the old, guessable
paths were publicly downloadable. Find the folder name in the Hostinger File Manager; it is not written down here.
The database dump in `db/` is stored as `.sql.php` files that start with `<?php exit; ?>`, so a web request returns
nothing; strip that first line before importing.
