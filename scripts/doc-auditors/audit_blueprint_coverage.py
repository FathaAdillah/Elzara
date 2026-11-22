#!/usr/bin/env python3
"""Sourced COVER lists; direct Blueprint checks are explicitly opt-in."""

import os
import re
import sys
from collections import Counter
from pathlib import Path

from common import ROOT, cells, finding, read, run, section
from doc_rules import body, doc_paths, rows
from expectations import ADR_NUMBERS, BLUEPRINT_BUSINESS_PATHS, BLUEPRINT_PATHS, BLUEPRINT_TABLE_COUNT, BLUEPRINT_TABLE_EXCEPTIONS, BUCKETS, CANDIDATES, CONTRADICTIONS, DOC_FILES, EVENTS, MENUS, MENU_GROUP_COUNT, QUEUES, ROLES, RUNBOOKS, SCHEMA_TABLES, STATUS_AXES, SUBMENU_COUNT
from verify_schema import ownership


def blueprint_section(text, number):
    headings = list(re.finditer(r"^(#{1,6})\s+(\d+)(?:[.)])?\s+[^\n]+", text, re.M))
    matches = [m for m in headings if int(m[2]) == number]
    if len(matches) != 1:
        raise ValueError(f"Blueprint section {number} missing/duplicated")
    start = matches[0]
    end = next((m.start() for m in headings if m.start() > start.start() and len(m[1]) <= len(start[1])), len(text))
    return text[start.end():end]


def source_menus(text):
    result, current = {}, None
    for line in text.splitlines():
        row = cells(line)
        if len(row) >= 2 and row[0].strip("`*") in MENUS:
            group = row[0].strip("`*")
            if group in result:
                raise ValueError(f"Blueprint duplicate menu group {group}")
            result[group] = [s.strip() for s in row[1].split(",")]
            current = None
            continue
        heading = re.match(r"^#{1,6}\s+(?:4\.\d+[.)]?\s+)?(.+?)\s*$", line)
        if heading:
            group = heading[1].strip("`*")
            current = group if group in MENUS else None
            if current:
                if current in result:
                    raise ValueError(f"Blueprint duplicate menu group {current}")
                result[current] = []
        item = re.match(r"^\s*(?:[-*]|\d+[.)])\s+(.+?)\s*$", line)
        if current and item:
            result[current].append(item[1].strip("`*"))
    return result


def source_tables(text):
    names = []
    for line in text.splitlines():
        row = cells(line)
        candidate = row[0] if row else re.sub(r"^\s*(?:[-*]|\d+[.)])\s+", "", line)
        if re.fullmatch(r"\s*`?[a-z][a-z0-9_]*`?(?:\s*[,/]\s*`?[a-z][a-z0-9_]*`?)*\s*", candidate):
            names.extend(re.findall(r"[a-z][a-z0-9_]*", candidate))
    if len(names) != len(set(names)):
        raise ValueError("Blueprint duplicate table name")
    return set(names)


