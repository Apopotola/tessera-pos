"""Turn user-guide Markdown chapters into one Word document (.docx), pictures included.

Usage:
    python md2docx.py OUT.docx CHAPTER.md [CHAPTER.md ...] [--for="Client name"]

Standard library only (a .docx is a zip of XML parts), so it runs without Word or extra packages.
Supports the same Markdown as md2html.py: headings, paragraphs, **bold**, *italic*, `code`,
links (text only), tables, numbered and bullet lists, images with captions and the
**Tip:** / **Important:** / **Common mistake:** callouts. Adds a cover page and a table of
contents that Word fills in when the document is opened (it asks to update fields).
"""
import datetime
import html
import pathlib
import re
import struct
import sys
import zipfile

args = [a for a in sys.argv[1:] if not a.startswith("--")]
client = next((a.split("=", 1)[1] for a in sys.argv[1:] if a.startswith("--for=")), "")
out_path, sources = pathlib.Path(args[0]), [pathlib.Path(a).resolve() for a in args[1:]]

IMG = re.compile(r"^!\[([^\]]*)\]\(([^)]+)\)\s*$")
OL = re.compile(r"^(\d+)\. (.*)")
UL = re.compile(r"^\s*[-*] (.*)")
INLINE = re.compile(r"(\*\*[^*]+\*\*|\*[^*\s][^*]*\*|`[^`]+`|\[[^\]]+\]\([^)]+\))")
CALLOUT = {"Tip:": ("FDF3E2", "F2A93B"), "Important:": ("EEE9F8", "5B3FA3"), "Common mistake:": ("FDECEC", "E03131")}

TEXT_WIDTH = 11906 - 2 * 850  # A4 width minus margins, in twips
EMU_PER_TWIP = 635

media: list[tuple[str, bytes]] = []
body: list[str] = []


def esc(text: str) -> str:
    return html.escape(text, quote=False)


def run(text: str, bold=False, italic=False, code=False, color=None, size=None) -> str:
    props = ""
    if bold:
        props += "<w:b/>"
    if italic:
        props += "<w:i/>"
    if code:
        props += '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:shd w:val="clear" w:fill="F1F0F7"/>'
    if color:
        props += f'<w:color w:val="{color}"/>'
    if size:
        props += f'<w:sz w:val="{size}"/>'
    return f'<w:r>{f"<w:rPr>{props}</w:rPr>" if props else ""}<w:t xml:space="preserve">{esc(text)}</w:t></w:r>'


def inline(text: str, **base) -> str:
    out = []
    for part in INLINE.split(text):
        if not part:
            continue
        if part.startswith("**"):
            out.append(run(part[2:-2], bold=True, **{k: v for k, v in base.items() if k != "bold"}))
        elif part.startswith("`"):
            out.append(run(part[1:-1], code=True))
        elif part.startswith("["):
            out.append(run(re.match(r"\[([^\]]+)\]", part).group(1), **base))
        elif part.startswith("*") and part.endswith("*") and len(part) > 2:
            out.append(run(part[1:-1], italic=True, **{k: v for k, v in base.items() if k != "italic"}))
        else:
            out.append(run(part, **base))
    return "".join(out)


def para(content: str, style=None, props="") -> str:
    ppr = (f'<w:pStyle w:val="{style}"/>' if style else "") + props
    return f'<w:p>{f"<w:pPr>{ppr}</w:pPr>" if ppr else ""}{content}</w:p>'


def png_size(data: bytes) -> tuple[int, int]:
    return struct.unpack(">II", data[16:24])


