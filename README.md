# Smart WebP Converter for WordPress

Official plugin page: https://wordpress.org/plugins/smart-webp-converter/

## Source and releases

The editable WordPress plugin source is in [smart-webp-converter/](smart-webp-converter/). The earlier repository contained only a binary ZIP; the new source directory is authoritative for upcoming releases.

**Development version:** 1.2.0 (not yet published to WordPress.org).

**Current WordPress.org version:** 1.1.0. Updating this GitHub repository does **not** publish an update to the WordPress.org plugin directory. A separately reviewed WordPress SVN tag/release is required.

## What changed in 1.2.0

- Safe replacement before WordPress registers a new attachment, keeping media metadata and thumbnail generation consistent.
- Default non-destructive mode retains originals and creates permanent WebP files beside original images and generated thumbnails.
- Attachment images and `core/image` blocks get a `<picture>` WebP source with the original as a fallback.
- Avoids animated-GIF flattening, accidental image deletion on conversion failures, overwrite/collision errors, duplicate statistics, and false lossless claims.
- Existing settings keys and conversion-statistics table are preserved.
- No remote JavaScript is loaded. No repeated costly table creation or full WooCommerce product enumeration on ordinary requests.

## Install and test

1. Use a ZIP containing the **smart-webp-converter** directory with `smart-webp-converter.php` and `readme.txt` at its root.
2. Upload in WordPress Plugins > Add New > Upload Plugin.
3. Leave **Replace Original** disabled until a real staging-site test and backup have succeeded.
4. Verify JPEG/PNG uploads, thumbnails, front-end `<picture>` markup, and image URLs.

CI checks PHP 7.4, 8.2, and 8.4. WordPress integration tests passed on 6.7.10 and 7.1.4 with real media uploads. WooCommerce, CDN and production-like staging validation remain pending. GitHub Actions packages an installable ZIP artifact.

## Package locally

```sh
zip -r smart-webp-converter-1.2.0.zip smart-webp-converter/
```

## Limitations

Animated GIFs are not converted. Raw HTML image URLs, CSS background images, and unknown CDN URL rewrites are not touched. Legacy files in `webp-temp/` are deliberately left untouched because older versions may have registered them as original attachment files. Existing attachments are not migrated or bulk-converted on activation. Explicitly regenerating attachment metadata can create new sidecars.

Full detailed behavior is in [the plugin readme](smart-webp-converter/readme.txt). Security and compatibility findings are documented in [docs/SECURITY-AUDIT.md](docs/SECURITY-AUDIT.md).

## Licensing

The published WordPress plugin declares GPL v2 or later. The historical repository contains an MIT `LICENSE` file; license reconciliation for a public packaged release is a separate maintainer/legal review item, not an implicit relicensing through this update.
