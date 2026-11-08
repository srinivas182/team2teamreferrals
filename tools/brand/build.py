"""Builds production brand assets for Team to Team Referrals (concept C, t2t).

All lettering is converted to vector outlines, so files render identically
everywhere without fonts installed.
"""
import io, os
import uharfbuzz as hb
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
import cairosvg
from PIL import Image

FONT_DIR = "/usr/share/fonts/opentype/inter/"
COBALT, ORANGE, NAVY, SLATE, WHITE = "#2451F5", "#FF7A1A", "#0E1B3D", "#5B6785", "#FFFFFF"
SLATE_ON_DARK = "#AAB4CE"
OUT = "../../brand"  # run from tools/brand: python3 build.py

class Face:
    def __init__(self, file):
        path = FONT_DIR + file
        self.blob = hb.Blob.from_file_path(path)
        self.hbface = hb.Face(self.blob)
        self.font = hb.Font(self.hbface)
        self.tt = TTFont(path)
        self.gs = self.tt.getGlyphSet()
        self.upem = self.tt["head"].unitsPerEm
        self.order = self.tt.getGlyphOrder()

    def path(self, text, size, x, y, tracking=0.0):
        """Return SVG path data for text at baseline (x, y), size in px, tracking in em."""
        buf = hb.Buffer(); buf.add_str(text); buf.guess_segment_properties()
        hb.shape(self.font, buf, {"kern": True, "liga": True})
        s = size / self.upem
        pen = SVGPathPen(self.gs)
        cx = 0
        for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
            name = self.order[info.codepoint]
            tp = TransformPen(pen, (s, 0, 0, -s, x + (cx + pos.x_offset) * s, y - pos.y_offset * s))
            self.gs[name].draw(tp)
            cx += pos.x_advance + tracking * self.upem
        return pen.getCommands(), (cx - tracking * self.upem) * s

BLACK = Face("InterDisplay-Black.otf")
XBOLD = Face("InterDisplay-ExtraBold.otf")
MED = Face("InterDisplay-Medium.otf")

def grad(uid):
    return (f'<defs><linearGradient id="{uid}" x1="21" y1="0" x2="50" y2="0" gradientUnits="userSpaceOnUse">'
            f'<stop offset="0" stop-color="{COBALT}"/><stop offset="1" stop-color="{ORANGE}"/></linearGradient></defs>')

def mark(uid, letter=NAVY, arrow=None, head=None):
    """t2t mark in a 76x64 box. arrow/head None = brand gradient + orange."""
    t1, _ = BLACK.path("t", 46, 0, 50, -0.04)
    t2, _ = BLACK.path("t", 46, 48, 50, -0.04)
    stroke = f"url(#{uid})" if arrow is None else arrow
    head = ORANGE if head is None else head
    d = grad(uid) if arrow is None else ""
    return (f'{d}<path d="{t1}" fill="{letter}"/><path d="{t2}" fill="{letter}"/>'
            f'<path d="M21.5 21 C24 12, 41 11, 41 22 C41 30, 33 34, 22 47 L43 47" fill="none" stroke="{stroke}" '
            f'stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>'
            f'<path d="M41 39.5 L50 47 L41 54.5 Z" fill="{head}" stroke="{head}" stroke-width="2.4" stroke-linejoin="round"/>')

def wordmark(x, ink, sub):
    a, wa = XBOLD.path("Team to Team", 27, x, 31, -0.022)
    b, wb = MED.path("Referrals", 19, x + 1, 54, 0.01)
    return f'<path d="{a}" fill="{ink}"/><path d="{b}" fill="{sub}"/>', max(wa, wb + 1)

def svg(w, h, body, bg=None):
    rect = f'<rect width="{w}" height="{h}" fill="{bg}"/>' if bg else ""
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}">{rect}{body}</svg>\n'

def save(name, content):
    os.makedirs(os.path.dirname(f"{OUT}/{name}"), exist_ok=True)
    open(f"{OUT}/{name}", "w").write(content)