def picture(path: pathlib.Path) -> str:
    data = path.read_bytes()
    media.append((path.name, data))
    rid = f"rIdImg{len(media)}"
    w_px, h_px = png_size(data)
    max_w = TEXT_WIDTH * EMU_PER_TWIP
    cx = min(max_w, w_px * 9525)
    cy = int(cx * h_px / w_px)
    n = len(media)
    return (
        f'<w:r><w:drawing><wp:inline><wp:extent cx="{cx}" cy="{cy}"/><wp:docPr id="{n}" name="Picture {n}"/>'
        '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        f'<pic:nvPicPr><pic:cNvPr id="{n}" name="{esc(path.name)}"/><pic:cNvPicPr/></pic:nvPicPr>'
        f'<pic:blipFill><a:blip r:embed="{rid}"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        f'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="{cx}" cy="{cy}"/></a:xfrm>'
        '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:ln w="6350"><a:solidFill><a:srgbClr val="D9D7E6"/></a:solidFill></a:ln></pic:spPr>'
        "</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>"
    )


def table(rows: list[list[str]]) -> str:
    cols = len(rows[0])
    width = TEXT_WIDTH // cols
    grid = "".join(f'<w:gridCol w:w="{width}"/>' for _ in range(cols))
    out = [f'<w:tbl><w:tblPr><w:tblStyle w:val="GuideTable"/><w:tblW w:w="{TEXT_WIDTH}" w:type="dxa"/></w:tblPr><w:tblGrid>{grid}</w:tblGrid>']
    for i, row in enumerate(rows):
        header = i == 0
        cells = []
        for cell in row + [""] * (cols - len(row)):
            shade = '<w:shd w:val="clear" w:fill="EEE9F8"/>' if header else ""
            cells.append(f'<w:tc><w:tcPr><w:tcW w:w="{width}" w:type="dxa"/>{shade}</w:tcPr>{para(inline(cell, bold=header), "TableText")}</w:tc>')
        out.append(f'<w:tr>{"<w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>" if header else "<w:trPr><w:cantSplit/></w:trPr>"}{"".join(cells)}</w:tr>')
    out.append("</w:tbl>")
    return "".join(out) + para("", "Small")


def convert(md_file: pathlib.Path) -> None:
    lines = md_file.read_text(encoding="utf-8").splitlines()
    i = 0
    while i < len(lines):
        line = lines[i].rstrip()
        if not line.strip():
            i += 1
            continue
        if m := re.match(r"^(#{1,4}) (.*)", line):
            level = len(m.group(1))
            body.append(para(inline(m.group(2)), f"Heading{level}"))
            i += 1
            continue
        if m := IMG.match(line):
            caption, path = m.groups()
            body.append(para(picture((md_file.parent / path).resolve()), "Figure"))
            body.append(para(inline(caption), "Caption"))
            i += 1
            continue
        if line.startswith("|"):
            rows = []
            while i < len(lines) and lines[i].startswith("|"):
                cells = [c.strip() for c in lines[i].strip().strip("|").split("|")]
                if not all(re.fullmatch(r":?-{3,}:?", c) for c in cells):
                    rows.append(cells)
                i += 1
            body.append(table(rows))
            continue
        if line.startswith(">"):
            text = []
            while i < len(lines) and lines[i].startswith(">"):
                text.append(lines[i][1:].strip())
                i += 1
            joined = " ".join(text)
            fill, border = next((v for k, v in CALLOUT.items() if joined.startswith(f"**{k}**")), ("F4F3F8", "8F79CC"))
            props = (f'<w:pBdr><w:left w:val="single" w:sz="24" w:space="8" w:color="{border}"/></w:pBdr>'
                     f'<w:shd w:val="clear" w:fill="{fill}"/><w:ind w:left="200" w:right="120"/>')
            body.append(para(inline(joined), "Callout", props))
            continue
        if m := OL.match(line):
            body.append(para(run(f"{m.group(1)}.\t") + inline(m.group(2)), "ListText"))
            i += 1
            continue
        if m := UL.match(line):
            indent = len(line) - len(line.lstrip())
            style = "ListText2" if indent >= 2 else "ListText"
            body.append(para(run("•\t") + inline(m.group(1)), style))
            i += 1
            continue
        text = [line.strip()]
        i += 1
        while i < len(lines) and lines[i].strip() and not re.match(r"^(#|!\[|\||>|\d+\. |\s*[-*] )", lines[i]):
            text.append(lines[i].strip())
            i += 1
        body.append(para(inline(" ".join(text))))


