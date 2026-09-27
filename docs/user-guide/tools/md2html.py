"""Turn user-guide Markdown chapters into one branded HTML page, ready to print to PDF.

Usage:
    python md2html.py OUT.html CHAPTER.md [CHAPTER.md ...] [--cover] [--landscape] [--gallery]

Supports what the guide uses: headings, paragraphs, **bold**, *italic*, `code`, links (text only),
tables, numbered lists (keeping their numbers), bullet lists, images with captions
(![Figure …](path)) and callout boxes written as blockquotes that start with
**Tip:**, **Important:** or **Common mistake:**.
--cover adds a title page and a table of contents; --gallery puts consecutive images
side by side (used for the inventory). Brand colours: Jacaranda #5B3FA3 headings,
Ink #1A1B2E text, Tile Amber #F2A93B for tips.
"""
import datetime
import html
import pathlib
import re
import sys

args = [a for a in sys.argv[1:] if not a.startswith("--")]
flags = {a for a in sys.argv[1:] if a.startswith("--")}
out_path, sources = pathlib.Path(args[0]), [pathlib.Path(a).resolve() for a in args[1:]]
IMG = re.compile(r"^!\[([^\]]*)\]\(([^)]+)\)\s*$")
OL = re.compile(r"^(\d+)\. (.*)")


def inline(text: str) -> str:
    text = html.escape(text, quote=False)
    text = re.sub(r"`([^`]+)`", r"<code>\1</code>", text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", text)
    text = re.sub(r"(?<![*\w])\*([^*\s][^*]*)\*(?!\w)", r"<i>\1</i>", text)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r"\1", text)
    return text