def png(svg_text, path, w, h=None):
    os.makedirs(os.path.dirname(f"{OUT}/{path}"), exist_ok=True)
    cairosvg.svg2png(bytestring=svg_text.encode(), write_to=f"{OUT}/{path}", output_width=w, output_height=h)

# ---------- logos ----------
def horizontal(uid, letter, ink, sub, arrow=None, head=None):
    wm, ww = wordmark(94, ink, sub)
    w = int(94 + ww + 4)
    return svg(w, 64, f'<g>{mark(uid, letter, arrow, head)}</g>{wm}')

def stacked(uid, letter, ink, sub, arrow=None, head=None):
    a, wa = XBOLD.path("Team to Team", 27, 0, 0, -0.022)
    b, wb = MED.path("Referrals", 19, 0, 0, 0.01)
    W = int(max(wa, wb) + 8)
    mx = (W - 76) / 2
    a, _ = XBOLD.path("Team to Team", 27, (W - wa) / 2, 104, -0.022)
    b, _ = MED.path("Referrals", 19, (W - wb) / 2, 128, 0.01)
    return svg(W, 136, f'<g transform="translate({mx} 0)">{mark(uid, letter, arrow, head)}</g>'
                       f'<path d="{a}" fill="{ink}"/><path d="{b}" fill="{sub}"/>')

files = {
    "logo/t2t-logo-horizontal.svg": horizontal("g1", NAVY, NAVY, SLATE),
    "logo/t2t-logo-horizontal-on-dark.svg": horizontal("g2", WHITE, WHITE, SLATE_ON_DARK),
    "logo/t2t-logo-stacked.svg": stacked("g3", NAVY, NAVY, SLATE),
    "logo/t2t-logo-stacked-on-dark.svg": stacked("g4", WHITE, WHITE, SLATE_ON_DARK),
    "logo/t2t-logo-mono-navy.svg": horizontal("m1", NAVY, NAVY, NAVY, NAVY, NAVY),
    "logo/t2t-logo-mono-white.svg": horizontal("m2", WHITE, WHITE, WHITE, WHITE, WHITE),
    "logo/t2t-mark.svg": svg(76, 64, mark("g5")),
    "logo/t2t-mark-on-dark.svg": svg(76, 64, mark("g6", WHITE)),
}
for k, v in files.items():
    save(k, v)

# ---------- icons ----------
def app_icon(uid, size=64, radius=15):
    s = 0.6
    off_x, off_y = (64 - 76 * s) / 2, (64 - 64 * s) / 2 + 1.5
    return svg(64, 64, f'<rect width="64" height="64" rx="{radius}" fill="{NAVY}"/>'
                       f'<g transform="translate({off_x} {off_y}) scale({s})">{mark(uid, WHITE)}</g>')

# Favicon: at 16-32 px the full t2t is too fine, so it uses the arrow-2 alone.
def favicon(uid):
    return svg(64, 64, f'<rect width="64" height="64" rx="14" fill="{NAVY}"/>'
                       f'<defs><linearGradient id="{uid}" x1="14" y1="0" x2="50" y2="0" gradientUnits="userSpaceOnUse">'
                       f'<stop offset="0" stop-color="{COBALT}"/><stop offset="1" stop-color="{ORANGE}"/></linearGradient></defs>'
                       f'<path d="M17 19 C20 8, 43 7, 43 20 C43 30, 33 35, 18 50 L40 50" fill="none" stroke="url(#{uid})" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>'
                       f'<path d="M38 41 L49 50 L38 59 Z" fill="{ORANGE}" stroke="{ORANGE}" stroke-width="3" stroke-linejoin="round"/>')

icon = app_icon("i1")
fav = favicon("f1")
save("icons/app-icon.svg", icon)
save("icons/favicon.svg", fav)
for n in (16, 32, 48):
    png(fav, f"icons/favicon-{n}.png", n, n)
