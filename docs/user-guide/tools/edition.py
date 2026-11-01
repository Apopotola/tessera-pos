"""Prepare a client edition of the user guide: the chapters with the edition's wording, pointing at its pictures.

Usage:
    python edition.py EDITION_DIR OUT_DIR

EDITION_DIR holds edition.json and the edition's images/ (taken by guide-capture with GUIDE_IMAGES).
edition.json lists [old, new] text replacements applied to every chapter; each one must match
somewhere, so a changed chapter is noticed. The prepared chapters land in OUT_DIR (a build folder),
ready for md2html.py and md2docx.py.
"""
import json
import os
import pathlib
import sys

edition_dir, out_dir = pathlib.Path(sys.argv[1]).resolve(), pathlib.Path(sys.argv[2]).resolve()
guide = pathlib.Path(__file__).resolve().parent.parent
edition = json.loads((edition_dir / "edition.json").read_text(encoding="utf-8"))
images = pathlib.Path(os.path.relpath(edition_dir / "images", out_dir)).as_posix()

out_dir.mkdir(parents=True, exist_ok=True)
chapters = sorted(guide.glob("[01][0-9]-*.md"))
chapters = [c for c in chapters if not c.name.startswith("00-")]
texts = {c.name: c.read_text(encoding="utf-8").replace("\r\n", "\n") for c in chapters}

for old, new in edition["replace"]:
    hits = [name for name, text in texts.items() if old in text]
    if not hits:
        sys.exit(f"edition.json: no chapter contains {old!r}")
    for name in hits:
        texts[name] = texts[name].replace(old, new)

for name, text in texts.items():
    (out_dir / name).write_text(text.replace("](images/", f"]({images}/"), encoding="utf-8")
print(f"{len(texts)} chapters for {edition['client']} in {out_dir}")
