---
title: Usage
slug: usage
order: 30
summary: Collections, filters, sidebar sources, bulk editing, usage tracking, duplicates, alt text, upload rules, Twig and GraphQL.
---

## Collections

A collection groups assets regardless of which volume or folder they are in. A logo can be in **Brand** whether it
sits in `/uploads/2024/` or `/press/`, and one asset can be in as many collections as you like.

Collections are metadata. Filing an asset never moves its file, and the folder it lives in stays
Craft's business.

Collections are also **content, not schema**. They live in Library's own database tables and never
touch project config, so an editor can make one on production without a deployment. That has a
consequence for conditions you build in development — see
[Troubleshooting](troubleshooting#a-field-restricted-to-a-collection-accepts-everything).

Each collection has:

| Field | Notes |
|---|---|
| **Name** | Required |
| **Handle** | Required, unique, filled in from the name. Used in templates: `craft.library.assets('logos')` |
| **Description** | Shown on the collections screen, so editors know what belongs |
| **Nested under** | Optional parent. A collection cannot be nested inside itself or one of its own children |
| **Colour** | One of Craft's status colours. Shows as a dot in the sidebar and on asset chips |

**Nesting means inclusion.** Everywhere in Library, a collection includes what is filed in its
children: the sidebar source, the count badge, the **Collection** filter and
`craft.library.assets()`. "Everything under Brand" is the reason to nest a collection in the first
place.

**Deleting a collection** unfiles its assets and deletes nothing else. Its child collections move
up to the top level and keep their contents.

**Counts** leave out assets in the trash. A soft-deleted asset keeps its collection membership, so
restoring it from the trash puts it back where it was.

Collections are listed alphabetically.

## Filing assets

There are three ways to file assets:

- **One asset at a time**: the Library panel on the asset's edit screen has a checkbox per
  collection. Ticking one saves straight away. There is no Save button to remember, and it doesn't
  mark the asset as having unsaved changes.
- **In bulk**: select assets in the index and use **Add to collections** or **Remove from
  collections**. Three hundred assets are filed in one insert.
- **Automatically, on upload** (Pro): [upload rules](#upload-rules-pro).

You can't upload *into* a collection source. A collection isn't a folder, and Craft needs a folder
to put the file in. Upload to a folder as usual, then file it, or let an upload rule do it.

## Filters that work everywhere

Library adds its filters to Craft's own asset condition. Craft uses that same condition in four
places, so every Library rule appears in all of them:

1. the **filter toolbar** in the asset index
2. the **selector modal** an editor sees when picking assets for an asset field
3. an asset field's **Selectable assets** condition in its settings, which restricts what the field
   will accept
4. **custom sources** you build under **Customize sources** in the asset index

| Rule | Edition | What it matches |
|---|---|---|
| **Collection** | All | Assets in the chosen collections or anything nested inside them. Operators: *is one of*, *is not one of*, *is empty*, *has a value* |
| **Orientation** | All | Landscape, portrait or square. Assets with no recorded width or height (most non-images) never match |
| **Is used anywhere** | Pro | On: something points at the asset. Off: nothing does — see [Usage tracking](#usage-tracking-pro) |
| **Has duplicate copies** | Pro | The asset is byte-identical to at least one other asset that isn't in the trash. The original matches too, not just the copies |

**Collection → is empty** is your *Uncategorised* view: every asset nobody has filed yet.

All four rules run in SQL. They combine with each other, with search and with Craft's own rules,
and they stay fast on a large library.

Library doesn't duplicate rules Craft already ships. File size, file type, filename, width, height,
volume, uploader, dates and **Has alternative text** are Craft's own, and they all sit in the same
toolbar.

### Examples

- **A hero image field that only accepts landscape images from Brand:** in the field's settings,
  add **Collection** *is one of* Brand and **Orientation** *is* Landscape to its selectable assets
  condition.
- **A custom "Unfiled images" source:** **Collection** *is empty* plus Craft's **File type** *is*
  Image.
- **Safe-to-delete candidates (Pro):** **Is used anywhere** off, plus Craft's **Date uploaded**
  more than a year ago.

## Sidebar sources

Under a **Library** heading in the asset index sidebar:

- **One source per collection**, nested to match the tree, with an optional count badge.
- **Missing alt text**: every image with no alternative text.

These are also shown in asset selector modals unless you turn that off. Users need **View
collections and asset usage** to see them.

**Unused** and **Duplicates** are deliberately *not* sidebar sources. A source has to be exact.
Craft enforces a source's criteria, and Library will not ship a source labelled "Unused" whose
contents are only approximately unused. Neither set can be expressed in Craft's native asset
query parameters, and either can run to thousands of rows. They are on the
**Is used anywhere** and **Has duplicate copies** filter rules, which are exact because they run in
SQL, and on the Audit screen. If you want a one-click view, build a custom source from those rules.

## Index columns

Add these from the asset index's table view column settings:

- **Collections**: the asset's collections, as coloured chips.
- **Used in** (Pro): how many places use the asset, or a red **Unused** label.

Both load once for the whole page, not once per row, so adding them doesn't slow the index down as
it grows.

## Bulk editing

Select assets in the asset index and open the actions menu.

| Action | Edition | Permission |
|---|---|---|
| **Add to collections** | All | Add assets to collections |
| **Remove from collections** | All | Add assets to collections |
| **Set alt text** | All | Bulk edit assets |
| **Rename files** | Pro | Bulk edit assets |
| **Move to volume** | Pro | Bulk edit assets |

Every action only touches the selected assets the current user is allowed to save. The rest are
left alone.

### Set alt text

This fills in alt text from **the filename** or **the title**, for the selected **images** only.
Other file kinds are skipped.

Existing alt text is left alone unless you tick **Replace alt text that is already there**.
A description somebody wrote beats anything derived from a filename, so that box is off by default.

Alt text is per site. This writes to the site you are viewing and doesn't copy the same string
over every other site's translation.

Filenames are cleaned up: separators become spaces, camelCase is split, and camera counters and
words like *final*, *copy*, *edited*, *v3*, *DSC* and *IMG* are dropped. Years are kept.
`brand-logo_dark-2024_FINAL-v3.png` becomes **Brand logo dark 2024**.

The result is a first draft. It is better than an empty attribute for somebody using a screen reader, and it gives an
editor something to correct rather than a blank field to find. Read it before you rely on it.

### Rename files (Pro)

Renames the selected files on disk using a pattern. **Preview only** is ticked by default. With it
on, the action changes nothing and reports what *would* happen: how many files would be renamed,
the first few `old → new` examples, and any problems. The report appears as a notice you have to dismiss, not a
green tick, so it doesn't read as done. Untick **Preview only** and confirm to apply it.

| Token | Becomes |
|---|---|
| `{basename}` | Current filename without the extension |
| `{ext}` | File extension, without the dot |
| `{title}` | Asset title |
| `{id}` | Asset ID |
| `{index}` | Position in the selection, from 1 |
| `{volume}` | Volume handle |
| `{folder}` | Folder name |
| `{kind}` | File kind, e.g. `image` |
| `{width}` `{height}` | Image dimensions in pixels |
| `{year}` `{month}` | When the file was uploaded; month zero-padded |
| `{collection}` | Handle of the asset's first collection |

If the pattern doesn't mention `{ext}` and produces no extension, the file keeps its original one.
`press-{index}` renames `photo.jpg` to `press-1.jpg`, not to `press-1`.

Files that would end up with the same name as another file in the selection, or as a different file
already in the folder, are **skipped**, not half-renamed. The preview names the first problem.
Adding `{index}` to the pattern usually fixes it.

Renaming changes the file's URL. Asset fields and reference tags like `{asset:412:url}` resolve by
ID and keep working. A URL somebody pasted in as plain text does not.

### Move to volume (Pro)

Craft can move assets between folders in one volume from the index, but not between volumes.
Doing it by hand means downloading, re-uploading and re-picking the file everywhere it was used,
because a re-upload is a new element.

**Move to volume** keeps the same element. Every relation, collection and reference tag still
resolves afterwards. Choose a target volume, and optionally a **Subfolder** path. Missing
folders are created. A file whose name is already taken in the target folder gets `-1`, `-2`
and so on, instead of failing the whole move.

Only volumes you can save assets in are offered.

## Usage tracking (Pro)

"Is anything pointing at this file?" is what you need to know before you delete it. Library
answers from two places, kept deliberately apart:

- **Relations**: Craft's own relations table, read live on every query. Asset fields, and the
  asset references CKEditor and Redactor record, all land here, so this half is always current.
- **Reference tags**: `{asset:412:url}` or `{asset:<uid>:url}` typed by hand into a text field,
  plus legacy `data-asset-id` markup. Craft doesn't index those, so Library finds them by reading
  content and stores what it finds. This half is kept current as content saves, if **Track usage as content is
  saved** is on, and rebuilt in full by a scan. Run a scan once after installing Pro:
  **Library → Audit → Scan content now**, or `php craft library/usage/scan`.

**Unused** means neither half has anything.

**Drafts and revisions are excluded** from both halves, everywhere: the count, the list, the
**Is used anywhere** rule and the unused list all share one definition. On a site with revisions turned on,
revision rows outnumber real content several times over. Counting them would make almost every
asset look used, including one that was removed from the live entry months ago. An asset used
*only* in an unpublished draft therefore reads as unused. Elements in the trash don't count either.

A disabled entry still counts. Disabling something isn't deleting it.

### Where usage shows up

- **The Library panel** on an asset lists every place it is used. If the reference is in a Matrix
  block or another nested element, the panel shows and links to the entry that owns it. Reference-tag uses are labelled as such.
- **The Used in column** in the asset index.
- **Library → Audit → Not used anywhere**: a paged list of every unused asset, excluding
  [excluded volumes](configuration#excluded-volumes).
- **`craft.library.usageCount(asset)`** in templates.

### What unused does not know about

Read **unused** as "nothing in the CMS points at it", not "nobody uses it". Library can't see:

- a file linked from a template by path or URL
- a URL pasted into a text field as plain text, not as a reference tag
- a volume-and-path reference tag such as `{asset:images/logo.png:url}`. Resolving those means
  asking the filesystem for every row, so the scan skips them.
- assets another plugin stores in its own tables, or a user's profile photo
- a PDF that was emailed out, or a favicon referenced from your HTML

Check before you delete anything.

## Duplicate detection (Pro)

Library finds files that are **byte-for-byte identical**. They might be the same logo uploaded three times to
three folders, or the same PDF in two volumes.

Files are compared by size first, and only hashed when another file has exactly the same size. Hashing reads the
whole file, which on a remote volume means downloading it, so nothing is read unless it has a
possible twin. Files over **Maximum file size to hash** are left out.

Hashing runs in batches:

- **Library → Audit → Hash now** runs batches from the browser until it is done, showing progress.
- `php craft library/audit/hash` does the same from the command line. Use this one on a large library
  or from cron.

Until hashing is finished, the duplicate count is a minimum, not a total, and the Duplicates screen
says so.

### Merging a duplicate

**Library → Audit → Duplicates** lists groups of identical files with the oldest first. The oldest
file is treated as the original. Each copy shows how many places use it, and has **Merge into
original**, which:

1. repoints every relation from the copy to the original, so asset fields that showed the
   copy now show the original
2. moves Library's usage records for the copy onto the original
3. files the original into every collection the copy was in
4. moves the copy to the trash

It is a soft delete, so you can restore the copy from Craft's trash.

**Reference tags typed into text are not rewritten.** A `{asset:412:url}` that names the copy
still names the copy afterwards, and stops resolving once the copy is trashed. Library won't
edit somebody's prose silently. Check the copy's **Used in** list for entries marked *reference tag*
before you merge, and fix those by hand.

## Alt text

On every edition:

- the **Missing alt text** sidebar source
- the **Set alt text** bulk action
- `php craft library/alt/report` and `php craft library/alt/fill` — see [Console](console)

On Pro, **Library → Audit → Alt text** adds:

- counts for the current site: images, described, missing
- a paged list of undescribed images, with what each would become if filled from its filename
- **Fill in the blanks**, which fills up to 200 images per click from the filename or title. It
  never overwrites existing alt text, and it needs the **Bulk edit assets** permission. For more
  than 200, click it again or use the console command.

Alt text is per site in Craft, so every count and every fill applies to one site. Switch sites in
the control panel to audit another one. Only images are counted. A PDF with no alt text isn't an
accessibility problem, and counting documents would make the number meaningless.

## Upload rules (Pro)

Media libraries turn back into piles because somebody has to remember to file each upload. Upload
rules file it when it arrives.

**Library → Upload rules → New rule.** A rule matches on any combination of:

| Field | Notes |
|---|---|
| **Volume** | One volume, or any |
| **Folder path** | The folder path inside the volume, without leading or trailing slashes. `*` and `?` work: `brand/*` matches anything under `brand` |
| **Filename** | `*` and `?` work, case-insensitively: `*-icon.svg` |
| **File kinds** | Image, PDF, video and so on. None ticked means all |

Blank fields are ignored, so a rule with everything blank matches every upload. Use one to put
everything in an *Inbox* collection.

When a rule matches, it:

- files the asset into the chosen **collections**
- optionally fills in **alt text** from the filename or title. This only fills an empty alt
  attribute and never replaces one somebody wrote

A rule must do at least one of those two things.

**Rules add up.** Every matching rule adds its collections, so "everything in the Press volume is
Press kit" and "anything called `*-icon.svg` is Icons" can both apply to the same file. Alt text is
the exception: the first matching rule that sets a source wins, because a file has only one
description. If no rule sets one, the [default alt text source](configuration#default-alt-text-source)
applies. A rule set to **Use the default from settings** has no opinion and defers to the next
matching rule. A rule set to **Leave alone** does have an opinion: it wins, and the file gets no
alt text, which is how you keep generated descriptions off a volume of decorative textures.

Rules run in the order they were created.

### When rules run

When a file **arrives in a volume**:

- uploaded directly into a folder in the asset index: straight away
- uploaded through an **asset field** on an entry: when the entry is saved. Until then the file
  sits in Craft's temporary uploads folder, which belongs to no volume, and the editor may never
  save the entry. Rules run when the file moves into its real volume.

Rules don't run for drafts or revisions, for later saves of an asset that is already in the
library, or while **Apply upload rules** is off.

### Backfill

A new rule only affects new uploads. To apply it to files already in the library, click
**Backfill** beside the rule. It runs on Craft's queue, works through every asset in the rule's
volume in batches, and does what the rule does: files the matches into its collections and fills
in empty alt text if the rule has an alt source. It only touches assets that you, the person who
clicked it, are allowed to save.

Deleting a rule leaves assets where it filed them.

## Twig

`craft.library` is available in front-end templates on every edition.

```twig
{# Everything filed anywhere under a collection, as a normal asset query #}
{% for image in craft.library.assets('logos').kind('image').limit(12).all() %}
  <img src="{{ image.url }}" alt="{{ image.alt }}">
{% endfor %}

{# Several collections at once #}
{% set press = craft.library.assets(['press-kit', 'logos']).all() %}

{# One collection, by handle #}
{% set brand = craft.library.collection('brand') %}

{# The whole tree #}
{% for collection in craft.library.collections() %}
  {{ collection.name }} ({{ collection.children|length }} nested)
{% endfor %}

{# What is this asset filed under? #}
{% for collection in craft.library.collectionsFor(image) %}{{ collection.name }}{% endfor %}

{% if craft.library.isIn(image, 'brand') %}…{% endif %}

{# Pro; null on Lite #}
{{ craft.library.usageCount(image) }}
```

| Method | Returns |
|---|---|
| `assets(handle \| collection \| [handles])` | An `AssetQuery`, including nested collections. An unknown handle returns no assets, not every asset |
| `collection(handle)` | One collection, or `null` |
| `collections()` | The top-level collections, each with `children` |
| `collectionsFor(asset)` | The collections an asset is in. Takes an asset or an ID |
| `isIn(asset, handle \| collection)` | Whether the asset is in that collection or one nested inside it |
| `usageCount(asset)` | How many places use the asset. Pro; `null` on Lite |

A collection has `id`, `name`, `handle`, `description`, `color`, `parentId` and `children`.

`craft.library.assets()` returns a normal `AssetQuery`, so you can chain `.kind()`, `.orderBy()`,
`.limit()`, eager loading and anything else onto it.

## GraphQL

Headless front ends get collections through Craft's GraphQL API, on every edition. Both pieces
sit behind one schema permission: open the schema under **Settings → GraphQL → Schemas** and tick
**Library → Query for collections**. Without it, neither the query nor the argument exists in
that schema.

### Filtering assets by collection

Craft's `assets`, `asset` and `assetCount` queries take a `libraryCollection` argument. It accepts
collection handles or UIDs, one or a list, and includes the collections nested inside the ones you
name:

```graphql
{
  assets(libraryCollection: "logos", kind: "image", limit: 12) {
    url
    alt
  }
  assetCount(libraryCollection: ["press-kit", "logos"])
}
```

It narrows Craft's own query rather than replacing it. Every other argument still applies, and the
results are still limited to the volumes the schema can read. A collection that spans three volumes
returns only the files in the volumes that schema was given.

A handle that matches no collection returns no assets. It does not return the whole library. An
empty list (`libraryCollection: []`) means no filter, the same as leaving the argument out.

The argument is on the top-level asset queries only. Asset fields nested inside an entry don't
take it. To narrow those, filter the top-level `assets` query by `relatedToEntries` as well.

### Listing collections

```graphql
{
  libraryCollections {
    handle
    name
    level
    parent { handle }
    children { handle }
    assetCount
  }
}
```

`libraryCollections` returns every collection in tree order, each parent followed by the collections
nested inside it. Its arguments:

| Argument | Narrows to |
|---|---|
| `handle: [String]` | Collections with these handles |
| `uid: [String]` | Collections with these UIDs |
| `id: [Int]` | Collections with these IDs |
| `topLevel: Boolean` | Only the top-level collections. Walk `children` for the rest |

A collection has `id`, `uid`, `name`, `handle`, `description`, `color`, `parentId`, `level` (1 at the
top), `parent`, `children` and `assetCount`. `assetCount` counts the files in the collection and
everything nested inside it, in the volumes the schema can read. On a schema with no volumes, it is 0.

## Console

See [Console](console) for every command and its options.
