=== Smart WebP Converter ===
Contributors: firexcore
Tags: webp, images, performance, optimization, media
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert newly uploaded images to WebP without silently invalidating media-library paths.

== Description ==

Smart WebP Converter converts JPEG/PNG images and supported single-frame GIF images on new uploads.

By default the original stays intact. WebP sidecars are placed alongside the original and generated sizes. When WordPress renders attachment images or core/image blocks, a picture element supplies WebP and retains the original as browser fallback.

The optional Replace Original mode converts before an attachment is created, so WordPress builds its image metadata and thumbnails from the new WebP. This mode removes the old file and must be backed up/tested before enabling.

Other features:
* Quality control and optional metadata stripping.
* True lossless only with an ImageMagick installation supporting WebP.
* Native WordPress settings permissions/nonces.
* Conversion dashboard using locally rendered HTML/CSS (no remote scripts).
* Existing attachment alt text preserved; only meaningful editorial titles fill empty alt text.
* Existing database statistics and `firexcore_webp_*` settings retained from version 1.1.0.

Limitations and safeguards:
* New uploads are processed. Explicit regeneration of attachment metadata can create sidecars for existing images; no automatic bulk pass is run.
* Animated GIFs are skipped to preserve animation. GIFs require Imagick even when single-frame.
* Large images above 40 million pixels are skipped.
* Raw HTML image URLs and CSS background images are not automatically rewritten.
* Attachment images hosted through a CDN using a different base URL may not receive a picture source. Originals remain functional.
* Legacy images from older releases (including webp-temp files) are not deleted or migrated automatically.
* Lossless requires Imagick; when unavailable, lossless requests are skipped rather than silently using lossy conversion.
* Requires PHP 7.4+ (version 1.1.0 required PHP 7.2+). Hosts on PHP 7.2/7.3 must upgrade PHP before installing this update.
* Test replacement on staging with a backup first. It changes new upload filenames and MIME types.
* Smoke integration has passed on WordPress 6.7.10 and 7.1.4. WooCommerce, multisite and CDN variants still require testing.

== Installation ==

1. Install the plugin as a directory named smart-webp-converter.
2. Activate it and open Smart WebP Converter > Settings.
3. Keep Replace Original disabled unless the site has been backed up and tested.
4. Upload a new JPEG or PNG, then verify the WebP file and attachment output.

== Upgrade Notice ==

= 1.2.0 =
Major safety and lifecycle correction. Requires PHP 7.4+. Existing options, files and conversion records are retained. No automatic migration or deletion of legacy webp-temp contents. The former dashboard's remote Chart.js chart/suggestions and ineffective temporary-cache-header hooks have been removed.

== Changelog ==

= 1.2.0 =
* Convert before media registration when original replacement is enabled.
* Generate durable sidecar WebP files for original and generated image sizes.
* Deliver WebP via picture markup for attachment images and core/image blocks.
* Preserve animated GIFs, existing alt text, and color profiles.
* Avoid false lossless claims and duplicate conversion statistics.
* Prevent repeated schema work and remove ineffective cron/cache-header logic.
* Remove external Chart.js dependency and expensive full WooCommerce product enumeration.
* Correct checkbox persistence, settings sanitization, and release documentation.
* Preserve existing 1.1.0 option names and conversion statistics; new uploads require PHP 7.4+.
* Remove historical remote Chart.js and smart suggestions UI; image conversion and basic statistics remain.
