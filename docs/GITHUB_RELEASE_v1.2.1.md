# Smart WebP Converter 1.2.1 — Stable

**Official WordPress.org release:** https://wordpress.org/plugins/smart-webp-converter/

This is the first stable **GitHub Release** corresponding to the already-published WordPress.org **1.2.1** release. The attached plugin ZIP is obtained from the official WordPress.org distribution endpoint and verified against the tested Git source commit, rather than packaged from later unrelated repository documentation changes.

## Highlights

- **Original-preserving WebP sidecars by default.** Uploaded JPEG/PNG attachments retain their original Media Library filenames and MIME types; compatible WebP sidecars are generated alongside them.
- **Optional original replacement, with an explicit deletion confirmation.** This mode permanently deletes the original *for new uploads* after successful WebP conversion. Keep it disabled unless backups and staging tests are complete.
- **Conversion visibility.** Responsive WordPress administration dashboard shows real image-conversion statistics and per-media conversion status.
- **Media Library support.** Inspect generated WebP files, and explicitly regenerate a missing WebP sidecar for an existing attachment without deleting its source.
- **Better compatibility.** WordPress attachment images and core image blocks can emit responsive `<picture>` markup with a working original-image fallback. Animated GIFs are skipped.
- **Upgrade continuity.** Automated tests verified retention of six option values and existing statistics when upgrading from public 1.1.0.
- **Self-contained UI.** Admin styles ship locally and do not require remote Chart.js or external scripts.

## Install

Download **`smart-webp-converter-1.2.1.zip`** below, or install directly from [WordPress.org](https://wordpress.org/plugins/smart-webp-converter/). In WordPress use **Plugins → Add New → Upload Plugin**.

The accompanying **`SHA256SUMS`** file provides the checksum for this exact attachment.

**Requirements:** WordPress 5.8+, PHP 7.4+; server-side GD or Imagick WebP support for conversion. WordPress.org is the authoritative channel for normal WordPress plugin updates.

## Known limitations and important safety notes

- **Replace Original is destructive** and affects future uploads only; files deleted by earlier settings cannot be restored by this plugin.
- Existing media are not automatically bulk-converted. Raw HTML URLs, CSS backgrounds, direct file links, site icons and unknown CDN URL rewrites are not modified.
- Animated GIFs are not converted; true lossless WebP requires suitable Imagick support.
- WooCommerce, CDN/offload, and Multisite-specific acceptance remain tracked in [Issue #2](https://github.com/FireXCore/Smart-Webp-Converter/issues/2).
- Persian translation has been contributed to WordPress.org Development GlotPress, but it requires human review and approval; see [Issue #10](https://github.com/FireXCore/Smart-Webp-Converter/issues/10).
- The published plugin declares **GPL v2 or later**; the repository retains a historic MIT LICENSE file. The licensing discrepancy remains documented for maintainer review.

## Verification

- WordPress.org SVN [`tags/1.2.1`](https://plugins.svn.wordpress.org/smart-webp-converter/tags/1.2.1/) contains the published code.
- The official public distribution ZIP, SVN content, and image assets passed [independent read-only publication verification](https://github.com/FireXCore/Smart-Webp-Converter/actions/runs/41205983159).
- GitHub release source tag targets the previously tested plugin implementation commit **`f7d233403b6fbd35d67fcb2b977ab4c379d1b53c`**, not subsequent docs-only or translation-only commits.
