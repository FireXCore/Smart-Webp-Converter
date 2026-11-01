#!/usr/bin/env python3
"""Verify the full WordPress.org 1.2.1 GlotPress catalog, including plugin metadata."""
from pathlib import Path
import json
import re

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "smart-webp-converter" / "smart-webp-converter.php"
TRANSLATIONS = ROOT / "translations"
php = SOURCE.read_text(encoding="utf-8")

gettext_pattern = re.compile(r"\b(?:__|esc_html__|esc_attr__)\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'smart-webp-converter'\s*\)")
gettext_ids = {m.group(1).replace("\\'", "'") for m in gettext_pattern.finditer(php)}
metadata_pattern = re.compile(r"^ \* (Description|Plugin URI|Author|Author URI): (.+)$", re.M)
metadata = {m.group(1): m.group(2).strip() for m in metadata_pattern.finditer(php)}
assert len(gettext_ids) == 64, f"Expected 64 gettext strings; got {len(gettext_ids)}"
assert set(metadata) == {"Description", "Plugin URI", "Author", "Author URI"}
originals = gettext_ids | set(metadata.values())
assert len(originals) == 68, f"Expected 68 GlotPress original strings; got {len(originals)}"

def messages(filename):
    result = {}
    found = None
    for line in (TRANSLATIONS / filename).read_text(encoding="utf-8").splitlines():
        if line.startswith("msgid "):
            found = json.loads(line[6:])
        elif line.startswith("msgstr ") and found is not None:
            value = json.loads(line[7:])
            if found:
                assert found not in result, f"Duplicate msgid in {filename}: {found}"
                result[found] = value
            found = None
    return result

pot = messages("smart-webp-converter.pot")
po = messages("smart-webp-converter-fa_IR.po")
assert set(pot) == originals, f"POT/source mismatch: missing={originals-set(pot)}, stale={set(pot)-originals}"
assert set(po) == originals, f"PO/source mismatch: missing={originals-set(po)}, stale={set(po)-originals}"
assert all(not v for v in pot.values()), "POT translations must remain blank"
assert all(v.strip() for v in po.values()), "Every PO entry must have a draft translation"
assert all(po[x] == x for x in ("FireXCore", metadata["Plugin URI"], metadata["Author URI"])), "Brand and URLs must stay unchanged"
assert sum(any("\u0600" <= c <= "\u06ff" for c in po[x]) for x in gettext_ids) >= 64, "Expected Persian translations for all gettext entries"
print(f"PASS: {len(gettext_ids)} source PHP gettext strings")
print(f"PASS: {len(metadata)} additional translatable plugin-header metadata fields")
print(f"PASS: {len(originals)} unique original strings, matching public GlotPress Development fa_IR")
print("PASS: POT and Persian PO complete, all values nonempty, brand and URLs preserved")
print("NOTE: Draft requires fluent human review before GlotPress submission and PTE/GTE approval")
