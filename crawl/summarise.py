"""Merge the crawl CSVs and summarise: per store, per platform (inferred from how files are served),
per country (TLD), profile classes, colour shift and image width distributions."""
import csv, sys, glob, collections, statistics

files = [f for f in sys.argv[1:] if 'thumb-profiles' not in f]
thumb_files = [f for f in sys.argv[1:] if 'thumb-profiles' in f]
rows = []
for f in files:
    rows += list(csv.DictReader(open(f, encoding='utf-8')))
thumbs = []
for f in thumb_files:
    thumbs += list(csv.DictReader(open(f, encoding='utf-8')))

def platform(store_rows):
    served = collections.Counter(r['served_file'] for r in store_rows if r['status'] == 'ok')
    if not served:
        return 'none'
    return 'shopify-cdn' if served.most_common(1)[0][0] == 'cdn-processed' else 'self-hosted'

def tld(url):
    host = url.split('//')[-1].split('/')[0]
    return host.rsplit('.', 1)[-1]

stores = collections.OrderedDict()
for r in rows:
    stores.setdefault(r['store'], []).append(r)

ok = [r for r in rows if r['status'] == 'ok']
print(f"stores: {len(stores)} | with images: {sum(1 for s in stores.values() if any(r['status']=='ok' for r in s))} | images: {len(ok)}")
print("\nstore outcomes:", dict(collections.Counter(
    'images' if any(r['status']=='ok' for r in s) else s[0]['status'] for s in stores.values())))

print("\n%-32s %-12s %4s  %-40s %-28s %5s %6s" % ('store', 'platform', 'n', 'profiles', 'served', '<900', 'medKB'))
for store, s in stores.items():
    okr = [r for r in s if r['status'] == 'ok']
    if not okr:
        continue
    cls = collections.Counter(r['class'] for r in okr)
    served = collections.Counter(r['served_file'] for r in okr)
    widths = [int(r['width']) for r in okr if r['width']]
    kb = [int(r['bytes'])/1024 for r in okr if r['bytes']]
    small = sum(1 for w in widths if w < 900)
    print("%-32s %-12s %4d  %-40s %-28s %5d %6.0f" % (store.replace('https://',''), platform(okr), len(okr),
          ','.join(f"{k}:{v}" for k, v in cls.most_common()), ','.join(f"{k}:{v}" for k, v in served.most_common()),
          small, statistics.median(kb) if kb else 0))

print("\nby platform:")
for plat in ('self-hosted', 'shopify-cdn'):
    prow = [r for store, s in stores.items() if platform(s) == plat for r in s if r['status'] == 'ok']
    if not prow:
        continue
    cls = collections.Counter(r['class'] for r in prow)
    nst = sum(1 for s in stores.values() if platform(s) == plat)
    widths = [int(r['width']) for r in prow if r['width']]
    print(f"  {plat:12s} stores {nst:3d} images {len(prow):5d} | " + ', '.join(f"{k} {v} ({100*v/len(prow):.1f}%)" for k, v in cls.most_common())
          + f" | width median {statistics.median(widths):.0f}, <900px {100*sum(1 for w in widths if w<900)/len(widths):.1f}%")

uploads = [r for r in ok if r['served_file'] == 'upload']
print(f"\nfiles that are the upload itself: {len(uploads)} from {len(set(r['store'] for r in uploads))} stores")
cls = collections.Counter(r['class'] for r in uploads)
for k, v in cls.most_common():
    print(f"  {k:14s} {v:5d} ({100*v/max(1,len(uploads)):.1f}%)")
wide = [r for r in uploads if r['class'] not in ('none', 'srgb')]
print(f"  wide-gamut uploads: {len(wide)} = {100*len(wide)/max(1,len(uploads)):.1f}% of uploads, in {len(set(r['store'] for r in wide))} stores")

des = sorted(float(r['de_mean']) for r in ok if r['de_mean'])
over = sorted(int(r['de_over_2_3']) for r in ok if r['de_over_2_3'])
if des:
    q = lambda p: des[min(len(des)-1, int(p*len(des)))]
    print(f"\ncolour shift of non-sRGB images (n={len(des)}): mean dE median {q(.5):.1f}, p90 {q(.9):.1f}, max {des[-1]:.1f}; "
          f"share of pixels past 2.3: median {over[len(over)//2]}%, images with >50% of pixels past 2.3: {sum(1 for o in over if o > 50)}")

print("\nby country (TLD), stores with images:")
byc = collections.defaultdict(lambda: {'stores': 0, 'images': 0, 'wide': 0, 'uploads': 0})
for store, s in stores.items():
    okr = [r for r in s if r['status'] == 'ok']
    if not okr:
        continue
    c = byc[tld(store)]
    c['stores'] += 1; c['images'] += len(okr)
    c['uploads'] += sum(1 for r in okr if r['served_file'] == 'upload')
    c['wide'] += sum(1 for r in okr if r['class'] not in ('none', 'srgb'))
for k, c in sorted(byc.items(), key=lambda kv: -kv[1]['images']):
    print(f"  .{k:6s} stores {c['stores']:3d} images {c['images']:5d} uploads {c['uploads']:5d} wide-gamut {c['wide']:3d}")

if thumbs:
    print("\nwhat the host's resized copy does with a wide-gamut upload (post-pass on the thumbnails):")
    byst = collections.defaultdict(collections.Counter)
    for t in thumbs:
        byst[t['store'].replace('https://', '')][t['thumb_profile']] += 1
    for st, c in byst.items():
        kinds = set(c)
        if kinds == {'srgb'}:
            verdict = 'converts resized copies to sRGB (colour-managed)'
        elif kinds == {'none'}:
            verdict = 'drops the profile on resize (colour lost on screen)'
        elif 'none' not in kinds and 'srgb' not in kinds:
            verdict = 'keeps the profile on resize (browsers honour it)'
        else:
            verdict = 'mixed'
        print(f"  {st:32s} {dict(c)}  → {verdict}")

widths = sorted(int(r['width']) for r in ok if r['width'])
if widths:
    print(f"\nimage width (full size as served): median {widths[len(widths)//2]}, p10 {widths[len(widths)//10]}, p90 {widths[9*len(widths)//10]}; "
          f"<600 px {100*sum(1 for w in widths if w<600)/len(widths):.1f}%, <900 px {100*sum(1 for w in widths if w<900)/len(widths):.1f}%, <1200 px {100*sum(1 for w in widths if w<1200)/len(widths):.1f}%")
