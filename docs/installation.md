---
title: Installation
slug: installation
order: 10
summary: Requirements, install, what Lite and Pro each include, and your first collection.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

Library needs nothing beyond Craft itself: no other Composer packages and no build step.

## Install

```sh
composer require justinholtweb/craft-library
php craft plugin/install library
```

Or find **Library** in the Craft Plugin Store and install it from there.

Installing creates Library's own database tables and nothing else. It does not touch your volumes,
your folders or your files, and it writes nothing to project config.

## What you get straight away

Out of the box, before you configure anything:

- A **Library** heading in the asset index sidebar, which fills in as you add collections.
- A **Missing alt text** source under that heading, listing every image with no alternative text.
- **Collection** and **Orientation** rules in the asset filter toolbar.
- A **Library** panel in the sidebar of every asset's edit screen.
- A **Collections** column you can add to any asset index table view.

## Your first collection

1. Go to **Library → Collections** and click **New collection**.
2. Give it a **Name**. The **Handle** fills itself in from the name. You use the handle in templates.
3. Optionally nest it under another collection, give it a **Colour**, and write a short
   **Description** of what belongs in it.
4. Save. The collection now appears under **Library** in the asset index sidebar.

To file assets, select some in the asset index and choose **Add to collections** from the actions
menu. To file a single asset, tick its collections in the Library panel on its edit screen. See
[Usage](usage) for the rest.

## Editions

Lite is free and covers the taxonomy: collections, the filters, the sidebar sources and the
everyday bulk actions. Pro costs $79, with a $59/year renewal. It adds everything that needs to read the
whole site or touch files on disk: usage tracking, the audit screens, duplicate detection,
renaming, cross-volume moves and upload rules.

| | Lite | Pro |
|---|---|---|
| **Price** | **Free** | **$79**, $59/year renewal |
| Collections: nesting, colours, descriptions | Yes | Yes |
| Collections in the asset index sidebar and in asset selector modals | Yes | Yes |
| **Missing alt text** sidebar source | Yes | Yes |
| **Collection** and **Orientation** filter rules | Yes | Yes |
| Library panel on the asset edit screen: collections | Yes | Yes |
| **Collections** index column | Yes | Yes |
| Bulk actions: add to and remove from collections | Yes | Yes |
| Bulk action: set alt text from the filename or title | Yes | Yes |
| `craft.library` Twig API (except `usageCount`) | Yes | Yes |
| GraphQL: `libraryCollections` and the `libraryCollection` asset argument | Yes | Yes |
| Console: `alt/report`, `alt/fill`, `audit/prune` | Yes | Yes |
| **Is used anywhere** and **Has duplicate copies** filter rules | — | Yes |
| **Used in** index column and "Used in" list on the asset panel | — | Yes |
| **Audit** screens: unused files, duplicates, alt text | — | Yes |
| Duplicate detection and merging | — | Yes |
| Bulk action: rename files with a pattern | — | Yes |
| Bulk action: move to another volume | — | Yes |
| **Upload rules**, including the default alt text source for new uploads | — | Yes |
| Usage tracking as content is saved, and reference-tag scanning | — | Yes |
| Console: `usage/scan`, `usage/unused`, `audit/hash`, `audit/duplicates` | — | Yes |
| `craft.library.usageCount()` | — | Yes (`null` on Lite) |

Edition checks happen on the server as well as in the navigation. If you bookmark an Audit page
while trialling Pro, it stops working when you switch back to Lite. It does not keep working just
because the URL is still in your history.

## Switching editions

Change edition from **Settings → Plugins** or the Plugin Store, as with any Craft plugin. Nothing is
lost going either way. Collections and their contents are the same in both editions. Usage
rows, file hashes and upload rules stay in the database while you are on Lite and are used again
when you return to Pro.

## Uninstalling

`php craft plugin/uninstall library` drops Library's tables. Collections, upload rules, recorded
usage and file hashes go with them. Your assets, folders and files are untouched. Library never
moves or deletes a file except when you run **Move to volume** or **Merge into original**
yourself.
