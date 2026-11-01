# Smart WebP Converter — Persian translation draft (fa_IR)

This folder contains a **proposed** Persian translation based on the published WordPress.org 1.2.1 source and verified directly against the public Development (trunk) GlotPress catalog (68 originals).

| File | Purpose |
| --- | --- |
| `smart-webp-converter.pot` | Complete English original strings (64 PHP gettext + 4 translatable plugin-header fields) |
| `smart-webp-converter-fa_IR.po` | Editable, complete Persian PHP-string translation draft |
| `readme-fa_IR.md` | Human-readable Persian draft of the release readme, to be translated through the separate **Stable Readme** GlotPress project |
| `check_translations.py` | Checks PO/POT against 64 gettext strings plus 4 translatable plugin-header fields |

The GitHub Actions **Translation catalogs QA** workflow validates the PO and compiles `smart-webp-converter-fa_IR.mo` for local inspection.

## Publish at WordPress.org (requires account)

1. A fluent Persian speaker **must review** the translation. The WordPress.org Polyglots team explicitly prohibits importing unreviewed machine translations.
2. Sign in to https://translate.wordpress.org/projects/wp-plugins/smart-webp-converter/ with a WordPress.org account.
3. Open Persian (fa_IR) → **Development (trunk)**. The public GlotPress Stable project was still showing 38 older strings as of 2026-11-01, while Development has all 68 originals. Scroll to **Import Translations**, upload `smart-webp-converter-fa_IR.po`, and import as **Waiting** unless you have PTE/GTE approval rights.
4. Review and approve using the Persian locale's PTE or GTE. Importing as a normal contributor does **not** publish the strings: they remain Waiting until approved.
5. In the separate **Stable Readme** project, use `readme-fa_IR.md` as reviewed source text. Individual GlotPress original strings for the readme may be split differently; this Markdown file is not a GlotPress-importable PO for readme strings.
6. After translations become **Current**, WordPress.org builds the locale's language pack when its approval thresholds are met. Translation packs are separate from the plugin's SVN release.

The plugin already calls gettext with text domain `smart-webp-converter`. Adding a PO file to GitHub does not upload it to translate.wordpress.org, nor does it change the public WordPress.org SVN tag.

## Local-only test

Install the compiled MO file at:

`wp-content/languages/plugins/smart-webp-converter-fa_IR.mo`

Then switch the WordPress site/user interface to Persian and inspect all plugin settings and warnings. This is for **review**, not a substitute for an approved WordPress.org language pack.

## Status

- Translation draft covers **68 / 68** currently published GlotPress Development (trunk) originals: **64 PHP gettext strings** and four metadata strings (description, author, author URL and plugin URL). The URLs and brand name intentionally retain their original values.
- No WordPress.org GlotPress import or locale-editor approval has been performed by this repository workflow.
- The readme translation is a prose review draft, not a WordPress.org Stable Readme import file.

## 2026-11-01 live source audit

Public GlotPress [Development (trunk) → Persian](https://translate.wordpress.org/projects/wp-plugins/smart-webp-converter/dev/fa/default/) showed 68 original strings: 62 Untranslated and 6 Waiting, no Current. The GitHub Actions source audit separately downloaded the public untranslated/waiting sets, compared message IDs against the previously prepared 64 gettext strings, and confirmed **exactly four** additional plugin-header originals: plugin description, author name, author URI and plugin URI; **zero** of the 64 original gettext strings were absent from GlotPress. Evidence: https://github.com/FireXCore/Smart-Webp-Converter/actions/runs/41275315220 .

This file is a **draft for fluent human review**, not a verified human-approved translation. The six Waiting items may be independent contributions; do not overwrite or claim them as ours. Publishing to GlotPress is separate from a GitHub merge and requires an authenticated WordPress.org account and applicable reviewer permissions.
