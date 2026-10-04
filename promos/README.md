# Plugin Store promo images

Seven 1920×1080 JPEGs for the Library listing, in the theme of the plugin's page at
[justinholt.com/plugins/craft-library](https://justinholt.com/plugins/craft-library).

```bash
./build.sh          # all slides
./build.sh "1 2 6 7"  # just those
```

Output lands in `out/` as `library-promo-N.jpg`: rendered at 2× in headless Chrome, then downsampled
and converted to JPEG in one `sips` pass. Promos are never PNG.

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover: name, tagline, icon, Lite free · Pro $79 · $59/yr badges | — |
| 2 | File by what it is: one file in several collections; one rule in four places | the pitch |
| 3 | Collections in the asset index sidebar | `shots/library-index.png` — **not captured yet** |
| 4 | The Library panel on an asset | `shots/library-asset-panel.png` — **not captured yet** |
| 5 | Library → Audit → Duplicates | `shots/library-duplicates.png` — **not captured yet** |
| 6 | Pro features grid | — |
| 7 | Closing grid: Lite, Pro, content not schema, hands off your content | — |

## Still to do: slides 3–5

They need real CP screenshots from the plugin-testing harness (Library on Pro). Demo data is already
there: collections Brand › Logos, Campaigns › Autumn 2025 / Spring 2026, Editorial, Press kit and
Team photos, `editorial/harbour-cranes.jpg`, and duplicate copies under `library-demo/uploads/2024/`.

On 2026-10-03 the harness could not render CP pages reliably: it can't write its project-config YAML
(a stale Visorr entry type file is unwritable), and Craft's pending-changes alert then throws on CP
pages. Fix that, then capture at 2× and hide the Puppy panel and the config banner.

- `library-index.png`: Assets with the **Spring 2026** collection source selected (1600×1000).
- `library-asset-panel.png`: `assets/edit/<harbour-cranes id>`, framed on the Library panel.
- `library-duplicates.png`: `library/audit/duplicates`.

## Assets

- `assets/icon.svg` is a straight copy of `../src/icon.svg`.
- `assets/icon-source.jpg` is Justin's original raster art the icon was redrawn from. It is not used
  by the deck; it lives here so it doesn't ship in `src/`.
