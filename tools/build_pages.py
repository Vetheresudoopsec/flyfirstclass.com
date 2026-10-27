#!/usr/bin/env python3
"""Builds the per-page files from public_html/index.html (the source of the whole site).

Run this after every change to public_html/index.html:   python3 tools/build_pages.py
It writes about/, services/, routes/, contact/, terms/, privacy-policy/ and airlines/<name>/ (each an index.html with its own
title, description, canonical address and sharing tags), sitemap.xml, robots.txt, robots-test.txt and, once, og-image.png.
"""
import re, os, sys, html, datetime
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "public_html")
SITE = "https://flyfirstclass.com"
PATHS = {"home": "/", "about": "/about/", "services": "/services/", "listings": "/routes/",
         "contact": "/contact/", "manage": "/manage-booking/", "terms": "/terms/", "privacy": "/privacy-policy/"}
CRUMB = {"about": "About", "services": "Services", "listings": "Routes", "contact": "Contact", "manage": "Manage Booking",
         "terms": "Terms of Service", "privacy": "Privacy Policy"}
NOINDEX = {"manage"}   # a tool page, not content: kept out of search results and out of sitemap.xml
src = open(os.path.join(ROOT, "index.html"), encoding="utf-8").read()
pm = re.search(r"const P=\{(.*?)\};", src, re.S).group(1)
P = {k: (t, d) for k, t, d in re.findall(r'(\w+):\["([^"]*)","([^"]*)"\]', pm)}
E = lambda x: html.escape(x, quote=True)
import json
AIRS = json.loads(re.search(r"const AIRS=(\[\[.*?\]\]);", src, re.S).group(1))   # the partner airlines: name, address part, code, region

def make_air(a):
    n, slug = a[0], a[1]
    t = "%s Business and First Class Fares | Fly First Class" % n
    d = "Business and first class fares on %s up to 70%% off market airfares, arranged by an advisor with 20+ years of experience. Request a free quote." % n
    u = "%s/airlines/%s/" % (SITE, slug)
    s = src
    s = sub1(r"<title>.*?</title>", "<title>%s</title>" % E(t), s)
    s = sub1(r'<meta name="description" content="[^"]*">', '<meta name="description" content="%s">' % E(d), s)
    s = sub1(r'<link rel="canonical" href="[^"]*">', '<link rel="canonical" href="%s">' % u, s)
    s = sub1(r'<meta property="og:url" content="[^"]*">', '<meta property="og:url" content="%s">' % u, s)
    s = sub1(r'<meta property="og:title" content="[^"]*">', '<meta property="og:title" content="%s">' % E(t), s)
    s = sub1(r'<meta property="og:description" content="[^"]*">', '<meta property="og:description" content="%s">' % E(d), s)
    s = sub1(r'<meta name="twitter:title" content="[^"]*">', '<meta name="twitter:title" content="%s">' % E(t), s)
    s = sub1(r'<meta name="twitter:description" content="[^"]*">', '<meta name="twitter:description" content="%s">' % E(d), s)
    crumb = ('<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":'
             '[{"@type":"ListItem","position":1,"name":"Home","item":"%s/"},{"@type":"ListItem","position":2,"name":"Listings","item":"%s/routes/"},'
             '{"@type":"ListItem","position":3,"name":"%s","item":"%s"}]}</script>\n' % (SITE, SITE, E(n), u))
    s = s.replace("<style>", crumb + "<style>", 1)
    s = sub1(r'<section class="pg" id="p-home">', '<section class="pg" id="p-home" hidden>', s)
    s = sub1(r'<section class="pg" id="p-airline" hidden>', '<section class="pg" id="p-airline">', s)
    return s

def sub1(pat, rep, s):
    out, n = re.subn(pat, lambda m: rep, s, count=1)
    assert n == 1, pat
    return out