for src in sources:
    convert(src)

today = datetime.date.today()
page_break = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
cover = [
    para("", props='<w:spacing w:before="3600"/>'),
    para(run("tessera", bold=True, color="5B3FA3", size=40)),
    para(run("User Guide", bold=True, color="1A1B2E", size=88), props='<w:spacing w:before="480"/>'),
    para(run("Tessera POS for wines & spirits shops", color="C7861C", size=30)),
]
if client:
    cover.append(para(run(f"Prepared for {client}", size=26)))
cover += [para(run(f"Version 1.0 · {today.day} {today.strftime('%B %Y')}", color="6B6B80", size=22), props='<w:spacing w:before="360"/>'), page_break]
toc = [
    para(run("Contents"), "TOCHeading"),
    '<w:p><w:r><w:fldChar w:fldCharType="begin" w:dirty="true"/></w:r><w:r><w:instrText xml:space="preserve"> TOC \\o "1-2" \\h \\z \\u </w:instrText></w:r>'
    '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>Right-click here and choose Update Field to show the contents.</w:t></w:r>'
    '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>',
]  # each chapter heading starts a new page

NS = ('xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
      'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
      'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"')
footer_ref = '<w:footerReference w:type="default" r:id="rIdFooter"/>'
sect = (f'<w:sectPr>{footer_ref}<w:pgSz w:w="11906" w:h="16838"/>'
        '<w:pgMar w:top="900" w:right="850" w:bottom="1000" w:left="850" w:header="450" w:footer="450" w:gutter="0"/><w:titlePg/></w:sectPr>')
document = f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document {NS}><w:body>{"".join(cover + toc + body)}{sect}</w:body></w:document>'

footer = (f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr {NS}><w:p><w:pPr><w:jc w:val="right"/></w:pPr>'
          f'{run("Tessera POS User Guide · page ", color="6B6B80", size=16)}'
          '<w:r><w:rPr><w:color w:val="6B6B80"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r>'
          '<w:r><w:rPr><w:color w:val="6B6B80"/><w:sz w:val="16"/></w:rPr><w:instrText>PAGE</w:instrText></w:r>'
          '<w:r><w:rPr><w:color w:val="6B6B80"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>')


def style(sid, name, ppr="", rpr="", based="Normal", kind="paragraph"):
    return (f'<w:style w:type="{kind}" w:styleId="{sid}"><w:name w:val="{name}"/>'
            f'{f"<w:basedOn w:val=\"{based}\"/>" if based else ""}<w:qFormat/>'
            f'{f"<w:pPr>{ppr}</w:pPr>" if ppr else ""}{f"<w:rPr>{rpr}</w:rPr>" if rpr else ""}</w:style>')


