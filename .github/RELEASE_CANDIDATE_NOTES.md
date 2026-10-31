# Smart WebP Converter 1.2.1 — Release Candidate 2

**This is a GitHub preview build, not the final WordPress.org release.**
WordPress.org remains on version 1.1.0 until separate maintainer publication and staging acceptance.

## Changes
- New WordPress admin dashboard and settings design with real conversion statistics and Media Library status.
- Destructive replacement now requires a separate explicit deletion confirmation on settings save.
- Real WordPress admin screenshots and branded WordPress.org assets are included in the validated CI SVN package.

- Convert before WordPress registers a media attachment when opting into original replacement.
- Safe default preserves original JPEG/PNG files and creates lasting WebP sidecars for full images and thumbnails.
- Render responsive WebP `picture` sources with original fallback for attachment images and core/image blocks.
- Avoid animated GIF flattening, overwriting unrelated sidecars, duplicate statistics, false lossless claims and expensive dashboard queries.
- Improve option persistence and remove external dashboard JavaScript.

## Media Library update
- Safe mode keeps the original PNG/JPEG in the Media Library; it produces a separate verified WebP sidecar.
- Attachment detail panels and the Media list show WebP generation status and an output link.
- A nonce-verified action generates missing sidecars for individual existing images, preserving original files.
- Site icons and other raw PNG URLs are deliberately not rewritten.
- Genuine WordPress.org screenshot handoff is described in `docs/WORDPRESS_ORG_SCREENSHOTS.md` and must be committed separately to SVN assets.

## Automated verification
- PHP lint and smoke tests on PHP 7.4, 8.2 and 8.4.
- Actual WordPress media upload, thumbnail and markup integration on WordPress 6.7.10 and 7.1.4.
- WordPress Plugin Check: 0 errors, 8 warnings relating to the existing custom statistics table direct queries/caching.

## Not yet accepted for production
- WooCommerce galleries, multisite, remote/offloaded media, CDN rewriting, animated and large-image matrix, color profile fidelity and production-like staging.
- Historical repository MIT `LICENSE` and WordPress.org GPLv2-or-later declarations require a maintainer-confirmed license approach before stable publication.
- Existing library is **not** bulk migrated and legacy `webp-temp` files are deliberately left intact.
- Replace Original remains opt-in and is destructive for **new uploads**. Keep it off without a full backup and staging verification.

## Install for staging only
Download `smart-webp-converter-1.2.1-rc.2.zip` from **Assets**, then use WordPress > Plugins > Add New > Upload Plugin. The ZIP contains the required `smart-webp-converter/` directory.

Release-gating checklist: https://github.com/FireXCore/Smart-Webp-Converter/issues/2
