#!/usr/bin/env python3
"""Gate I: compare documented structure with source, without executing services."""

import json
import re
import sys
from collections import Counter

from common import ROOT, cells, expand_braces, finding, read, run, section, text_files, tree_entries
from expectations import BLUEPRINT_PATHS, BUCKETS, DOC_FILES, FRONTENDS, MIGRATION_BANDS, MODULE_COUNT, QUEUES, TABLELESS_MODULES, TREE_ENTRY_COUNT, WORKSPACE_FILES
from verify_schema import ownership


def check():
    errors = []

    def fail(path, line, message):
        errors.append((path, line, message))

    architecture = read(DOC_FILES["01"])
    entries = tree_entries(architecture)
    # TREE_BRACES: Doc 01 writes four test directories as one brace expression;
    # the gate counts filesystem entries, not the number of printed tree rows.
    entry_count = sum(len(expand_braces(entry)) for _, entry in entries)
    if entry_count != TREE_ENTRY_COUNT:
        fail(DOC_FILES["01"], 1, f"expected {TREE_ENTRY_COUNT} tree entries, got {entry_count}")
    for number, entry in entries:
        for path in expand_braces(entry):
            if not (ROOT / path).exists():
                fail(DOC_FILES["01"], number, f"missing tree path {path}")

    owners, _ = ownership(read(DOC_FILES["09"]))
    modules = set(owners) - {"Lintas modul"} | TABLELESS_MODULES
    doc_modules = set()
    for number, line in section(architecture, "Modul, tanggung jawab"):
        row = cells(line)
        if len(row) != 4 or row[0] in {"Modul", "---"} or not re.fullmatch(r"[A-Z][A-Za-z]+", row[0]):
            continue
        if row[0] in doc_modules:
            fail(DOC_FILES["01"], number, f"duplicate module {row[0]}")
        doc_modules.add(row[0])
        # OWNERSHIP_REBUTTAL: prose after the em dash discusses other modules,
        # retired names and views, not additional owned tables (Doc 11 §8.5).
        tables = re.findall(r"`([a-z][a-z0-9_]*)`", row[2].split(" — ", 1)[0])
        if Counter(tables) != Counter(owners.get(row[0], [])):
            fail(DOC_FILES["01"], number, f"ownership mirror differs for {row[0]}")
    disk = {p.name for p in (ROOT / "apps/api/app/Modules").iterdir() if p.is_dir()}
    tree_modules = {entry.rsplit("/", 1)[-1] for _, entry in entries if entry.startswith("apps/api/app/Modules/")}
    if not (doc_modules == tree_modules == disk == modules) or len(modules) != MODULE_COUNT:
        fail(DOC_FILES["01"], 1, f"module sets differ: Doc01={sorted(doc_modules)}, tree={sorted(tree_modules)}, disk={sorted(disk)}, Doc09={sorted(modules)}")
    current = None
    parenthetical = 0
    tree_tables = {}
    for number, line in section(architecture, "Layout monorepo"):
        match = re.search(r"(?:├──|└──) ([A-Za-z]+)/", line)
        if match:
            current = match[1] if match[1] in modules else None
            parenthetical = 0
        if current and "#" in line:
            comment = line.split("#", 1)[1].strip()
            # TREE_REBUTTAL: parenthesized historical explanations are not owned
            # tables; table lists before '(' remain matched in full.
            prior_parenthetical = parenthetical
            parenthetical += comment.count("(") - comment.count(")")
            if prior_parenthetical or comment.startswith("("):
                continue
            names = comment.split("(", 1)[0].strip().rstrip(",").strip()
            if names.startswith("tanpa tabel:"):
                continue
            if names and not re.fullmatch(r"[a-z][a-z0-9_]*(?:,\s*[a-z][a-z0-9_]*)*", names):
                fail(DOC_FILES["01"], number, f"unrecognized ownership tree annotation: {names}")
            elif names:
                tree_tables.setdefault(current, []).extend(re.split(r",\s*", names))
    for module in modules:
        if Counter(tree_tables.get(module, [])) != Counter(owners.get(module, [])):
            fail(DOC_FILES["01"], 1, f"tree ownership differs for {module}")

    for path in text_files():
        # AUDITOR_RULE_LITERALS: expectations and fixtures must name forbidden
        # spellings to detect them; no application/docs source is exempted here.
        if path.parts[:2] == ("scripts", "doc-auditors"):
            continue
        for number, line in enumerate(read(path).splitlines(), 1):
            # LAYOUT_RENAME_RECORD (Doc 11 §8.5): exact historical source name,
            # not permission to use an old path elsewhere in the paragraph.
            if str(path) == DOC_FILES["11"]:
                line = line.replace("Blueprint §3.1 menulis `apps/commerce-api|commerce-admin|commerce-web`; repo ini memakai", "")
                line = line.replace("(bukan `infrastructure/docker`)", "")
                line = line.replace("`apps/commerce-*` di dokumen baru sedang menulis nama yang salah", "")
                # LAYOUT_GATE_SPEC: the gate's negative example is not a path claim.
                line = line.replace("nol sisa nama layout lama (`commerce-*`, `infrastructure/docker`)", "")
            # SKILL_GATE_SPEC: mirror of the same negative example in both skills.
            if path.name == "SKILL.md" and path.parent.name == "doc-verification-gates":
                line = line.replace("tidak ada nama `commerce-*` sisa", "")
            # PLAN_RENAME_RECORD: this exact parenthetical retracts the old name.
            if path.name == "2026-11-17-sprint-0-fondasi.md":
                line = line.replace("pola path `apps/commerce-*` adalah nama lama, diganti `apps/admin`/`apps/web`", "")
            if re.search(r"commerce-(?:api|admin|web|\*)|infrastructure/docker", line):
                fail(path, number, "retired application/layout path")

    compose = read("docker/compose.yaml")
    queue_line = re.findall(r"^  HORIZON_QUEUES: ([^\n]+)$", compose, re.M)
    routing = read("apps/api/app/Platform/Outbox/QueueRouting.php")
    queue_code = re.search(r"public const QUEUES = \[([^]]*)\];", routing)
    if len(queue_line) != 1 or Counter(queue_line[0].split(",")) != Counter(QUEUES):
        fail("docker/compose.yaml", 1, "HORIZON_QUEUES differs from canonical queues")
    if not queue_code or Counter(re.findall(r"'([^']+)'", queue_code[1])) != Counter(QUEUES):
        fail("apps/api/app/Platform/Outbox/QueueRouting.php", 1, "QUEUES differs from canonical queues")
    buckets = re.findall(r'^BUCKETS="([^"]+)"$', read("docker/minio/init-buckets.sh"), re.M)
    if len(buckets) != 1 or Counter(buckets[0].split()) != Counter(BUCKETS):
        fail("docker/minio/init-buckets.sh", 1, "bucket inventory differs")
    bucket_code = re.search(r"\$buckets = \[(.*?)\];", read("apps/api/config/filesystems.php"), re.S)
    if not bucket_code or Counter(re.findall(r"'([^']+)'\s*=>", bucket_code[1])) != Counter(BUCKETS):
        fail("apps/api/config/filesystems.php", 1, "bucket disks differ")

    for name in WORKSPACE_FILES:
        if (ROOT / name).exists():
            fail(name, 1, "root workspace/package manager file forbidden")
    for frontend in FRONTENDS:
        package = json.loads(read(frontend + "/package.json"))
        lock = json.loads(read(frontend + "/package-lock.json"))
        if not isinstance(lock.get("packages"), dict):
            fail(frontend + "/package-lock.json", 1, "npm lock packages missing")
        if "workspaces" in package:
            fail(frontend + "/package.json", 1, "workspaces forbidden")
        for group in ("dependencies", "devDependencies", "optionalDependencies"):
            for name, value in package.get(group, {}).items():
                if value.startswith("file:"):
                    target = (ROOT / frontend / value[5:]).resolve()
                    if not target.is_relative_to(ROOT / "packages") or json.loads((target / "package.json").read_text())["name"] != name:
                        fail(frontend + "/package.json", 1, f"file dependency name/path differs: {name}")
                elif value.startswith("workspace:"):
                    fail(frontend + "/package.json", 1, "workspace protocol forbidden")

    seen = set()
    for path in text_files():
        if path.suffix != ".php" or not any(part.lower() == "migrations" for part in path.parts):
            continue
        match = re.fullmatch(r"(\d{4})_([A-Z][A-Za-z]+)_([a-z][a-z0-9_]*)\.php", path.name)
        if not match:
            fail(path, 1, "M-3 migration basename invalid")
            continue
        if path.name in seen:
            fail(path, 1, "M-3 duplicate global basename")
        seen.add(path.name)
        number, module = int(match[1]), match[2]
        expected_dir = f"apps/api/app/Modules/{module}/Infrastructure/Database/Migrations"
        if module == "Platform":
            expected_dir = "apps/api/app/Platform/Infrastructure/Database/Migrations"
        # Framework's own migrations may live in database/migrations (M-1).
        framework_dir = str(path.parent) == "apps/api/database/migrations" and module == "Platform"
        if str(path.parent) != expected_dir and not framework_dir:
            fail(path, 1, "M-1/M-3 migration owner does not match directory")
        if not any(low <= number <= high for low, high in MIGRATION_BANDS.get(module, ())) and number < 1600:
            fail(path, 1, "M-3 migration outside documented sprint band")

    # This parser is intentionally limited to the repository's block-style YAML
    # path keys. Reject duplicates and malformed inventory instead of grepping
    # for occurrences in descriptions, examples, or unrelated sections.
    spec = read("packages/api-contracts/openapi.yaml")
    paths_block = re.search(r"^paths:\n(.*?)(?=^\S|\Z)", spec, re.M | re.S)
    if not paths_block:
        raise ValueError("OpenAPI paths mapping missing")
    paths = re.findall(r"^  (/[^\n]+):\s*$", paths_block[1], re.M)
    if len(paths) != len(set(paths)):
        fail("packages/api-contracts/openapi.yaml", 1, "duplicate OpenAPI path")
    for path in BLUEPRINT_PATHS:
        if path not in paths:
            fail("packages/api-contracts/openapi.yaml", 1, f"missing Blueprint path {path}")
    for path, number, message in errors:
        finding(path, number, message)
    if not errors:
        print("LULUS — pohon Doc 01 §3.1 = filesystem, dan tujuh aturan layout ditegakkan di berkas")
    return int(bool(errors))


if __name__ == "__main__":
    sys.exit(run(check))
