#!/usr/bin/env python3
"""A–H: cross-document contracts, not a substitute for Blueprint source."""

import re
import subprocess
import sys
from collections import Counter

from common import ROOT, cells, finding, read, run, section, tree_entries
from doc_rules import active_line, body, doc_paths, historical_name, numbered_rows, rows
from expectations import ADR_NUMBERS, ADR_TOPICS, BLUEPRINT_ITEMS, BUCKETS, CANDIDATES, DEAD_MODULE_NAMES, DEAD_STATUS_NAMES, DEAD_TABLE_NAMES, DOC_FILES, EVENTS, EVENT_COUNT, EVENT_RENAMES, EVENT_SUFFIXES, GATE_H_TABLE_COUNT, MENUS, MODULE_COUNT, NON_EVENT_CLASSES, QUEUES, ROLES, RUNBOOKS, STATUS_AXES, TABLELESS_MODULES
from verify_schema import declarations, ownership


def check():
    errors = []

    def fail(path, number, message):
        errors.append((path, number, message))

    schema = read(DOC_FILES["09"])
    orders = "\n".join(line for _, line in declarations(schema)["orders"]["body"])
    # A: compare exact sets, preserving duplicate detection with Counter.
    for axis, expected in STATUS_AXES.items():
        constraints = re.findall(rf"CHECK \({axis} IN \(([^)]+)\)\)", orders)
        if len(constraints) != 1 or Counter(re.findall(r"'([^']+)'", constraints[0])) != Counter(expected):
            fail(DOC_FILES["09"], 1, f"A: CHECK {axis} differs")
        for path in (DOC_FILES["01"], "docs/02-business-flow-customer-features.md", DOC_FILES["11"]):
            matches = []
            for number, line in enumerate(read(path).splitlines(), 1):
                row = cells(line)
                if row and row[0] == f"`{axis}`":
                    enums = [cell.strip("`").split(" · ") for cell in row[1:] if " · " in cell]
                    matches.extend(enums)
            if len(matches) != 1 or Counter(matches[0]) != Counter(expected):
                fail(path, 1, f"A: enum {axis} differs")

    # B: every scenario must retain its mapping and real nonempty file.
    mapped = [(row[1], row[2].strip("`")) for _, row in rows(read(DOC_FILES["11"]), "Sebelas runbook wajib") if len(row) == 4 and row[0].isdigit()]
    if mapped != list(RUNBOOKS):
        fail(DOC_FILES["11"], 1, "B: runbook mappings differ")
    for _, name in RUNBOOKS:
        path = "docs/runbooks/" + name
        if not (ROOT / path).is_file() or not read(path).strip():
            fail(path, 1, "B: required runbook missing/empty")

    # C: the register owns names; references elsewhere are allowed (§8.4).
    register = [row[0].strip("`") for _, row in rows(read(DOC_FILES["12"]), "Katalog event") if row[0].startswith("`")]
    if len(register) != EVENT_COUNT or Counter(register) != Counter(EVENTS):
        fail(DOC_FILES["12"], 1, "C: event register differs from Doc 12 expectation")
    event_pattern = re.compile(r"\b[A-Z][a-z]+(?:[A-Z][a-z0-9]+)+\b")
    exceptions = {row[0].strip("`") for _, row in rows(read(DOC_FILES["12"]), "Peta DomainException") if row[0].startswith("`")}
    dead = DEAD_TABLE_NAMES | DEAD_MODULE_NAMES | DEAD_STATUS_NAMES
    for path in doc_paths():
        for number, original in enumerate(read(path).splitlines(), 1):
            line = active_line(path, number, original)
            for match in event_pattern.finditer(line):
                name = match[0]
                # EVENT_LEXICAL_BOUNDARY is a guessed reconstruction: see the
                # expectations comment and README limits, not a silent waiver.
                explicit = re.search(r"\bevent\s+`?$", line[:match.start()], re.I)
                if (name.endswith(EVENT_SUFFIXES) or explicit) and name not in register:
                    # DOMAIN_EXCEPTION_NOT_EVENT (Doc 12 §4.3/§6.2): names in
                    # the exception register aren't events, unless asserted so.
                    if not explicit and name in exceptions | NON_EVENT_CLASSES:
                        continue
                    # EVENT_RENAME_RECORD (Doc 11 §8.4): the old token itself
                    # must be explicitly historical, not merely on that line.
                    if name in EVENT_RENAMES and (historical_name(line, match.start(), match.end(), name) or re.search(r"sebelumnya tertulis\s+`?$", line[:match.start()])):
                        continue
                    fail(path, number, f"C: unregistered event {name}")
            # HTTP_PATH_SEGMENT (Doc 12 §2.2): a segment inside a backticked
            # `/path` is part of the HTTP contract, not a SQL identifier.
            paths = [span.span() for span in re.finditer(r"`/[^`]*`", line)]
            for old in sorted(dead):
                # IDENTIFIER_BOUNDARY (Doc 12 §2.2): stock-reservations is an
                # HTTP path segment, not the retired SQL identifier reservations.
                for match in re.finditer(rf"(?<![\w-]){re.escape(old)}(?![\w-])", line):
                    if any(start < match.start() and match.end() < end for start, end in paths):
                        continue
                    if not historical_name(line, match.start(), match.end(), old):
                        fail(path, number, f"D/A: retired active name {old}")
            # Dead values are axis-specific: 'draft' remains valid for a PO.
            for axis, allowed in STATUS_AXES.items():
                for match in re.finditer(rf"\b{axis}\s*=\s*['`]?([a-z_]+)", line):
                    if match[1] not in allowed and not historical_name(line, match.start(1), match.end(1), match[1]):
                        fail(path, number, f"A: invalid {axis} value {match[1]}")
            for match in re.finditer(r"ADR-(\d{3})\s*\(([^)]+)\)", line):
                own = int(match[1])
                topic = match[2].lower()
                # INLINE_ADR_TOPIC_LABEL (Doc 11 §3, skill): compare labels
                # beginning with a distinguishing keyword, not explanatory
                # sentences such as ADR-005 (intent expiry follows gateway).
                for other, words in ADR_TOPICS.items():
                    if other != own and any(re.match(rf"{re.escape(word)}(?:[^\w-]|$)", topic) for word in words) and not any(re.match(rf"{re.escape(word)}(?:[^\w-]|$)", topic) for word in ADR_TOPICS.get(own, ())):
                        fail(path, number, f"E: ADR-{own:03} inline topic belongs to ADR-{other:03}")

    # E: sourced coverage mappings and inventories, not remembered totals.
    adrs = list((ROOT / "docs/adr").glob("ADR-*.md"))
    numbers = [int(p.name[4:7]) for p in adrs if re.match(r"ADR-\d{3}-", p.name)]
    if Counter(numbers) != Counter(ADR_NUMBERS) or len(adrs) != len(ADR_NUMBERS):
        fail("docs/adr", 1, "E: ADR inventory must be 001–012 exactly")
    for number, keywords in ADR_TOPICS.items():
        files = [p for p in adrs if p.name.startswith(f"ADR-{number:03}-")]
        if len(files) == 1 and not any(word in files[0].read_text().splitlines()[0].lower() for word in keywords):
            fail(files[0].relative_to(ROOT), 1, "E: ADR title topic differs")
    for title, path in (("Peta ADR Blueprint", DOC_FILES["11"]), ("Peta Definition of Done Blueprint", "docs/03-sprint-plan.md")):
        if numbered_rows(read(path), title) != list(BLUEPRINT_ITEMS):
            fail(path, 1, f"E: ten mappings missing in {title}")
    sprint = read("docs/03-sprint-plan.md")
    mapped_tests = set()
    for match in re.finditer(r"Blueprint §16 baris (\d+)(?:[–-](\d+))?", sprint):
        mapped_tests.update(range(int(match[1]), int(match[2] or match[1]) + 1))
    for number in BLUEPRINT_ITEMS:
        # TEST_ROW_RANGE (Doc 03 D-19): 9–10 is an explicit two-item mapping.
        if number not in mapped_tests:
            fail("docs/03-sprint-plan.md", 1, f"E: Blueprint test {number} unmapped")
    candidates = body(DOC_FILES["11"], "Enam \"next document candidates\"")
    for name in CANDIDATES:
        if f"`{name}`" not in candidates:
            fail(DOC_FILES["11"], 1, f"E: candidate mapping missing {name}")
    queue_register = body(DOC_FILES["11"], "Nama antrian Horizon")
    queue_lines = [line for line in queue_register.splitlines() if line.startswith("Tujuh dan hanya tujuh:")]
    if len(queue_lines) != 1 or Counter(re.findall(r"`([a-z]+)`", queue_lines[0])) != Counter(QUEUES):
        fail(DOC_FILES["11"], 1, "E: canonical queue register differs")
    for name in QUEUES + BUCKETS:
        if f"`{name}`" not in read(DOC_FILES["01"]):
            fail(DOC_FILES["01"], 1, f"E: canonical infrastructure name missing {name}")
    menu_rows = [row for _, row in rows(read("docs/06-admin-dashboard-requirements.md"), "Peta penuh 14 grup") if row[0] in MENUS]
    if Counter(row[0] for row in menu_rows) != Counter(MENUS.keys()):
        fail("docs/06-admin-dashboard-requirements.md", 1, "E: menu groups differ")
    for role in ROLES:
        # ROLE_READ_ONLY_LABEL (Doc 06 §1): analyst's display label includes
        # read-only; the seeded role code itself remains exactly analyst.
        if not re.search(rf"\| \*\*{role}(?: \(read-only\))?\*\*", read("docs/06-admin-dashboard-requirements.md")):
            fail("docs/06-admin-dashboard-requirements.md", 1, f"E: role missing {role}")

    # H: exactly one owner, exact Doc 01 §3.3 mirror; tree cannot claim a
    # different module's table, even when that module only appears in comments.
    owners, total = ownership(schema)
    if total != GATE_H_TABLE_COUNT:
        fail(DOC_FILES["09"], 1, "H: approved table count differs")
    if len(owners.keys() - {"Lintas modul"} | TABLELESS_MODULES) != MODULE_COUNT:
        fail(DOC_FILES["09"], 1, "E/H: domain module inventory differs")
    owner_by_table = {table: module for module, tables in owners.items() for table in tables}
    mirrored = {}
    for number, row in rows(read(DOC_FILES["01"]), "Modul, tanggung jawab"):
        if len(row) == 4 and row[0] in owners.keys() | TABLELESS_MODULES:
            if row[0] in mirrored:
                fail(DOC_FILES["01"], number, "H: duplicate module")
            mirrored[row[0]] = re.findall(r"`([a-z][a-z0-9_]*)`", row[2].split(" — ", 1)[0])
    for module in owners.keys() - {"Lintas modul"} | TABLELESS_MODULES:
        if Counter(mirrored.get(module, [])) != Counter(owners.get(module, [])) or module not in mirrored:
            fail(DOC_FILES["01"], 1, f"H: ownership mirror differs for {module}")
    current = None
    for number, line in section(read(DOC_FILES["01"]), "Layout monorepo"):
        directory = re.search(r"(?:├──|└──) ([A-Za-z]+)/", line)
        if directory:
            current = directory[1] if directory[1] in mirrored else None
        if current and "#" in line:
            # TREE_FOREIGN_REFERENCE (Doc 01 §3.1, Doc 11 §8.5): this exact
            # phrase attributes media to Content, not Sourcing. All other
            # parenthesized table names remain checked.
            comment = line.split("#", 1)[1].replace("lampiran = baris media polimorfik milik modul Content, bukan tabel request_attachments", "lampiran polimorfik")
            for table in re.findall(r"\b[a-z][a-z0-9_]*\b", comment):
                if table in owner_by_table and owner_by_table[table] != current:
                    fail(DOC_FILES["01"], number, f"H: {current} tree claims {table} owned by {owner_by_table[table]}")

    for script, args in (("list_dead_cites.py", []), ("scan_unicode.py", [str(p) for p in doc_paths()])):
        result = subprocess.run([sys.executable, str(ROOT / "scripts/doc-auditors" / script), *args], cwd=ROOT, capture_output=True, text=True)
        if result.returncode:
            print(result.stdout, end="")
            fail("scripts/doc-auditors/" + script, 1, "F/G: delegated check failed")
    for path, number, message in errors:
        finding(path, number, message)
    if not errors:
        print("LULUS — semua pemeriksaan A–H bersih")
    return int(bool(errors))


if __name__ == "__main__":
    sys.exit(run(check))
