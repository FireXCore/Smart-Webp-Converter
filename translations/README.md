# Smart WebP Converter — Persian translation draft (fa_IR)

This folder contains a **proposed** Persian translation based on the published WordPress.org 1.2.1 source.

| File | Purpose |
| --- | --- |
| `smart-webp-converter.pot` | Extracted English PHP strings |
| `smart-webp-converter-fa_IR.po` | Editable, complete Persian PHP-string translation draft |
| `readme-fa_IR.md` | Human-readable Persian draft of the release readme, to be translated through the separate **Stable Readme** GlotPress project |
| `check_translations.py` | Ensures PO/POT exactly cover all translatable PHP strings |

The GitHub Actions **Translation catalogs QA** workflow validates the PO and compiles `smart-webp-converter-fa_IR.mo` for local inspection.

## Publish at WordPress.org (requires account)

1. A fluent Persian speaker **must review** the translation. The WordPress.org Polyglots team explicitly prohibits importing unreviewed machine translations.
2. Sign in to https://translate.wordpress.org/projects/wp-plugins/smart-webp-converter/ with a WordPress.org account.
3. Open Persian (fa_IR) → **Stable (latest release)**. Scroll to **Import Translations**, upload `smart-webp-converter-fa_IR.po`, and import as **Waiting** unless you have PTE/GTE approval rights.
4. Review and approve using the Persian locale's PTE or GTE. Importing as a normal contributor does **not** publish the strings: they remain Waiting until approved.
5. In the separate **Stable Readme** project, use `readme-fa_IR.md` as reviewed source text. Individual GlotPress original strings for the readme may be split differently; this Markdown file is not a GlotPress-importable PO for readme strings.
6. After translations become **Current**, WordPress.org builds the locale's language pack when its approval thresholds are met. Translation packs are separate from the plugin's SVN release.

The plugin already calls gettext with text domain `smart-webp-converter`. Adding a PO file to GitHub does not upload it to translate.wordpress.org, nor does it change the public WordPress.org SVN tag.

## Local-only test

Install the compiled MO file at:

`wp-content/languages/plugins/smart-webp-converter-fa_IR.mo`

Then switch the WordPress site/user interface to Persian and inspect all plugin settings and warnings. This is for **review**, not a substitute for an approved WordPress.org language pack.

## Status

- Translation draft covers **64 / 64** current PHP gettext strings.
- No WordPress.org GlotPress import or locale-editor approval has been performed by this repository workflow.
- The readme translation is a prose review draft, not a WordPress.org Stable Readme import file.
