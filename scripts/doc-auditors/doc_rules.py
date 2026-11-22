"""Named, local historical-context rules; never waive an entire document."""

import re

from common import ROOT, cells, read, section
from expectations import ENGLISH_OBJECT_PHRASES, EVENT_RENAMES, RENAMES


def doc_paths():
    return sorted(path.relative_to(ROOT) for path in (ROOT / "docs").rglob("*.md"))


def rows(text, title):
    return [(number, cells(line)) for number, line in section(text, title) if cells(line)]


def active_line(path, number, line):
    # OLD_DOCUMENT_COLUMN (Doc 11 §1): only the explicitly obsolete column is
    # historical; the Blueprint and resolution columns remain fully checked.
    if str(path) == "docs/11-blueprint-reconciliation.md" and line.startswith("| B-"):
        row = cells(line)
        if len(row) == 5:
            line = " | ".join(row[:2] + row[3:])
    # RENAME_MAP_CELL (Doc 11 §4.1/§8.5, Doc 01 delta tables, Doc 10 §6.1):
    # a first-cell old name paired with its replacement is not an active use.
    row = cells(line)
    if len(row) >= 2:
        original = row[0]
        for old, new in RENAMES.items():
            if re.search(rf"\b{old}\b", row[0]) and re.search(rf"\b{re.escape(new)}\b", row[1]):
                row[0] = re.sub(rf"\b{old}\b", "", row[0])
            # MODULE_RENAME_COLUMN (Doc 11 §8.5) reverses the column order.
            if str(path) == "docs/11-blueprint-reconciliation.md" and row[0] == f"`{new}`" and row[1] == f"`{old}`":
                row[1] = ""
        if row[0] != original or "" in row:
            line = " | ".join(row)
    # OLD_STATUS_MAPPING_CELL (Doc 10 §6.1): only the left-hand operand of
    # this explicitly headed migration mapping table describes old values.
    if str(path) == "docs/10-migration-plan.md" and re.match(r"^\| `(?:order|fulfillment|procurement)_status = '", line):
        row = cells(line)
        line = " | ".join(row[1:])
    # SPRINT_TRACE_OLD_COLUMN (Doc 03 §13): the old sprint definition is
    # historical; the new Phase and explanatory columns remain checked.
    if str(path) == "docs/03-sprint-plan.md" and line.startswith("| §10.5 Sprint"):
        line = " | ".join(cells(line)[1:])
    # WRITTEN_MODULE_RENAME (Doc 11 §8.5): this exact list records old labels.
    line = line.replace("draft awal kami menulis `Quotes`, `Shipping`, `IntlLogistics`", "draft awal kami menulis label lama")
    # ADR_OLD_PIVOT_REASON (ADR-012 Konteks): exact rejected old-model phrases,
    # not an exemption for new active assertions anywhere in that section.
    line = line.replace("dua pivot buatan sendiri (`role_user`, `permission_role`)", "dua pivot buatan sendiri")
    line = line.replace("`role_user` + `permission_role` hanya bisa memberi hak lewat role", "pivot lama hanya bisa memberi hak lewat role")
    # WRITTEN_EVENT_RETRACTION (threat-model §5): exact dead-name list.
    line = line.replace("`ParcelShipped`, `PoBatchArrived`, dan `RefundApproved` adalah nama mati", "nama event lama")
    # ENGLISH_MENU_LABEL (Doc 06 §2.2): Shipping Labels is not module Shipping.
    line = line.replace("Shipping Labels", "label pengiriman")
    # PLAIN_ENGLISH_NOUN: only sourced literal phrases, never all unquoted
    # old names. An active declaration such as 'tabel quotes' must still fail.
    for phrase in ENGLISH_OBJECT_PHRASES:
        line = line.replace(phrase, "label non-SQL")
    return line


def historical_name(line, start, end, old):
    before, after = line[:start].rstrip('`* "'), line[end:].lstrip('`* "')
    # EXPLICIT_RENAME_ARROW (Doc 11 §4.1): only the old token before an arrow
    # or its explicit replacement is historical, not unrelated tokens nearby.
    replacement = (RENAMES | EVENT_RENAMES).get(old)
    if replacement and re.match(rf"[→⇒]\s*[`*]*{re.escape(replacement)}\b", after):
        return True
    # EXPLICIT_REBUTTAL (Doc 11 §8.8): local Indonesian rename/rejection words.
    if re.search(r"(?:rename|rename \+ perluasan modul|pengganti(?: pivot)?|dahulu|dulu(?: modul)?|bekas(?: label)?|bukan|bukan lagi|nama lama|modul lama|tabel lama|pivot lama|lama(?: \()?)[ `\"/]*$", before, re.I):
        return True
    if re.match(r"(?:lama\b|yang lama\b|hilang\b|diganti\b|tidak (?:ada|berlaku|dipakai|dibuat)\b|dihapus\b)", after, re.I):
        return True
    # PAIRED_RENAME (Doc 11 §4.4 B and Doc 09 §19): a slash-separated old pair
    # is historical only when the immediately following predicate retracts it.
    if re.match(r"(?:/`?[a-z_]+`?)*\s*(?:— tidak berlaku|tidak berlaku|hilang|\*\*diganti|diganti|\(.*?tidak berlaku)", after):
        return True
    # OLD_COLUMN_DELETION (Doc 11 B-3): exact explicit removal predicate.
    if old == "money_status" and re.match(r"kita = `payment_status` Blueprint \(nama dihapus\)", after):
        return True
    return False


def numbered_rows(text, title):
    return [int(row[0].split()[0]) for _, row in rows(text, title) if re.match(r"^\d+(?:\s|$)", row[0])]


def body(path, title):
    return "\n".join(line for _, line in section(read(path), title))
