# Colour-profile crawl

Measures, over live stores, how many product images carry a colour profile
other than sRGB, how far their colours shift when that profile is ignored, and
how wide the served files are. It is the multi-store study of section 5.5 of
the paper; the method is the paper's sections 3.3 and 3.4, run on files
fetched from each store's public catalogue instead of its uploads folder.

Plain PHP 8 with curl and GD. No WordPress, no imaging library: the profile
parser and the colour maths are in `../lib/icc.php`, the polite HTTP client in
`../lib/http.php`.

## Run

```
php crawl.php --seeds=seeds.txt --out=results.csv --max-products=50 --max-images=100 --delay=1.0
php crawl.php --pages=urls.txt  --out=results.csv
```

- `seeds.txt`: one store URL per line, `#` for comments. A store is read
  through the first catalogue that answers: the WooCommerce Store API,
  Shopify's public `/products.json`, or a WordPress product sitemap
  (`/wp-sitemap-posts-product-1.xml` from core, `/product-sitemap.xml` from
  Yoast SEO and Rank Math), whose product pages are then read as pages.
- `urls.txt`: product-page addresses on any platform (PrestaShop, Magento,
  custom). The page's own images come from its Open Graph tag and JSON-LD
  product data; its `<img>` tags are read only when it declares neither.
- A run can be stopped and restarted: stores already in the CSV are skipped.

Post-passes:

```
php thumb-profile.php thumbs.csv results-1.csv results-2.csv   # does the host's resized copy keep the profile?
php cdn-convert-check.php results.csv 2                        # does Shopify's CDN convert the pixels, or only re-tag?
python summarise.py data/results-all-*.csv data/thumb-profiles-*.csv
```

## What it fetches, and manners

Per store: `/robots.txt`, then the product list from the public catalogue (no
login), then for each product image the first 128 KB of the full-size file (a
`Range` request: the ICC profile sits in the file header) and, only for a
non-sRGB profile, the small thumbnail, to measure the shift and to record
whether the resized copy kept the profile. One request at a time, a pause
between requests (`--delay`), a User-Agent naming the study and a contact
address, `robots.txt` honoured, at most `--max-images` files per store, three
failed images and the store is left alone. Product images only; no personal
data. The October 2026 run identified itself as `HoorColourStudy/0.1`; the
scripts now send `ColourProfileStudy/0.2`.

## What it cannot see

A store hands out the files it serves. A host that drops the profile when it
resizes (GD does) serves an untagged file, so the profile is invisible there. A
full-size file with `-scaled` in its name went through the host's editor, a
WebP or AVIF full size is a conversion, and an image CDN re-encodes what it
serves; the CSV marks each file as `upload`, `scaled-by-host`,
`converted-by-host` or `cdn-processed`, and the summary reports prevalence
separately for files that are the upload itself, which is the closer estimate
of what merchants upload. A photograph stripped of its profile before upload
(by a messaging app, say) is untagged everywhere and indistinguishable from an
sRGB one.

## Seeds

The script takes no position on where store addresses come from. For a
documented sampling frame, the HTTP Archive's monthly crawl on BigQuery lists
every site it saw with the technologies it detected and a popularity rank; it
is public and citable, and the free monthly quota covers this query:

```sql
SELECT root_page, rank,
       (SELECT STRING_AGG(t.technology) FROM UNNEST(technologies) t
         WHERE t.technology IN ('WooCommerce', 'Shopify')) AS platform
FROM `httparchive.crawl.pages`
WHERE date = '2026-10-01' AND client = 'mobile' AND is_root_page
  AND EXISTS (SELECT 1 FROM UNNEST(technologies) t WHERE t.technology IN ('WooCommerce', 'Shopify'))
  AND (ENDS_WITH(NET.HOST(root_page), '.es') OR ENDS_WITH(NET.HOST(root_page), '.fr')
       OR ENDS_WITH(NET.HOST(root_page), '.de') OR ENDS_WITH(NET.HOST(root_page), '.co.uk'))
  AND rank <= 1000000
ORDER BY rank
LIMIT 5000
```

Sample by platform and rank band from that list (for example 150 WooCommerce
and 150 Shopify stores, 50 images each). Hand-picked niche lists, such as the
European modest-fashion stores used for the October 2026 run, are right for a
comparison between kinds of store, not for a prevalence estimate: the unit
that varies is the store, and 26 stores give wide intervals. A list of 217
such addresses contained 68 that resolved; check reachability first
(`curl -sL -o /dev/null -w '%{http_code}'`) before spending crawl time.

Whatever the source, only public catalogues and public image files are read.

## Results (15 October 2026)

68 reachable stores from the modest-fashion list: 26 with a public catalogue
(23 Shopify, 3 WooCommerce), 39 without, 3 forbidding it in robots.txt;
2,419 full-size images measured. Wide-gamut profiles on 13 images (0.5%) in
two stores; Shopify converts its resized copies to sRGB (verified pixel by
pixel with `cdn-convert-check.php`: the CDN copy is ΔE 1.2–1.3 from the
original read through its profile, 3.0–5.5 from it read as sRGB), the
WooCommerce store keeps the profile on resize, so no loss on screen was
observed. 85% of self-hosted uploads carry no profile at all. 18.6% of
full-size images are narrower than 900 px, 38.3% narrower than 1,200 px. The
data files are in `data/` (`results-all-*.csv`, `thumb-profiles-*.csv`,
`liveness.txt`, `seeds-live.txt`, `summary.txt`); `summarise.py` reproduces
the summary.

## Output

`results.csv`: one row per image (`status` = `ok`), plus one row per store or
page that was skipped (`robots`, `no-catalogue`, `no-images`, `page-error`) or
per image that failed (`image-error`). Columns: store, status, product_id,
image_id, src, served_file, content_type, bytes, width, height, profile, class,
de_mean, de_p95, de_over_2_3, thumb_bytes, thumb_profile, note. The summary
printed at the end counts stores, images, profile classes and the ΔE
distribution over the whole CSV.
