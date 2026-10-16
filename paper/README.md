# Keeping the Red Red — paper sources

Colour loss, perceptual quality targets and deployment constraints in a
self-hosted storefront image pipeline. A single-author study by an independent
researcher; the store it was carried out on is not named.

## Files

- `main.tex` — the paper, ACM `acmart` class, `sigconf` layout with the
  `nonacm` option (no publisher block, suitable for arXiv and for an
  independent author).
- `refs.bib` — the references. Web sources carry an "accessed" date.

The measurement tools, the crawler, the calibration script and the crawl data
the paper cites live beside this folder: `../lib`, `../crawl`,
`../calibration`, `../tools`.

## Compile

Either:

- **Overleaf**: new project → upload both files → compiler pdfLaTeX. The
  `acmart` class and `pgfplots` are preinstalled.
- **Locally**: install TeX Live or MiKTeX, then

  ```
  latexmk -pdf main.tex
  ```

  (pdflatex → bibtex → pdflatex ×2). `acmart` needs TeX Live 2023 or later.

## Before submitting

1. Confirm the author line and e-mail in `main.tex`.
2. Replace `P1`–`P5` in the colour table with the real file names if the venue
   allows, or keep the labels and attach the images as supplementary material.
3. arXiv: a first submission to a `cs.*` category needs an endorsement from an
   existing arXiv author in that category; `cs.MM` (multimedia) or `cs.HC` fit.
   Zenodo gives a DOI without endorsement.
4. For a double-blind venue, switch the class options to
   `sigconf,anonymous,review` and remove the repository link from the
   Availability section.
5. The two figures are drawn by `pgfplots` from numbers in the text (the
   calibration table; the served-width distribution in `../crawl/data`);
   editing those numbers means editing the coordinates too.

## Venues considered

- IS&T Color and Imaging Conference, or Electronic Imaging (Image Quality and
  System Performance): best fit for the colour-loss method and measurement.
- QoMEX or Picture Coding Symposium: fit once the SSIMULACRA 2 port and its
  benchmarks exist.
- ACM Web Conference (industry / short) or ICWE: fit for the multi-store
  measurement once it has a documented sampling frame.
- arXiv (`cs.MM`) as a citable preprint at any stage.
