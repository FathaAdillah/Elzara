#!/usr/bin/env python3
"""Parse Doc 09 declarations, ownership tally, and the actual REFERENCES graph."""

import re
import sys

from common import cells, finding, read, run, section
from expectations import ADMIN_OPERATOR_CHECK, AUTH_TABLES, DEAD_TABLE_NAMES, DOC_FILES, FRAMEWORK_TABLES, GATE_H_TABLE_COUNT, NON_TABLES, OPERATOR_INDEX, RETIRED_USER_COLUMN, USER_FLAGS


def ownership(text):
    owners, total = {}, None
    for number, line in section(text, "Ringkasan kepemilikan"):
        row = cells(line)
        if len(row) != 3:
            continue
        if row[0] == "**Total**":
            total = int(row[2].strip("*"))
        elif row[2].isdigit():
            tables = [t.strip() for t in row[1].split(",")]
            if row[0] in owners or len(tables) != int(row[2]) or len(set(tables)) != len(tables):
                raise ValueError(f"Doc 09:{number}: invalid/duplicate ownership row")
            owners[row[0]] = tables
    flat = [t for tables in owners.values() for t in tables]
    if not owners or total != len(flat) or len(set(flat)) != len(flat):
        raise ValueError("Doc 09 ownership tally missing, duplicated, or inconsistent")
    return owners, total


def declarations(text):
    blocks, current, fenced = {}, None, False
    for number, line in enumerate(text.splitlines(), 1):
        if line.startswith("```"):
            fenced = not fenced
            current = None
            continue
        if not fenced:
            continue
        match = re.match(r"^([a-z][a-z0-9_]*(?:\s*/\s*[a-z][a-z0-9_]*)*)\s+\[([A-Za-z ]+)\]", line)
        if match:
            # The unowned example in §1 is a notation legend, not a declaration.
            if match[2] == "modul pemilik":
                continue
            for table in re.split(r"\s*/\s*", match[1]):
                if table in blocks:
                    raise ValueError(f"duplicate declaration {table} at line {number}")
                blocks[table] = {"owner": match[2], "line": number, "body": []}
            current = match[1] if "/" not in match[1] else None
        elif current:
            blocks[current]["body"].append((number, line))
    return blocks


def check():
    path = DOC_FILES["09"]
    text = read(path)
    owners, total = ownership(text)
    blocks = declarations(text)
    errors = []

    def fail(line, message):
        errors.append((line, message))

    if total != GATE_H_TABLE_COUNT:
        fail(1, f"tally {total} != approved gate H expectation {GATE_H_TABLE_COUNT}")
    expected = {t: owner for owner, tables in owners.items() for t in tables}
    for table in expected.keys() | FRAMEWORK_TABLES:
        if table not in blocks:
            fail(1, f"missing declaration {table}")
        elif blocks[table]["owner"] != expected.get(table, "Laravel"):
            fail(blocks[table]["line"], f"wrong owner for {table}")
    for table in blocks.keys() - expected.keys() - FRAMEWORK_TABLES:
        fail(blocks[table]["line"], f"undeclared ownership {table}")
    graph = {table: set() for table in blocks}
    for table, block in blocks.items():
        if table in DEAD_TABLE_NAMES | NON_TABLES:
            fail(block["line"], f"retired/non-table declaration {table}")
        for number, line in block["body"]:
            # SCHEMA_EXPLANATION: comments and declaration suffixes describe old
            # names. Neither creates a foreign key or an active identifier.
            active = line.split("--", 1)[0]
            for old in DEAD_TABLE_NAMES:
                if re.search(rf"\b{old}\b", active):
                    fail(number, f"retired schema identifier {old}")
            # PLAIN_BACK_EDGE (M-5): this exact phrase explicitly denies a FK.
            fk_text = active.replace("tanpa REFERENCES", "")
            targets = re.findall(r"\bREFERENCES\s+([a-z][a-z0-9_]*)\s*\(", fk_text, re.I)
            if len(targets) != len(re.findall(r"\bREFERENCES\b", fk_text, re.I)):
                fail(number, "unparseable REFERENCES declaration")
            for target in targets:
                if target not in graph:
                    fail(number, f"unknown FK target {target}")
                # M-5 explicitly permits self-FKs in the same CREATE TABLE,
                # including immutable reversal chains, not only tree parents.
                elif target != table:
                    graph[table].add(target)
    visiting, visited = set(), set()

    def visit(table):
        if table in visiting:
            fail(blocks[table]["line"], f"FK cycle through {table}")
            return
        if table in visited:
            return
        visiting.add(table)
        for target in sorted(graph[table]):
            visit(target)
        visiting.remove(table)
        visited.add(table)

    for table in sorted(graph):
        visit(table)
    omitted = "\n".join(line for _, line in section(text, "Yang sengaja TIDAK ada"))
    for table in NON_TABLES:
        if f"`{table}`" not in omitted:
            fail(1, f"§20 missing explanation for {table}")
    for table in AUTH_TABLES - blocks.keys():
        fail(1, f"§4.4 missing table {table}")
    users = "\n".join(line.split("--", 1)[0] for _, line in blocks.get("users", {}).get("body", []))
    for flag in USER_FLAGS:
        if not re.search(rf"^  {flag}\s+boolean\s+NOT NULL\s+DEFAULT false\b", users, re.M):
            fail(1, f"users.{flag}: boolean NOT NULL DEFAULT false required")
    if ADMIN_OPERATOR_CHECK not in users:
        fail(1, "users admin/operator CHECK missing")
    if not re.search(rf"idx {OPERATOR_INDEX}\s*\(is_operator, status\)", users):
        fail(1, "users operator index missing")
    if re.search(rf"^  {RETIRED_USER_COLUMN}\s", users, re.M):
        fail(1, f"users.{RETIRED_USER_COLUMN} is retired")
    for number, message in errors:
        finding(path, number, message)
    if not errors:
        print(f"LULUS — {total} tabel, konsisten dengan Doc 11.")
    return int(bool(errors))


if __name__ == "__main__":
    sys.exit(run(check))
