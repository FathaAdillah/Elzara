#!/usr/bin/env python3
"""Gate F: validate explicit Doc NN section citations against real headings."""

import re
import sys

from common import ROOT, finding, run
from expectations import DOC_NUMBERS


def check():
    documents = {}
    for path in sorted((ROOT / "docs").glob("[0-9][0-9]-*.md")):
        key = path.name[:2]
        if key in documents:
            raise ValueError(f"duplicate Doc {key}")
        headings = set(re.findall(r"^#{2,6}\s+(\d+(?:\.\d+)*)(?=[. ]|$)", path.read_text(encoding="utf-8"), re.M))
        documents[key] = headings
    missing = set(DOC_NUMBERS) - documents.keys()
    if missing:
        raise ValueError(f"missing numbered documents: {sorted(missing)}")
    count = 0
    citation = re.compile(r"\bDoc\s+(\d{2})\s+§(\d+(?:\.\d+)*)(?!\d)")
    continuation = re.compile(r"\s*(?:/|,|dan|[–—-])\s*§?(\d+(?:\.\d+)*)")
    for path in sorted((ROOT / "docs").rglob("*.md")):
        for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            # MARKDOWN_EMPHASIS: formatting a citation is not a different token.
            line = line.replace("**", "").replace("`", "")
            for match in citation.finditer(line):
                targets = [match[2]]
                offset = match.end()
                while following := continuation.match(line, offset):
                    targets.append(following[1])
                    offset = following.end()
                for target in targets:
                    if target not in documents.get(match[1], set()):
                        finding(path.relative_to(ROOT), number, f"Doc {match[1]} §{target}: bagian tidak ada")
                        count += 1
    if not count:
        print("0 temuan")
    return int(count != 0)


if __name__ == "__main__":
    sys.exit(run(check))
