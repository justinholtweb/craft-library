# Release Notes for Library

## 5.0.0 — 2026-08-18

Initial release.

### Added

- **Collections** — a taxonomy for assets that works across volumes and folders, nested to any
  depth, with a colour and a description each. Stored in the database rather than project config,
  because collections are content that editors create, not schema that developers deploy.
- **Collection sources** in the asset index sidebar and in asset field selector modals, nested to
  match the tree, with asset counts.
- **Condition rules** — Collection, Orientation, Is used anywhere (Pro) and Has duplicate copies
  (Pro). These appear in the asset index filter toolbar, in asset field selector modals, in asset
  field settings and in custom sources, because Craft asks the same condition class in all four.
- **Bulk actions** — add to collections, remove from collections, set alt text, rename files (Pro)
  and move to another volume (Pro).
- **The Library panel** on the asset edit screen: collections, and on Pro a list of every place the
  asset is used.
- **Usage tracking** (Pro) — relations read live from Craft's own index, plus a scanner for
  `{asset:12:url}` reference tags typed into content, which nothing indexes.
- **Duplicate detection** (Pro) — size-first, hash-second, with a merge that repoints every relation
  onto the original before the copy is trashed.
- **Alt text audit** — a "Missing alt text" source, an audit screen, and bulk filling from filenames
  or titles that never overwrites a description somebody wrote.
- **Upload rules** (Pro) — file new uploads into collections automatically, by volume, folder,
  filename pattern or file kind, and optionally seed their alt text.
- **Console commands** — `library/usage/scan`, `library/usage/unused`, `library/audit/hash`,
  `library/audit/duplicates`, `library/audit/prune`, `library/alt/report`, `library/alt/fill`.
- **`craft.library`** Twig variable: `collections()`, `collection()`, `assets()`, `collectionsFor()`,
  `isIn()` and `usageCount()`.
