#!/usr/bin/env python3
"""Check that the Persian draft covers precisely the translatable 1.2.1 PHP catalog."""
from pathlib import Path
import json
import re

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "smart-webp-converter" / "smart-webp-converter.php"
TRANSLATIONS = ROOT / "translations"
pattern = re.compile(r"\b(?:__|esc_html__|esc_attr__)\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'smart-webp-converter'\s*\)")
php = SOURCE.read_text(encoding="utf-8")
ids = {match.group(1).replace("\\'", "'") for match in pattern.finditer(php)}
assert len(ids) >= 64, f"Expected at least 64 PHP strings, found {len(ids)}"

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
assert set(pot) == ids, f"POT/source mismatch: missing={ids-set(pot)}, stale={set(pot)-ids}"
assert set(po) == ids, f"PO/source mismatch: missing={ids-set(po)}, stale={set(po)-ids}"
assert all(not v for v in pot.values()), "POT must contain empty msgstr values"
assert all(v.strip() for v in po.values()), "Every Persian translation must be nonempty"
assert any("\u0600" <= c <= "\u06ff" for v in po.values() for c in v), "Expected Persian translation content"
print(f"PASS: {len(ids)} unique PHP gettext strings")
print("PASS: POT matches current PHP exactly")
print("PASS: Persian PO covers all PHP strings; all translations nonempty")
print("NOTE: Formal WordPress.org acceptance requires human fluent-speaker review and PTE/GTE approval")