styles = (
    f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles {NS}>'
    '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Segoe UI" w:hAnsi="Segoe UI" w:cs="Segoe UI"/>'
    '<w:color w:val="1A1B2E"/><w:sz w:val="20"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault>'
    '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
    + style("Normal", "Normal", based="")
    + style("Heading1", "heading 1", '<w:keepNext/><w:pageBreakBefore/><w:pBdr><w:bottom w:val="single" w:sz="24" w:space="4" w:color="F2A93B"/></w:pBdr><w:spacing w:after="240"/><w:outlineLvl w:val="0"/>', '<w:b/><w:color w:val="5B3FA3"/><w:sz w:val="44"/>')
    + style("Heading2", "heading 2", '<w:keepNext/><w:spacing w:before="320" w:after="120"/><w:outlineLvl w:val="1"/>', '<w:b/><w:color w:val="5B3FA3"/><w:sz w:val="29"/>')
    + style("Heading3", "heading 3", '<w:keepNext/><w:spacing w:before="240" w:after="80"/><w:outlineLvl w:val="2"/>', '<w:b/><w:sz w:val="24"/>')
    + style("Heading4", "heading 4", '<w:keepNext/><w:spacing w:before="200" w:after="60"/><w:outlineLvl w:val="3"/>', '<w:b/><w:color w:val="5B3FA3"/><w:sz w:val="22"/>')
    + style("Figure", "Figure", '<w:keepNext/><w:spacing w:before="120" w:after="40"/><w:jc w:val="center"/>')
    + style("Caption", "caption", '<w:spacing w:after="200"/><w:jc w:val="center"/>', '<w:i/><w:color w:val="6B6B80"/><w:sz w:val="17"/>')
    + style("Callout", "Callout", '<w:spacing w:before="120" w:after="160"/>')
    + style("ListText", "List Text", '<w:tabs><w:tab w:val="left" w:pos="360"/></w:tabs><w:spacing w:after="80"/><w:ind w:left="360" w:hanging="360"/>')
    + style("ListText2", "List Text 2", '<w:tabs><w:tab w:val="left" w:pos="720"/></w:tabs><w:spacing w:after="60"/><w:ind w:left="720" w:hanging="360"/>')
    + style("TableText", "Table Text", '<w:spacing w:before="40" w:after="40"/>', '<w:sz w:val="18"/>')
    + style("Small", "Small", '<w:spacing w:after="60"/>', '<w:sz w:val="8"/>')
    + style("TOCHeading", "TOC Heading", '<w:spacing w:after="240"/>', '<w:b/><w:color w:val="5B3FA3"/><w:sz w:val="36"/>')
    + style("TOC1", "toc 1", '<w:spacing w:before="120" w:after="40"/>', '<w:b/>')
    + style("TOC2", "toc 2", '<w:spacing w:after="20"/><w:ind w:left="360"/>')
    + '<w:style w:type="table" w:styleId="GuideTable"><w:name w:val="Guide Table"/><w:tblPr>'
    '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="D9D7E6"/><w:left w:val="single" w:sz="4" w:color="D9D7E6"/>'
    '<w:bottom w:val="single" w:sz="4" w:color="D9D7E6"/><w:right w:val="single" w:sz="4" w:color="D9D7E6"/>'
    '<w:insideH w:val="single" w:sz="4" w:color="D9D7E6"/><w:insideV w:val="single" w:sz="4" w:color="D9D7E6"/></w:tblBorders>'
    '<w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
    "</w:styles>"
)

content_types = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                 '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                 '<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
                 '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                 '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                 '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
                 '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
                 '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>')
root_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
             '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>')
doc_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            '<Relationship Id="rIdSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            '<Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
            + "".join(f'<Relationship Id="rIdImg{n}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image{n}.png"/>'
                      for n in range(1, len(media) + 1))
            + "</Relationships>")
settings = (f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings {NS}><w:defaultTabStop w:val="360"/>'
            '<w:updateFields w:val="true"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>')
core = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties '
        'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" '
        'xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        '<dc:title>Tessera POS User Guide</dc:title><dc:creator>Tessera</dc:creator>'
        f'<dcterms:created xsi:type="dcterms:W3CDTF">{today.isoformat()}T00:00:00Z</dcterms:created></cp:coreProperties>')

with zipfile.ZipFile(out_path, "w", zipfile.ZIP_DEFLATED) as z:
    z.writestr("[Content_Types].xml", content_types)
    z.writestr("_rels/.rels", root_rels)
    z.writestr("docProps/core.xml", core)
    z.writestr("word/document.xml", document)
    z.writestr("word/styles.xml", styles)
    z.writestr("word/settings.xml", settings)
    z.writestr("word/footer1.xml", footer)
    z.writestr("word/_rels/document.xml.rels", doc_rels)
    for n, (_, data) in enumerate(media, 1):
        z.writestr(f"word/media/image{n}.png", data, compress_type=zipfile.ZIP_STORED)
print(f"wrote {out_path} ({len(media)} pictures)")