def slug(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


toc = []


def convert(md_file: pathlib.Path) -> str:
    base = md_file.parent
    lines = md_file.read_text(encoding="utf-8").splitlines()
    out, i = [], 0

    def figure(alt, path, cls):
        uri = (base / path).resolve().as_uri()
        return f'<figure class="{cls}"><img src="{uri}" alt="{html.escape(alt)}"><figcaption>{inline(alt)}</figcaption></figure>'

    while i < len(lines):
        line = lines[i]
        if IMG.match(line):
            figs = []
            while i < len(lines) and IMG.match(lines[i]):
                figs.append(IMG.match(lines[i]).groups())
                i += 1
                if "--gallery" not in flags:
                    break
            if len(figs) > 1:
                out.append('<div class="gallery">' + "".join(figure(a, p, "shot") for a, p in figs) + "</div>")
            else:
                out.append(figure(figs[0][0], figs[0][1], "wide"))
            continue
        if line.startswith("|"):
            rows = []
            while i < len(lines) and lines[i].startswith("|"):
                rows.append([c.strip() for c in lines[i].strip().strip("|").split("|")])
                i += 1
            out.append("<table><thead><tr>" + "".join(f"<th>{inline(c)}</th>" for c in rows[0]) + "</tr></thead><tbody>")
            out += ["<tr>" + "".join(f"<td>{inline(c)}</td>" for c in r) + "</tr>" for r in rows[2:]]
            out.append("</tbody></table>")
            continue
        if line.startswith(">"):
            quote = []
            while i < len(lines) and lines[i].startswith(">"):
                quote.append(lines[i].lstrip(">").strip())
                i += 1
            text = " ".join(q for q in quote if q)
            kind = "note"
            for label, cls in (("Tip:", "tip"), ("Important:", "important"), ("Common mistake:", "mistake")):
                if text.startswith(f"**{label}**"):
                    kind = cls
            out.append(f'<div class="callout {kind}">{inline(text)}</div>')
            continue
        m = re.match(r"^(#{1,4}) (.*)", line)
        if m:
            n, title = len(m.group(1)), m.group(2)
            anchor = slug(title)
            if n <= 2:
                toc.append((n, title, anchor))
            cls = ' class="chapter"' if n == 1 else ""
            out.append(f'<h{n} id="{anchor}"{cls}>{inline(title)}</h{n}>')
        elif OL.match(line):
            start = int(OL.match(line).group(1))
            items = []
            while i < len(lines) and OL.match(lines[i]):
                items.append(OL.match(lines[i]).group(2))
                i += 1
            out.append(f'<ol start="{start}">' + "".join(f"<li>{inline(t)}</li>" for t in items) + "</ol>")
            continue
        elif line.startswith("- "):
            items = []
            while i < len(lines) and lines[i].startswith("- "):
                items.append(lines[i][2:])
                i += 1
            out.append("<ul>" + "".join(f"<li>{inline(t)}</li>" for t in items) + "</ul>")
            continue
        elif line.strip() == "---":
            out.append("<hr>")
        elif line.strip():
            # A paragraph runs until a blank line.
            para = [line]
            while i + 1 < len(lines) and lines[i + 1].strip() and not re.match(r"^(#|\||>|- |\d+\. |!\[)", lines[i + 1]):
                i += 1
                para.append(lines[i])
            out.append(f"<p>{inline(' '.join(para))}</p>")
        i += 1
    return "\n".join(out)


body = "\n".join(convert(src) for src in sources)
landscape = "--landscape" in flags
today = datetime.date.today()
cover = ""
if "--cover" in flags:
    logo = "".join(f'<span style="background:{c}"></span>' for c in ("#8F79CC", "#F2A93B", "#8F79CC", "#FFFFFF"))
    items = "".join(f'<li class="lvl{n}"><a href="#{a}">{inline(t)}</a></li>' for n, t, a in toc)
    cover = f"""<section class="cover"><div class="logo">{logo}</div><div class="brand">tessera</div>
<h1>User Guide</h1><p class="sub">Tessera POS for wines &amp; spirits shops</p>
<p class="meta">Version 1.0 · {today.day} {today.strftime('%B %Y')}</p></section>
<section class="toc"><h2>Contents</h2><ol>{items}</ol></section>"""

css = f"""
@page {{ size: A4 {'landscape' if landscape else 'portrait'}; margin: 16mm 15mm 18mm; }}
@page cover {{ margin: 0; }}
html {{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }}
body {{ font-family: "Segoe UI", Arial, sans-serif; color: #1A1B2E; font-size: 10.5pt; line-height: 1.5; margin: 0; }}
h1 {{ color: #5B3FA3; font-size: 22pt; margin: 0 0 8px; line-height: 1.2; }}
h1.chapter {{ page-break-before: always; border-bottom: 4px solid #F2A93B; padding-bottom: 6px; }}
h2 {{ color: #5B3FA3; font-size: 14.5pt; margin: 20px 0 8px; page-break-after: avoid; }}
h3 {{ color: #1A1B2E; font-size: 12pt; margin: 16px 0 6px; page-break-after: avoid; }}
h4 {{ color: #5B3FA3; font-size: 11pt; margin: 12px 0 4px; page-break-after: avoid; }}
p {{ margin: 6px 0; }}
table {{ width: 100%; border-collapse: collapse; margin: 8px 0 12px; font-size: 9.5pt; }}
tr {{ page-break-inside: avoid; }}
thead {{ display: table-header-group; }}
th {{ background: #1C1D2E; color: #fff; text-align: left; padding: 5px 7px; font-weight: 600; }}
td {{ border-bottom: 1px solid #dddbd3; padding: 5px 7px; vertical-align: top; }}
tr:nth-child(even) td {{ background: #faf9f6; }}
code {{ font-family: Consolas, monospace; background: #f4f2ec; padding: 0 3px; border-radius: 3px; font-size: 9.5pt; }}
ol, ul {{ padding-left: 22px; margin: 6px 0; }}
li {{ margin: 3px 0; }}
figure {{ margin: 10px 0 14px; page-break-inside: avoid; }}
figure img {{ display: block; width: 100%; border: 1px solid #dddbd3; border-radius: 4px; }}
figure.wide img {{ max-height: {'150mm' if landscape else '120mm'}; object-fit: contain; object-position: left top; }}
figcaption {{ font-size: 9pt; color: #5B3FA3; font-weight: 600; margin-top: 4px; }}
.gallery {{ display: grid; grid-template-columns: 1fr 1fr; gap: 10mm 8mm; margin: 6px 0 12px; }}
.gallery figure {{ margin: 0; }}
.gallery figure img {{ height: 76mm; object-fit: contain; object-position: top left; background: #fff; }}
.callout {{ border-radius: 6px; padding: 8px 12px; margin: 10px 0; page-break-inside: avoid; }}
.callout.tip {{ background: #FEF3DF; border-left: 5px solid #F2A93B; }}
.callout.important {{ background: #EDE8F7; border-left: 5px solid #5B3FA3; }}
.callout.mistake {{ background: #FDECEC; border-left: 5px solid #E03131; }}
.callout.note {{ background: #F4F2EC; border-left: 5px solid #C9C4B8; }}
.cover {{ page: cover; background: #1C1D2E; color: #fff; height: 296mm; padding: 70mm 24mm 0; box-sizing: border-box; page-break-after: always; }}
.cover .logo {{ display: grid; grid-template-columns: 22px 22px; gap: 5px; }}
.cover .logo span {{ width: 22px; height: 22px; border-radius: 5px; }}
.cover .brand {{ font-size: 20pt; font-weight: 700; margin: 10px 0 40px; letter-spacing: .02em; }}
.cover h1 {{ color: #fff; font-size: 44pt; }}
.cover .sub {{ color: #F2A93B; font-size: 15pt; margin: 4px 0 30px; }}
.cover .meta {{ color: #b9b9c8; font-size: 11pt; }}
.toc {{ page-break-after: always; }}
.toc ol {{ list-style: none; padding: 0; }}
.toc li.lvl1 {{ font-weight: 700; margin-top: 10px; }}
.toc li.lvl2 {{ padding-left: 18px; font-size: 10pt; }}
.toc a {{ color: #1A1B2E; text-decoration: none; }}
hr {{ border: none; border-top: 1px solid #dddbd3; margin: 14px 0; }}
"""
title = "Tessera POS — User Guide" if "--cover" in flags else (toc[0][1] if toc else "Tessera POS")
out_path.write_text(f"<!DOCTYPE html><html lang='en-GB'><head><meta charset='utf-8'><title>{html.escape(title)}</title><style>{css}</style></head><body>{cover}{body}</body></html>", encoding="utf-8")
print("wrote", out_path)
