# Theme files edited by hand on the server

DomainFolio is a commercial theme without a child theme. The files below were edited directly on beta between
2026-11-17 and 2026-11-20 (custom footer and its styles, a Google Fonts link, the payment-plan and team templates,
the theme stylesheets). Updating the theme overwrites them without warning, so they are kept here exactly as they
ran on beta on 2026-11-24 (checksums matched the server).

| File | Changed on beta |
|---|---|
| `footer.php` | 2026-11-17 (custom footer `db-ft`, its CSS and the Google Fonts link; the plugin strips the fonts) |
| `page-templates/payment-plan-setup.php` | 2026-11-17 |
| `page-templates/team-jaymehta.php` | 2026-11-17 |
| `assets/css/style.css`, `assets/css/bootstrap.min.css`, `style.css` | 2026-11-20 |

After a theme update: compare each file with its copy here and put the edits back (Hostinger File Manager, path
`wp-content/themes/DomainFolio/`). Better: move these changes into the `db-custom-blocks` plugin or a child theme,
so they no longer live in the theme folder. Do not edit the copies here to change the site; they are a record.
