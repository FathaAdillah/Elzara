"""Small Markdown readers shared by the independent document gates."""

import os
import re
import sys
from pathlib import Path

from expectations import IGNORED_DIRS, IGNORED_FILES, RUNTIME_DIRS, TEXT_SUFFIXES

ROOT = Path(__file__).resolve().parents[2]


def read(path):
    return (ROOT / path).read_text(encoding="utf-8")


def finding(path, line, message):
    print(f"{path}:{line}: {message}")


def section(text, title):
    headings = list(re.finditer(r"^(#{2,6})\s+(?:\d+(?:\.\d+)*\.?\s+)?(.+)$", text, re.M))
    matches = [h for h in headings if h[2].startswith(title)]
    if len(matches) != 1:
        raise ValueError(f"expected exactly one heading titled {title!r}, found {len(matches)}")
    start = matches[0]
    end = next((h.start() for h in headings if h.start() > start.start() and len(h[1]) <= len(start[1])), len(text))
    return [(text.count("\n", 0, start.start()) + i + 1, line) for i, line in enumerate(text[start.start():end].splitlines())]


def cells(line):
    # Pipes inside inline code (enum values) do not delimit Markdown cells.
    return [cell.strip() for cell in re.split(r"\|(?=(?:[^`]*`[^`]*`)*[^`]*$)", line.strip())[1:-1]]


def text_files():
    for directory, dirs, files in os.walk(ROOT):
        dirs[:] = sorted(d for d in dirs if d not in IGNORED_DIRS and (Path(directory) / d).relative_to(ROOT).as_posix() not in RUNTIME_DIRS)
        for name in sorted(files):
            path = Path(directory) / name
            if path.is_symlink() or name in IGNORED_FILES or (name.startswith(".env.") and not name.endswith(".example")):
                continue
            data = path.read_bytes()
            if b"\0" in data:
                continue
            try:
                data.decode("utf-8")
            except UnicodeDecodeError:
                if path.suffix in TEXT_SUFFIXES:
                    raise
                continue
            yield path.relative_to(ROOT)


def tree_entries(text):
    entries, stack = [], []
    for number, line in section(text, "Layout monorepo"):
        match = re.match(r"(?P<prefix>[│ ]*)(?:├──|└──) (?P<name>[^#]+)", line)
        if not match:
            continue
        depth = len(match["prefix"]) // 4
        name = match["name"].strip().rstrip("/")
        stack[depth:] = [name]
        entries.append((number, "/".join(stack)))
    if not entries:
        raise ValueError("layout tree has no entries")
    return entries


def expand_braces(path):
    match = re.search(r"\{([^{}]+)\}", path)
    if not match:
        return [path]
    return [expanded for part in match[1].split(",") for expanded in expand_braces(path[:match.start()] + part + path[match.end():])]


def run(check):
    try:
        return check()
    except (OSError, UnicodeError, ValueError, KeyError) as error:
        path = Path(error.filename) if isinstance(error, OSError) and error.filename else Path(sys.argv[0]).resolve()
        finding(path.relative_to(ROOT) if path.is_relative_to(ROOT) else path, 1, str(error))
        return 1
