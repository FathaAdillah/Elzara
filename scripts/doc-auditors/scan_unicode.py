#!/usr/bin/env python3
"""Gate G; explicit file arguments match scripts/verify-docs.sh."""

import sys
import unicodedata
from pathlib import Path

from common import ROOT, finding, run, text_files
from expectations import SUSPICIOUS_UNICODE_NAMES


def check():
    count = 0
    for name in sys.argv[1:] or text_files():
        path = Path(name)
        path = path if path.is_absolute() else ROOT / path
        for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            for char in line:
                label = unicodedata.name(char, "")
                if char == "\ufffd" or any(token in label for token in SUSPICIOUS_UNICODE_NAMES):
                    finding(path.relative_to(ROOT) if path.is_relative_to(ROOT) else path, number, f"U+{ord(char):04X} {label or 'replacement character'}")
                    count += 1
    print(f"selesai: {count} kemunculan mencurigakan")
    return int(count != 0)


if __name__ == "__main__":
    sys.exit(run(check))
