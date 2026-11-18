# Plugin releases

Ready-to-upload builds of the `db-custom-blocks` plugin.

| File | Version | Built from |
|---|---|---|
| `db-custom-blocks-3.72.0.zip` | 3.72.0 | `main` at `1d5c139` |

To install: WP Admin → Plugins → Add New → Upload Plugin → choose the zip → **Replace current with uploaded**. After installing, Plugins shows the version above and Domain Brothers → Launch checks shows the commit.

Each zip is built with `tools/build-release.sh` (which writes `db-custom-blocks/release.json`, the build record inside the zip) followed by zipping the `db-custom-blocks/` folder from that commit.
