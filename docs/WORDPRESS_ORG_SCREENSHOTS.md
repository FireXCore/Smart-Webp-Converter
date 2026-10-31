# WordPress.org screenshot assets for 1.2.0

The WordPress.org plugin listing loads screenshots from the **SVN repository root `assets/` directory**, not `trunk/` or the installable plugin ZIP.

Screenshot captions are maintained in `smart-webp-converter/readme.txt` under `== Screenshots ==`.

## Images prepared from the maintainer's actual WordPress screenshots (2026-10-31)

- `assets/screenshot-1.png`: Smart WebP Converter settings on a WordPress site. Replace Original is OFF, so safe sidecar mode applies.
- `assets/screenshot-2.png`: WordPress Media Library details for `apple-touch-icon.png`. It correctly remains a PNG in safe sidecar mode, regardless of whether a separate WebP file was created.

These originals were provided outside this Git repository and are included in a separate screenshot ZIP handoff, rather than embedded into the installable plugin. The second screenshot predates the new WebP status field; therefore it must **not** be described as a screenshot showing the new field. Capture a new screenshot from an installation of the updated plugin to demonstrate the status field when available.

## Manual publishing in WordPress.org SVN

Copy `screenshot-1.png` and `screenshot-2.png` to the SVN root `assets/` directory, not into `trunk`. Do not replace unrelated banners and icons. In TortoiseSVN, use `Add` for new files and verify SVN `svn:mime-type=image/png` if necessary.

WordPress.org image assets may cache even after a successful SVN commit. The updated 1.2.0 code release is **separate** and remains blocked by Issue #2 until accepted. Screenshots alone do not publish a new plugin version.

Reference: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