def check():
    errors, cover = [], []

    def fail(path, line, message):
        errors.append((path, line, message))

    def covers(label, path, text, expected):
        cover.append(label)
        for token in expected:
            if not re.search(rf"(?<![\w]){re.escape(token)}(?![\w])", text):
                fail(path, 1, f"COVER {label}: missing {token}")

    admin_path = "docs/06-admin-dashboard-requirements.md"
    admin = read(admin_path)
    documented = {}
    for number, row in rows(admin, "Peta penuh 14 grup"):
        if len(row) == 5 and row[0] in MENUS:
            if row[0] in documented:
                fail(admin_path, number, "S1 duplicate menu group")
            documented[row[0]] = [s.strip() for s in row[1].split(",")]
    owners, _ = ownership(read(DOC_FILES["09"]))
    tables = {table for names in owners.values() for table in names}
    endpoint_text = body(DOC_FILES["12"], "Blueprint §7")
    documented_paths = set(re.findall(r"(?<![\w/])(/[a-zA-Z0-9_/{}/-]+)(?=`|\s|\||$)", endpoint_text))
    # These are the hand-kept lists actually reconstructed from named sources.
    # Count their executions; never inherit the unavailable auditor's 29 claim.
    covers("menu groups", admin_path, body(admin_path, "Peta penuh 14 grup"), MENUS)
    for group, submenus in MENUS.items():
        covers("submenu " + group, admin_path, ", ".join(documented.get(group, [])), submenus.split(", "))
    covers("role bundles", admin_path, body(admin_path, "Role"), ROLES)
    covers("queues", DOC_FILES["01"], read(DOC_FILES["01"]), QUEUES)
    covers("buckets", DOC_FILES["01"], read(DOC_FILES["01"]), BUCKETS)
    covers("runbooks", DOC_FILES["11"], body(DOC_FILES["11"], "Sebelas runbook wajib"), [name for _, name in RUNBOOKS])
    covers("events", DOC_FILES["12"], body(DOC_FILES["12"], "Katalog event"), EVENTS)
    covers("candidate mappings", DOC_FILES["11"], body(DOC_FILES["11"], 'Enam "next document candidates"'), CANDIDATES)
    covers("endpoints", DOC_FILES["12"], endpoint_text, BLUEPRINT_PATHS)
    covers("schema inventory", DOC_FILES["09"], " ".join(sorted(tables)), SCHEMA_TABLES)
    for axis, values in STATUS_AXES.items():
        covers("status " + axis, DOC_FILES["11"], body(DOC_FILES["11"], "Empat sumbu status order"), values)
    covers("ADR files", "docs/adr", " ".join(p.name for p in (ROOT / "docs/adr").glob("ADR-*.md")), [f"ADR-{n:03}" for n in ADR_NUMBERS])

    blueprint = os.environ.get("ELZARA_BLUEPRINT_PATH")
    if blueprint:
        source_path = Path(blueprint)
        source = source_path.read_text(encoding="utf-8")
        menus = source_menus(blueprint_section(source, 4))
        required_tables = source_tables(blueprint_section(source, 6))
        paths = re.findall(r"(?:`|\s)(/[a-zA-Z0-9_/{}/-]+)(?=`|\s|\|)", blueprint_section(source, 7))
        if len(required_tables) != BLUEPRINT_TABLE_COUNT:
            fail(source_path, 1, f"S2 expected {BLUEPRINT_TABLE_COUNT} tables, got {len(required_tables)}")
        if Counter(paths) != Counter(BLUEPRINT_BUSINESS_PATHS):
            fail(source_path, 1, "S3 Blueprint business endpoint inventory differs")
    else:
        menus = {group: names.split(", ") for group, names in MENUS.items()}
        required_tables = SCHEMA_TABLES
        paths = BLUEPRINT_BUSINESS_PATHS
    if len(menus) != MENU_GROUP_COUNT or sum(map(len, menus.values())) != SUBMENU_COUNT:
        fail(blueprint or admin_path, 1, "S1 expected 14 groups / 87 submenus")
    if menus != documented:
        fail(admin_path, 1, "S1 verbatim group/submenu mapping differs")
    for table in sorted(required_tables - BLUEPRINT_TABLE_EXCEPTIONS - tables):
        fail(DOC_FILES["09"], 1, f"S2 missing table {table}")
    for name in BLUEPRINT_TABLE_EXCEPTIONS:
        if f"`{name}`" not in body(DOC_FILES["11"], "Nama modul") or f"`{name}`" not in body(DOC_FILES["09"], "Yang sengaja TIDAK ada"):
            fail(DOC_FILES["11"], 1, f"S2 undocumented exception {name}")
    for path in paths:
        if path not in documented_paths:
            fail(DOC_FILES["12"], 1, f"S3 missing endpoint {path}")
    for path in doc_paths():
        # REJECTED_ADR_ALTERNATIVE (ADR decision format): only table rows
        # under the explicitly titled rejected-alternatives heading are quotes.
        rejected = set()
        if path.parent.name == "adr" and "## Alternatif yang ditolak" in read(path):
            rejected = {n for n, text in section(read(path), "Alternatif yang ditolak") if text.startswith("|")}
        for number, line in enumerate(read(path).splitlines(), 1):
            if number in rejected:
                continue
            # CONTRADICTION_SENTENCE: a negation in a different sentence may
            # not excuse a positive claim. Doc 11's old-document column is
            # explicitly a historical quotation, not a current assertion.
            if str(path) == DOC_FILES["11"] and line.startswith("| B-"):
                row = cells(line)
                line = " | ".join(row[:2] + row[3:])
            for sentence in re.split(r"[.!?](?:\s|$)|\|", line.replace("`", "").replace("**", "")):
                for rule, pattern in CONTRADICTIONS.items():
                    for match in re.finditer(pattern, sentence, re.I):
                        # EXPLICIT_NEGATED_CLAIM (Doc 11 §8.8): only an
                        # immediately preceding negation retracts the claim.
                        if re.search(r"(?:tidak benar bahwa|bukan berarti|jangan mengklaim)\s*$", sentence[:match.start()], re.I):
                            continue
                        fail(path, number, f"KONTRADIKSI {rule}: {match[0]}")
    for path, number, message in errors:
        finding(path, number, message)
    if not errors:
        if blueprint:
            print(f"LULUS — COVER ({len(cover)} daftar) + S1-S3 + KONTRADIKSI bersih")
        else:
            print(f"LULUS SEBAGIAN — COVER ({len(cover)} daftar) + S1-S3 dari dokumen + KONTRADIKSI bersih; Blueprint tidak dibaca")
    return int(bool(errors))


if __name__ == "__main__":
    sys.exit(run(check))
