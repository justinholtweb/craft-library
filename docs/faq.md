---
title: FAQ
slug: faq
order: 60
summary: Common questions about collections, filtering, usage tracking and duplicates for Craft CMS assets.
---

## How much does it cost?

Lite is free. Pro is $79, with a $59/year renewal for updates. See the
[editions table](installation#editions) for exactly what each one includes.

## Does Library move my files?

No. Collections are metadata. Filing an asset into five collections leaves its file in the folder it
was already in. Library only moves a file when you run **Move to volume** (Pro), and only renames one
when you run **Rename files** (Pro) with preview turned off.

## How is a collection different from a folder?

A file lives in exactly one folder, in exactly one volume. That is the right model for where a file
is stored, and the wrong one for what the file is. A collection describes what it is: a logo can be in
**Brand** and **Press kit** at the same time, wherever the file happens to be stored, and a
collection can gather files from several volumes.

## Is this like WordPress's Enhanced Media Library?

It covers the same ground: a taxonomy for media, filters wherever media is chosen, and bulk
editing. The difference is how it is built. Library's filters are rules on Craft's own asset condition, and its
sidebar entries are native sources. So it works in the asset index, in field selector
modals, in asset field settings and in custom sources, without a separate media screen.

## Will the filters work in my asset fields?

Yes. Craft uses the same asset condition in the asset index, in asset field selector modals, in an
asset field's **Selectable assets** settings and in custom sources. Library adds its rules to that
condition, so they appear in all four. You can restrict a field to "landscape images from Brand"
without writing any code.

## Are collections in project config?

No, on purpose. Editors create collections all the time, and project config is for schema that
moves through deployments. Collections live in the database, like entries do. One consequence: a
field condition that names a collection should be built where that collection exists. See
[Troubleshooting](troubleshooting#a-field-restricted-to-a-collection-accepts-everything).

## Why aren't Unused and Duplicates in the sidebar?

A sidebar source has to show exactly what its label says. Craft enforces a source's criteria, and
neither "unused" nor "has duplicates" can be expressed in Craft's own asset query parameters
without building an ID list that can run to tens of thousands of entries.

So they are filter rules, **Is used anywhere** and **Has duplicate copies**, which run as SQL and
stay exact on any size of library. The Audit screen has them too. If you want one click, build a custom source from
the rule.

## Why doesn't an asset used in a draft count as used?

Drafts and revisions are excluded from usage everywhere: the count, the list, the filter rule and
the unused screen. On a site with revisions turned on, revision rows outnumber real content several
times over. If they counted, almost every asset would look used, including one that was removed
from the live entry months ago.

The cost is that an asset used *only* in an unpublished draft reads as unused until the draft is
applied. Check before you delete.

## Can I trust the Unused list enough to delete everything on it?

No. "Unused" means nothing in the CMS points at the file. A file linked from a template, used as a
user's photo, stored by another plugin, or sent out in an email is invisible to Library. Use the
list to find candidates, then check them, and remember that Craft's trash lets you undo a delete.

## Does duplicate detection download my whole S3 bucket?

No. Library compares file sizes first, using what Craft already has in the database, and only reads files whose size
exactly matches another file's. That's normally a small part of the library. Files over **Maximum
file size to hash** (64 MB by default) are never read, and you can exclude whole volumes.

## Which copy is kept when I merge duplicates?

The oldest. Library treats the first upload as the original, because that's normally the one content already
points at. Merging repoints relations onto it, adds it to the copy's collections, and moves the copy
to the trash. You can restore the copy.

## Does Library write alt text for me?

It can write a first draft, from the filename or the title. `brand-logo_dark-2024_FINAL-v3.png`
becomes "Brand logo dark 2024". That is better than an empty attribute for somebody using a screen
reader, and it gives an editor something to correct. It isn't a description, and Library says so
everywhere it offers to write one.

It never overwrites alt text somebody wrote unless you tick **Replace alt text that is already
there**, or pass `--overwrite` on the command line.

## Does it work on multi-site installs?

Yes. Collections and usage apply to the asset across all sites. Alt text is per site in Craft, so
the alt text audit, the bulk action and the console commands each work on one site at a time, and
never copy one site's text over another's.

## Does it work with CKEditor and Redactor?

Yes. When an editor picks an asset in CKEditor or Redactor, Craft records a relation, and Library
reads relations live. A reference tag typed by hand, such as `{asset:412:url}`, is found by the
content scan.

## What happens to Pro data if I go back to Lite?

It stays in the database. Upload rules stop running, the audit screens and Pro filters disappear,
and usage counts are hidden. Switch back to Pro and it's all there again.

## Which Craft versions are supported?

Craft CMS 5.3 or later, on PHP 8.2 or later.