png(icon, "icons/apple-touch-icon.png", 180, 180)
png(icon, "icons/icon-192.png", 192, 192)
png(icon, "icons/icon-512.png", 512, 512)
imgs = [Image.open(f"{OUT}/icons/favicon-{n}.png") for n in (16, 32, 48)]
imgs[2].save(f"{OUT}/icons/favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)], append_images=imgs[:2])
# maskable icon (safe zone 80%)
mask = svg(64, 64, f'<rect width="64" height="64" fill="{NAVY}"/><g transform="translate({(64-76*0.5)/2} {(64-64*0.5)/2+1}) scale(0.5)">{mark("i2", WHITE)}</g>')
png(mask, "icons/icon-maskable-512.png", 512, 512)

# ---------- social ----------
h1, w1 = XBOLD.path("Your out-of-area leads,", 60, 0, 0, -0.025)
h2, w2 = XBOLD.path("handed to teams you trust.", 60, 0, 0, -0.025)
h1, _ = XBOLD.path("Your out-of-area leads,", 60, 80, 330, -0.025)
h2, _ = XBOLD.path("handed to teams you trust.", 60, 80, 404, -0.025)
sub, _ = MED.path("teamtoteamreferrals.com", 28, 80, 520, 0.0)
og = svg(1200, 630,
         f'<rect width="1200" height="630" fill="{NAVY}"/>'
         f'<rect x="0" y="618" width="600" height="12" fill="{COBALT}"/><rect x="600" y="618" width="600" height="12" fill="{ORANGE}"/>'
         f'<g transform="translate(80 90) scale(1.6)">{horizontal("o1", WHITE, WHITE, SLATE_ON_DARK).split(">",1)[1].rsplit("</svg>",1)[0]}</g>'
         f'<path d="{h1}" fill="{WHITE}"/><path d="{h2}" fill="{WHITE}"/><path d="{sub}" fill="{SLATE_ON_DARK}"/>')
save("social/og-image.svg", og)
png(og, "social/og-image.png", 1200, 630)
avatar = svg(64, 64, f'<rect width="64" height="64" fill="{NAVY}"/><g transform="translate({(64-76*0.62)/2} {(64-64*0.62)/2+1}) scale(0.62)">{mark("a1", WHITE)}</g>')
save("social/avatar.svg", avatar)
png(avatar, "social/avatar-400.png", 400, 400)

# ---------- preview sheet ----------
def embed(svgtext):
    return svgtext.split(">", 1)[1].rsplit("</svg>", 1)[0]
sheet = svg(1200, 900, f'''<rect width="1200" height="900" fill="#F6F7FB"/>
<rect x="40" y="40" width="540" height="200" rx="16" fill="#FFFFFF" stroke="#E3E7F0"/>
<g transform="translate(80 108) scale(1.45)">{embed(files["logo/t2t-logo-horizontal.svg"])}</g>
<rect x="620" y="40" width="540" height="200" rx="16" fill="{NAVY}"/>
<g transform="translate(660 108) scale(1.45)">{embed(files["logo/t2t-logo-horizontal-on-dark.svg"])}</g>
<rect x="40" y="270" width="350" height="280" rx="16" fill="#FFFFFF" stroke="#E3E7F0"/>
<g transform="translate(95 320) scale(1.3)">{embed(files["logo/t2t-logo-stacked.svg"])}</g>
<rect x="420" y="270" width="350" height="280" rx="16" fill="#FFFFFF" stroke="#E3E7F0"/>
<g transform="translate(465 375) scale(0.98)">{embed(files["logo/t2t-logo-mono-navy.svg"])}</g>
<rect x="800" y="270" width="360" height="280" rx="16" fill="{COBALT}"/>
<g transform="translate(845 375) scale(0.98)">{embed(files["logo/t2t-logo-mono-white.svg"])}</g>
<g transform="translate(40 590) scale(2.5)">{embed(icon)}</g>
<g transform="translate(220 610) scale(1.6)">{embed(fav)}</g>
<g transform="translate(340 630) scale(0.75)">{embed(fav)}</g>
<g transform="translate(400 640) scale(0.5)">{embed(fav)}</g>
<g transform="translate(470 590) scale(0.575)">{embed(og)}</g>''')
save("../docs/design/brand-sheet.svg", sheet)
png(sheet, "../docs/design/brand-sheet.png", 1800)
print("built")
