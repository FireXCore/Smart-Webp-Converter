# WordPress.org 1.2.0 release handoff

Official plugin: https://wordpress.org/plugins/smart-webp-converter/
Public SVN: https://plugins.svn.wordpress.org/smart-webp-converter/
Developer profile: https://profiles.wordpress.org/firexcore/
Release-gate issue: https://github.com/FireXCore/Smart-Webp-Converter/issues/2

## Status

GitHub `v1.2.0-rc.1` exists as a **pre-release**, not a WordPress.org release.
SVN currently lists tags 1.0.0, 1.0.1, 1.0.2, and 1.1.0 (checked on 2026-10-31).

The GitHub preflight workflow prepares an SVN-compatible `trunk/` payload with the main PHP and readme at the top level. It reads public SVN metadata, compares files, and uploads a review archive and checksum. It never authenticates or commits.

## Security and permissions

A public WordPress.org developer profile with the plugin listed does not independently prove active SVN commit authorization. Confirm that the exact username appears among authorized committers. SVN usernames are case-sensitive.

WordPress.org publishes a separate, generated SVN credential under the account's **Account & Security > SVN credentials** area. Never paste that credential in a GitHub issue, chat, shell history, repository file, or screenshot.

If deployment is later approved, use GitHub Actions encrypted secrets or a protected environment for `SVN_USERNAME` and `SVN_PASSWORD`, never the main website password. Require manual approval and a tested production tag.

## Release gate

Before any SVN write, complete the issue #2 staging, WooCommerce and data-safety acceptance steps. Confirm a backup/rollback procedure and resolve license documentation. Recheck plugin version and readme stable tag, both currently declaring 1.2.0. Keep `Replace Original` disabled during initial production rollout.

Publication must update `trunk/` and create `tags/1.2.0/` through `svn copy`, preserving repository history. The top level of `trunk` should contain `readme.txt` and `smart-webp-converter.php`, not another enclosing `smart-webp-converter/` directory. SVN uploads should contain source files, **not ZIP archives**.

Reference: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/

## Publication not performed

The preflight is intentionally read-only. Neither GitHub Release Candidate artifacts nor the profile screenshot grant permission to publish a WordPress.org update. The WordPress.org live listing remains independent from GitHub.
