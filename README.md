# Keeping the Red Red

[![DOI](https://zenodo.org/badge/DOI/10.5281/zenodo.23413712.svg)](https://doi.org/10.5281/zenodo.23413712)

Colour loss, perceptual quality targets and deployment constraints in a
self-hosted storefront image pipeline. This repository holds the paper's
sources, the measurement tools it describes, the crawler of its multi-store
study and the data that study produced.

```
paper/        main.tex, refs.bib — the paper (acmart, sigconf, nonacm)
lib/          icc.php  — reads the embedded ICC profile of a JPEG, PNG or WebP file
                         and measures the colour shift when it is ignored (ΔE76 in CIELAB)
              http.php — one polite GET for the crawl scripts (named User-Agent, Range, pause)
crawl/        crawl.php — the multi-store measurement; data/ — the October 2026 run
calibration/  calibrate.php — WebP quality against SSIM, per product category
tools/        make-p3-profile.php — a synthetic Display P3 profile for validation
```

Everything is plain PHP 8 (curl and GD, with WebP) and runs without WordPress
or an imaging library.

## Quick start

```
php crawl/crawl.php --seeds=crawl/seeds.example.txt --out=results.csv --max-images=20
php crawl/cdn-convert-check.php crawl/data/results-all-2.csv
php calibration/calibrate.php photos/          # one sub-folder per category
php tools/make-p3-profile.php p3.icc in.jpg out-p3.jpg
```

Reading one file's profile from your own code:

```php
require 'lib/icc.php';
$profile = icc_parse( icc_from_file( 'photo.jpg' ) );   // null when untagged
echo icc_classify( $profile );                           // srgb | display-p3 | adobe-rgb | …
if ( $profile && ! $profile['srgb'] && $profile['matrix'] ) {
    print_r( colour_shift( colour_sample_pixels( 'photo.jpg', 48 ), $profile ) ); // mean, p95, over
}
```

## Manners

The crawler reads only public catalogues and public image files, honours
`robots.txt`, sends a User-Agent that names the study and a contact address,
pauses between requests and fetches only the header bytes of each full-size
file. See `crawl/README.md`.

## Licence

Code (`lib/`, `crawl/*.php`, `crawl/*.py`, `calibration/`, `tools/`): MIT, see
`LICENSE`. Paper sources and data (`paper/`, `crawl/data/`): Creative Commons
Attribution 4.0 (https://creativecommons.org/licenses/by/4.0/).

## Citing

The concept DOI 10.5281/zenodo.23413712 always resolves to the latest release; each release also has its own DOI (v1.0.1: 10.5281/zenodo.23413713).

```
@misc{trad2026redred,
  author = {Ahmed Trad},
  title  = {Keeping the Red Red: Colour Loss, Perceptual Quality Targets and Deployment
            Constraints in a Self-Hosted Storefront Image Pipeline},
  year   = {2026},
  note   = {Preprint. Tools and data: \url{https://doi.org/10.5281/zenodo.23413712}}
}
```
