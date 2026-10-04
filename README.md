# Library

A better media library for Craft CMS 5.

Craft's asset index is a file browser: volumes, folders, and a search box. That is the right model
for *where a file lives* and the wrong model for *what a file is*. A logo belongs in "Brand" whether
it sits in `/uploads/2024/` or `/press/`, and the question an editor actually asks — "where are the
team photos?" — has no answer in a folder tree.

Library adds the missing half. It is the Craft answer to WordPress's
[Enhanced Media Library](https://wordpress.org/plugins/enhanced-media-library/): a real taxonomy for
assets, filters that work everywhere assets are chosen, and bulk editing that does not mean opening
three hundred files one at a time.

Distributed as `justinholtweb/craft-library`. **Lite is free · Pro $79, then $59/year for updates.**

## What it does

### Collections

A collection is a group of assets that cuts across volumes and folders. They nest, they carry a
colour and a description, and they show up as sources in the asset index sidebar — including inside
the selector modal that opens when an editor picks an image for an entry, which is where the folder
tree is least useful and most often the only thing on offer.

Collections are content, not schema. They live in database tables and never touch project config, so
an editor can make one without a deployment.

### Filters that work everywhere

Library registers condition rules on Craft's own asset condition. That single hook puts **Collection**,
**Orientation**, **Is used anywhere** and **Has duplicate copies** into:

- the asset index filter toolbar
- asset field selector modals
- asset field settings, to restrict what a field will accept
- custom sources a developer builds by hand

…because Craft asks the same condition class in all four places. Everything is enforced in SQL, so
the filters compose with each other, with search, and with Craft's own rules.

Library deliberately does not duplicate the rules Craft already ships — file size, file type,
filename, width, height, volume, uploader, date and *has alternative text* are all Craft's, and are
already good.

### Bulk editing

Select assets in the index and:

- **Add to / remove from collections** — one insert, not one per file
- **Set alt text** from the filename or the title
- **Rename files** with a pattern (Pro) — previews by default
- **Move to another volume** (Pro) — keeping the element, so nothing that referenced the file breaks

### Usage tracking · Pro

"Is anything actually pointing at this?" is the question you have to answer before you dare delete
anything, and Library answers it from two places kept deliberately apart:

- **Relations** — read live from Craft's own index on every query. Asset fields, and the asset
  references CKEditor and Redactor record, all land there, so this half is always current.
- **Reference tags** — `{asset:412:url}` typed by hand into a text field. Nothing indexes those, so
  they are found by a scan and cached. This half is only as fresh as the last scan, and the UI says
  so rather than pretending otherwise.

Drafts and revisions are excluded from both. On a site with revisions on they outnumber real content
several times over, which is enough to make almost every asset look used.

### Duplicate detection · Pro

Files are compared by size first and only hashed when a size is shared, so nothing is read unless it
has a possible twin — which matters, because reading a file on a remote volume means downloading it.

Resolving a duplicate **repoints every relation onto the original** and then moves the copy to the
trash. Fields that pointed at the copy keep working, and the delete is a soft one, so the decision is
reversible. A reference tag typed into text by hand (`{asset:412:url}`) is reported, not rewritten —
editing somebody's copy is not Library's call — so check the list before you resolve.

### Alt text

A "Missing alt text" source, an audit screen (Pro) with per-site counts, and bulk filling from filenames or
titles. `brand-logo_dark-2024_FINAL-v3.png` becomes "Brand logo dark 2024".

That is a first draft, not a description, and Library says so wherever it offers to write one. It is
better than an empty attribute for somebody using a screen reader, and it gives an editor something
to correct rather than a blank field to find. It **never** overwrites alt text somebody wrote unless
you explicitly ask it to.

### Upload rules · Pro

The reason media libraries turn back into piles is that filing is a thing somebody has to remember.
Upload rules do it at the moment the file arrives: match on volume, folder path, filename pattern or
file kind, and file the upload into collections — optionally seeding its alt text too.

Rules add up. "Everything in the Press volume is Press kit" and "anything called `*-icon.svg` is
Icons" can both be true of the same file.

## Requirements

Craft CMS 5.3+ and PHP 8.2+. No runtime dependencies beyond Craft's own, and no build step — the
control panel JavaScript and CSS in `src/web/assets/cp/dist/` are what ship.

## Installation

```sh
composer require justinholtweb/craft-library
php craft plugin/install library
```

## Templating

```twig
{# Every asset filed anywhere under a collection, as a normal asset query #}
{% for image in craft.library.assets('logos').kind('image').limit(12).all() %}
  <img src="{{ image.url }}" alt="{{ image.alt }}">
{% endfor %}

{# The collection tree #}
{% for collection in craft.library.collections() %}
  {{ collection.name }} ({{ collection.children|length }} nested)
{% endfor %}

{# What is this asset filed under? #}
{% for collection in craft.library.collectionsFor(image) %}{{ collection.name }}{% endfor %}

{% if craft.library.isIn(image, 'brand') %}…{% endif %}

{# Pro; null on Lite #}
{{ craft.library.usageCount(image) }}
```

`craft.library.assets()` returns an `AssetQuery`, so everything you would normally do to one still
works. Filtering by a parent collection includes everything nested inside it.

## Console commands

```sh
php craft library/usage/scan                  # find reference tags typed into content
php craft library/usage/unused --limit=50     # list assets nothing points at
php craft library/audit/hash                  # hash files that share a size
php craft library/audit/duplicates --limit=25 # list duplicate groups
php craft library/audit/prune                 # drop membership rows whose asset has gone
php craft library/alt/report                  # how many images have no alt text
php craft library/alt/fill --dry-run=1        # preview what alt text would be written
php craft library/alt/fill --source=filename  # write it
```

`library/audit/hash` and `library/alt/fill` are the ones to run from cron on a large library; the
control panel equivalents are capped per request and say what is left.

## Settings

Everything is optional and nothing is `required`, so a fresh install can save any one setting without
the others being filled in first.

Sources and panels can each be turned off; volumes can be excluded from the audits and the scanner
(cache and derivative volumes belong there); and on Pro you can control whether upload rules run,
whether usage is tracked as content saves, and how large a file the duplicate finder will read.

## Permissions

`View collections and asset usage`, and nested under it: `Add assets to collections`, `Create and
delete collections`, `Bulk edit assets` and `Run library audits`.

Changing an asset's collections also requires permission to edit that asset — otherwise the panel
would be a way around volume permissions.

## Known limits

Collection sources carry a complete asset ID list, which is what makes them honest — the source
shows exactly what the collection holds, always. The cost is that building the sidebar reads the
membership table, so on a library with hundreds of thousands of filed assets the asset index will
get heavy. Turn **Show collections in the asset index** off and use the Collection filter instead;
it is enforced entirely in SQL and does not scale with the number of assets.

## What Library does not do

- **It does not move your files.** Collections are metadata; the folder an asset lives in is Craft's
  business and stays that way. The one exception is the explicit "Move to volume" bulk action.
- **It does not rewrite your content.** Merging a duplicate repoints relations, which are structured
  data. Reference tags typed into a text field are reported, not silently edited.
- **It does not claim to write good alt text.** See above.

## Testing

```sh
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-library/tests/integration/checks.php   # 78 checks
```

The checks run against a real Craft install: real element saves, real files on a real volume, the
condition rules as actual SQL, and the edition boundary. Every run builds its own collections and
its own assets in a temporary folder and removes both afterwards, even when a check fails.
