---
title: Configuration
slug: configuration
order: 20
summary: Plugin settings, the config file, excluded volumes and user permissions.
---

## Settings

Settings are under **Library → Settings**, which only admins can see. Every setting has a config key, so you can also
set it in [`config/library.php`](#the-config-file). None of them is required, so you can save
any one setting on a fresh install without filling in the others.

### All editions

| Setting | Config key | Default | What it does |
|---|---|---|---|
| Show collections in the asset index | `showCollectionSources` | on | Adds a **Library** heading to the asset index sidebar, with one source per collection, nested to match the tree |
| Show collections in asset selector modals | `showCollectionSourcesInModals` | on | Shows the same sources in the modal that opens when an editor picks an asset for a field |
| Show asset counts | `showCollectionCounts` | on | Puts a count badge on each collection source. A parent's count includes its nested collections |
| Show audit sources | `showAuditSources` | on | Adds the **Missing alt text** source |
| Show the Library panel on assets | `showAssetSidebarPanel` | on | Adds the Library panel to the asset edit screen's sidebar |
| Default alt text source | `defaultAltSource` | `none` | What newly uploaded images get for alt text when no upload rule sets a source: `none`, `filename` or `title`. Takes effect on Pro only — see below |
| Excluded volumes | `excludedVolumes` | none | Volumes the audits ignore. Takes volume **UIDs** |

### Pro

These appear on the settings screen once Library is on Pro.

| Setting | Config key | Default | What it does |
|---|---|---|---|
| Apply upload rules | `applyUploadRules` | on | Runs [upload rules](usage#upload-rules-pro) on new uploads. Turn it off to pause the rules without deleting them |
| Track usage as content is saved | `trackUsageOnSave` | on | Re-reads an element's content for typed reference tags every time it saves, so that half of usage stays current between scans |
| Scan content for reference tags | `scanContentForRefs` | on | Must also be on for save-time tracking to run. It does not affect relations, which are always read live, or a scan you start by hand |
| Maximum file size to hash (MB) | `maxHashFileSizeMb` | `64` | Files larger than this are left out of duplicate detection. 1–10240 |
| Hash batch size | `hashBatchSize` | `100` | How many files one hashing batch reads before handing control back. 1–1000 |

## Default alt text source

This setting is a fallback for [upload rules](usage#upload-rules-pro). Library applies it when an
upload is processed, and that only happens on Pro with **Apply upload rules** on. It runs
even when no rule matches the file. On Lite the setting is saved, but nothing applies it.

It only fills an empty alt attribute. It never replaces alt text that is already there.

## Excluded volumes

Some volumes hold files nobody chose: image transform caches, derivative renditions, export dumps.
Left in, they fill the unused list with files that are meant to be unreferenced, and the duplicate
finder downloads every one of them to hash it.

An excluded volume is left out of:

- the **Not used anywhere** count and list, on the Audit screen and in `library/usage/unused`
- duplicate hashing. Files already hashed before you excluded the volume can still appear in
  duplicate groups
- the alt text audit's counts, its list and **Fill in the blanks**
- `library/alt/report` and `library/alt/fill`. `--overwrite` is the exception. It walks every
  image on the site regardless of exclusions

Exclusion does **not** affect the filter rules or the **Missing alt text** sidebar source. Those are
filters, and a filter shows what you asked for. That is why the sidebar source can list more images
than the alt text audit counts.

## The config file

You can fix any setting in `config/library.php` using the config keys above. Values in the file
override whatever is saved in the settings screen. Craft's usual multi-environment keys work too.

```php
<?php

return [
    // Very large library: drop the per-collection sources and filter instead.
    'showCollectionSources' => false,
    'showCollectionSourcesInModals' => false,

    // Volume UIDs, not handles.
    'excludedVolumes' => [
        'b7f2c1d4-0e3a-4c55-9a7e-2f6d8c1b3a90',
    ],

    // Remote volume: don't download anything bigger than 20 MB to hash it.
    'maxHashFileSizeMb' => 20,
];
```

A volume's UID is in its project config file under `config/project/volumes/`.

## Performance on large libraries

Each collection source in the sidebar carries the complete list of asset IDs in that collection.
That is what makes the source exact: it shows what the collection holds, and nothing approximates
it. The cost is that building the sidebar reads the whole membership table on every asset index
and every selector modal.

On a library with hundreds of thousands of filed assets, that gets heavy. Turn off **Show
collections in the asset index** and **Show collections in asset selector modals**, and use the
**Collection** filter rule instead. The filter runs entirely in SQL and does not slow down as you file more
assets.

**Show asset counts** adds one grouped count query per index load. It is cheap, but it is the first
thing to turn off if the sidebar feels slow.

## Permissions

Library adds a **Library** group to user group and user permissions:

| Permission | Lets a user |
|---|---|
| **View collections and asset usage** | See the Library sidebar sources and the Library panel on assets |
| ↳ **Add assets to collections** | Tick collections on the asset panel; use **Add to collections** and **Remove from collections** |
| ↳ **Create and delete collections** | Open **Library → Collections** to create, edit and delete collections. Also required for **Upload rules** (Pro) |
| ↳ **Bulk edit assets** | Use **Set alt text**, and on Pro **Rename files** and **Move to volume**. Also required for **Fill in the blanks** on the alt text audit |
| ↳ **Run library audits** | Open the **Audit** screens (Pro): scan, hash, see unused files and duplicates |

The four nested permissions sit under **View collections and asset usage** in Craft's permission
tree.

Library's permissions never get around Craft's own. Every write also checks the asset:

- **Changing an asset's collections** from its panel requires permission to save that asset.
  Otherwise the panel would be a way around volume permissions.
- **Bulk actions** only touch the assets in the selection that the user may save. If someone can
  view a volume but not save to it, or can't edit a peer's uploads, those assets are left out.
- **Move to volume** only offers volumes the user can save assets in.
- **Merge into original** requires permission to delete the copy and to save the original.

The **Collection** and **Orientation** filter rules, and on Pro the **Is used anywhere** and **Has
duplicate copies** rules, appear for everyone who can see the asset index. They are part of Craft's
asset condition, not a separate screen.

The **Library → Settings** link is shown to admins only.

## GraphQL schemas

GraphQL access is set per schema, not per user. Library adds one schema permission, **Library →
Query for collections**. It turns on the `libraryCollections` query and the `libraryCollection`
argument on asset queries. Asset results still need the schema's own volume permissions: Library
never returns a file from a volume the schema can't read, even when the collection holds it. See
[Usage](usage#graphql).
