# Smart WebP Converter — code audit (2026-10-31)

## Baseline and evidence

- Public WordPress plugin: 1.1.0, tested up to WordPress 6.7.10 at inspection time.
- Source audited: WordPress.org plugin SVN tag 1.1.0, `smart-webp-converter.php` (567 lines), including plugin readme and advertised features.
- GitHub `main` before this work: `97b89bd5ee3e3e443fc1ba0a3f9c49c83e6f35f7`. Contents: LICENSE, README.md, a binary `smart-webp-converter.zip`. PHP source was absent from the GitHub tree.
- The GitHub ZIP cannot be assumed byte-identical to the WordPress.org SVN release; automated binary equivalence was not established.
- No issues or pull requests existed in the GitHub repository at the time of inspection.

## Verified defects in published 1.1.0 source

| Priority | Source area | Defect | 1.2.0 response |
| --- | --- | --- | --- |
| Critical | `replace_original_file` | Removes original before regenerating media metadata; file URL/MIME/size references can become inconsistent after deleting original. | Replace on `wp_handle_upload` / sideload **before** WordPress registers attachment; verify output first, preserve input on failure. |
| High | `create_webp_file` | Sidecars stored in `webp-temp` are not registered or served in keep-original mode; subsequent cleanup may delete them. | Sidecars next to originals and thumbnails, `picture` fallback on WordPress-rendered images. |
| High | `imagewebp(...,-1)` | -1 is default GD quality, not lossless WebP. | Require Imagick WebP support for true lossless; never silently downgrade. |
| High | `process_image_conversion` | Repeated metadata events add repeated statistics and convert repeatedly. | One row per attachment and stable files; existing duplicate rows deduplicated in dashboard totals. |
| High | GIF handling | `imagecreatefromgif` extracts only a frame; animated images lose animation if replaced. | Skip animation; only single-frame GIF with Imagick. |
| Medium | `setup_stats_table` | Executes dbDelta on every plugin initialization. | One-time versioned schema upgrade. |
| Medium | `schedule_temp_cleanup` | Hooks `wp_cron` rather than the `firexcore_webp_cleanup_temp` scheduled event. | No temporary output, no cleanup task; legacy temp files deliberately untouched. |
| Medium | `serve_webp_with_cache` | Calls header() during attachment URL filters; invalid response semantics and may produce headers-sent warnings. | Removed; caching left to web server/CDN, where static images actually load. |
| Medium | `get_smart_suggestions` | Loads all WooCommerce products via `wc_get_products(['limit'=>-1])` only to count them. | Removed costly enumeration. |
| Medium | `enqueue_dashboard_scripts` | Remote CDN Chart.js plus potential missing assets in GitHub binary-only repo. | Dashboard uses accessible HTML without remote scripts. |
| Medium | Settings form | Unchecked checkboxes are absent from POST, so enabled settings may not disable. | Add hidden values for all boolean fields. |
| Medium | SEO metadata | File-derived alt text and overwriting alt data can be misleading. | Preserve existing alt, use only meaningful editorial titles. |
| Medium | Transparency | GD alpha/blending handling may flatten semi-transparent images. | Prefer image editor and Imagick; GD fallback delegated to WordPress. |
| Medium | Storage/collisions | Filenames in shared temp directory can collide and replace unrelated data. | Detect ownership of sidecars per attachment, prevent overwrite. |

## Design and compatibility choices

- All previous `firexcore_webp_*` options and the `webp_conversion_stats` table remain.
- New source in `smart-webp-converter/`, not a copy of unverified GitHub ZIP internals.
- No automatic mass conversion/deletion of existing media.
- No direct access to users' WordPress installations; production data is untouched.
- Known limitation: raw arbitrary HTML, CSS backgrounds, external/CDN-rewritten URLs are not globally rewritten.
- Metadata stripping is targeted to EXIF/IPTC/XMP; ICC profiles are not deliberately removed.
- The public WordPress.org listing remains at 1.1.0 until its SVN release is separately deployed.
- The original root MIT license and WordPress.org GPL plugin header differ; resolve intent before public package release.

## Verification

- `php -l` and isolated test harness on PHP 7.4, 8.2, 8.4.
- Test cases: non-destructive default, sidecar + thumbnail generation, repeated metadata, responsive picture fallback, core/image blocks, collision avoidance, scaled attachments, replacement safety, invalid source behavior, and preserving alt text.
- CI bundles `smart-webp-converter/` into an installable ZIP artifact.\n- Real WordPress integration passed on WordPress 6.7.10 and 7.1.4 (actual uploads, thumbnails, replacement MIME, and picture output).
- Required before WordPress.org publication: extended WordPress integration, WooCommerce image upload / frontend rendering, PHP GD and Imagick real-server tests, media offload/CDN compatibility review, RTL admin accessibility, plugin-check scans, image comparisons (alpha/color), restore/rollback dry-run, and regression on production-like staging.