def make(k):
    t, d = P[k]
    s = src
    s = sub1(r"<title>.*?</title>", "<title>%s</title>" % E(t), s)
    s = sub1(r'<meta name="description" content="[^"]*">', '<meta name="description" content="%s">' % E(d), s)
    u = SITE + PATHS[k]
    s = sub1(r'<link rel="canonical" href="[^"]*">', '<link rel="canonical" href="%s">' % u, s)
    s = sub1(r'<meta property="og:url" content="[^"]*">', '<meta property="og:url" content="%s">' % u, s)
    s = sub1(r'<meta property="og:title" content="[^"]*">', '<meta property="og:title" content="%s">' % E(t), s)
    s = sub1(r'<meta property="og:description" content="[^"]*">', '<meta property="og:description" content="%s">' % E(d), s)
    s = sub1(r'<meta name="twitter:title" content="[^"]*">', '<meta name="twitter:title" content="%s">' % E(t), s)
    s = sub1(r'<meta name="twitter:description" content="[^"]*">', '<meta name="twitter:description" content="%s">' % E(d), s)
    crumb = ('<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":'
             '[{"@type":"ListItem","position":1,"name":"Home","item":"%s/"},{"@type":"ListItem","position":2,"name":"%s","item":"%s"}]}</script>\n'
             % (SITE, CRUMB[k], u))
    s = s.replace("<style>", crumb + "<style>", 1)
    if k in NOINDEX:
        s = s.replace('<link rel="canonical"', '<meta name="robots" content="noindex,follow">\n<link rel="canonical"', 1)
    s = sub1(r'<section class="pg" id="p-home">', '<section class="pg" id="p-home" hidden>', s)
    s = sub1(r'<section class="pg" id="p-%s" hidden>' % k, '<section class="pg" id="p-%s">' % k, s)
    return s

for k in PATHS:
    if k == "home":
        continue
    d = os.path.join(ROOT, PATHS[k].strip("/"))
    os.makedirs(d, exist_ok=True)
    open(os.path.join(d, "index.html"), "w", encoding="utf-8").write(make(k))

for a in AIRS:
    d = os.path.join(ROOT, "airlines", a[1])
    os.makedirs(d, exist_ok=True)
    open(os.path.join(d, "index.html"), "w", encoding="utf-8").write(make_air(a))

today = datetime.date.today().isoformat()
urls = "".join("<url><loc>%s%s</loc><lastmod>%s</lastmod></url>\n" % (SITE, PATHS[k], today) for k in PATHS if k not in NOINDEX)
urls += "".join("<url><loc>%s/airlines/%s/</loc><lastmod>%s</lastmod></url>\n" % (SITE, a[1], today) for a in AIRS)
open(os.path.join(ROOT, "sitemap.xml"), "w").write('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + urls + "</urlset>\n")
open(os.path.join(ROOT, "robots.txt"), "w").write("User-agent: *\nAllow: /\nDisallow: /api/\n\nSitemap: %s/sitemap.xml\n" % SITE)
open(os.path.join(ROOT, "robots-test.txt"), "w").write("# Test site: keep out of search engines\nUser-agent: *\nDisallow: /\n")

og = os.path.join(ROOT, "og-image.png")
if not os.path.exists(og):
    from PIL import Image, ImageDraw, ImageFont
    im = Image.new("RGB", (1200, 630), "#ffffff"); dr = ImageDraw.Draw(im)
    dr.rectangle([0, 0, 1200, 12], fill="#c9b27c")
    # logo mark (same two shapes as favicon.svg), scaled
    sc = 7.5; ox, oy = 90, 120
    pt = lambda x, y: (ox + (x - 1) * sc, oy + (y - 1) * sc)
    dr.polygon([pt(3, 21), pt(27, 5), pt(21, 25), pt(15, 18)], fill="#c9b27c")
    dr.polygon([pt(15, 18), pt(27, 5), pt(11, 16)], fill="#a98f53")
    def F(sz, w):
        f = ImageFont.truetype("/usr/share/fonts/opentype/inter/Inter-Regular.otf", sz)
        try: f.set_variation_by_name(w)
        except Exception: pass
        return f
    dr.text((90, 380), "Fly First Class International", font=ImageFont.truetype("/usr/share/fonts/opentype/inter/Inter-Medium.otf", 58), fill="#14181f")
    dr.text((90, 462), "First and business class fares, up to 70% off market airfares", font=ImageFont.truetype("/usr/share/fonts/opentype/inter/Inter-Regular.otf", 30), fill="#59616f")
    dr.text((90, 540), "flyfirstclass.com", font=ImageFont.truetype("/usr/share/fonts/opentype/inter/Inter-Medium.otf", 26), fill="#8a6d2f")
    im.save(og, optimize=True)
print("built", [PATHS[k] for k in PATHS if k != "home"], len(AIRS), "airline pages")
