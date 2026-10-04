---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: Missing sources and menus, assets wrongly reported as unused, rules that did not fire, and what to check first.
---

## There is no Library item in the control panel nav

The nav item only shows links the user can open, and it is hidden if there are none:

- **Collections** needs **Create and delete collections**.
- **Audit** and **Upload rules** need Pro *and* **Run library audits**.
- **Settings** is admins only.

A user with only **View collections and asset usage** gets the sidebar sources and the asset panel,
but no nav item.

## There's no Upload rules item in the nav

Upload rules need Pro and the **Create and delete collections** permission, because a rule files
assets into collections. Audit is a separate permission (**Run library audits**), so a user can
see one item without the other.

## Collections don't appear in the asset index sidebar

Check, in order:

1. **Is there a collection?** The Library heading only appears once there is something under it.
2. **Does the user have View collections and asset usage?** Sources are hidden without it.
3. **Is Show collections in the asset index on?** For selector modals, **Show collections in
   asset selector modals** is a separate setting.
4. **Has someone hidden sources?** Open **Customize sources** in the asset index and check that
   the Library sources are not switched off.

## The asset index is slow on a very large library

Each collection source carries the full list of asset IDs in that collection. On hundreds of
thousands of filed assets, building the sidebar gets heavy. Turn off **Show collections in the asset
index** and **Show collections in asset selector modals**, and filter with the **Collection** rule
instead. It runs in SQL and doesn't slow down as you file more assets. See
[Performance on large libraries](configuration#performance-on-large-libraries).

## There's no upload button when a collection is selected

That is deliberate. A collection isn't a folder, and Craft needs a folder to put the file in. Upload
to a folder, then file it with **Add to collections**, or set up an
[upload rule](usage#upload-rules-pro) to do it.

## A field restricted to a collection accepts everything

Collections are content, not project config. A collection you create locally and one you
create with the same name on production are two different collections with different UIDs.

The **Collection** rule stores collection UIDs. Asset field settings and custom sources live in
project config. So if you build *"Collection is one of Logos"* into a field locally and deploy it,
production's condition points at a collection that doesn't exist there. **A Collection rule whose
collections can't be found filters nothing.** The field then accepts any asset.

To avoid it, do one of these:

- Build field conditions and custom sources that use the Collection rule **on the environment where
  the collections live**, usually production, then pull project config back down.
- Or copy the database down from production before building them locally, so the UIDs match.

To check a deployed field, open its settings on production and look at the Collection rule. If the
picker shows nothing selected, re-select the collection and save.

## An asset shows as unused, but it is used

Read [What unused does not know about](usage#what-unused-does-not-know-about) first. Then:

- **Has a scan run?** Relations are read live, but a reference tag typed into a text field is only
  known after **Library → Audit → Scan content now** or `php craft library/usage/scan`. The
  Unused screen warns you when no typed references are recorded.
- **Is it only used in a draft?** Drafts and revisions are excluded on purpose. Revisions outnumber
  real content several times over and would make everything look used. An asset used only in an
  unpublished draft is reported as unused until the draft is applied.
- **Is the reference a volume path?** `{asset:images/logo.png:url}` isn't matched. Only ID and UID
  reference tags are.
- **Is it used outside entry content?** User profile photos, another plugin's own tables and
  hard-coded template paths aren't relations, so Library can't see them.

## An asset shows as used, but I removed it

- **Is the entry in the trash?** Trashed elements don't count, but if you restored it, they do.
- **Is the entry disabled?** Disabled entries still count. Disabling something isn't deleting it.
- **Was the reference a typed tag, removed while save-time tracking was off?** The stored result is
  from the last scan. Run the scan again, or turn **Track usage as content is saved** back on.

## A content save doesn't update the asset's usage

For relations it always does, because they are read live. For typed reference tags, save-time tracking needs
Pro, **Track usage as content is saved** *and* **Scan content for reference tags** all on. If either
setting is off, typed tags are only picked up by a full scan.

## The duplicate list looks incomplete

- **Hashing isn't finished.** The Duplicates screen and `library/audit/duplicates` both say so
  while files are still waiting. Click **Hash now** on the Audit screen or run
  `php craft library/audit/hash`.
- **The files are bigger than Maximum file size to hash** (64 MB by default). They are left out so
  nobody's 4 GB video gets downloaded to hash it. Raise the limit if you need them included.
- **The files are in an excluded volume.** Excluded volumes aren't hashed.
- **A file couldn't be read.** Library logs a warning, *"Library could not hash asset N"*, and
  remembers the failure so the same broken file doesn't stall every batch. It is picked up again if
  the file is replaced with one of a different size.
- **The files aren't identical.** Library only matches files that are byte-for-byte identical. The same photo
  exported twice, resized or re-saved with different metadata, is a different file.

## Hashing a remote volume is slow

Hashing reads the whole file, so on S3 or another remote filesystem every hash is a download.
Library only hashes files whose size matches another file, which usually leaves a small fraction of
the library, but that fraction can still be large. Lower **Maximum file size to hash**, exclude
volumes that hold derivative files, and run `library/audit/hash` from cron rather than the browser.

## Merging a duplicate broke an image in a rich text field

Merging repoints relations, so asset fields and the asset references CKEditor and Redactor record
come through intact. A reference tag somebody **typed** into text, like `{asset:412:url}`, is
not rewritten. It still names the copy, and stops resolving once the copy is in the trash.

To fix the tag, change it to the original's ID. If you need the copy itself back, restore it from
Craft's trash. Before your next merge, check the copy's **Used in** list for entries marked
*reference tag*.

## An upload rule didn't file a new upload

Check, in order:

1. **Pro, and Apply upload rules on?** Rules don't run on Lite or while the setting is off.
2. **Is the rule enabled?**
3. **Has the file reached a volume yet?** A file uploaded through an asset field sits in Craft's
   temporary uploads folder until the entry is saved. Rules run at that point, not at upload.
4. **Does the folder path match?** It is the path *inside* the volume, without leading or trailing
   slashes, and the whole path must match: `brand` matches only the `brand` folder itself, and
   `brand/*` matches anything beneath it.
5. **Does the filename pattern match the whole name?** `icon` doesn't match `logo-icon.svg`;
   `*icon*` does. Matching ignores case.
6. **Was the asset already in the library?** Rules run once, when a file arrives. Re-saving an existing asset doesn't
   run them. Use **Backfill** for existing assets.

## Backfill didn't file everything

Backfill runs on Craft's queue, so check that the queue is running (**Utilities → Queue Manager**).
It only touches assets the person who clicked it could save, and only ever fills *empty* alt text.

## New uploads aren't getting alt text from the default source

**Default alt text source** is applied as part of upload rule processing. It needs Pro and **Apply
upload rules** on, even if you have no rules. On Lite it is saved but has no effect.

It also never overwrites: if the upload already has alt text, it is left alone.

## The Missing alt text source and the alt text audit show different numbers

There are two possible reasons:

- **Excluded volumes.** The audit leaves them out. The sidebar source is a filter and doesn't.
- **Sites.** Alt text is per site. Both count the site you are viewing, so compare them on the same
  site.

## Alt text was filled on one site only

That is intentional. Alt text is per site in Craft, and Library never copies one site's string over
another site's translation. Switch sites and fill again, or run
`php craft library/alt/fill --site=<handle>` for each site.

## A bulk action skipped some of the selected assets

Bulk actions only touch assets the current user can **save**. Assets in a volume they can only
view, or a colleague's uploads without the *edit peer assets* permission, are left out. **Set alt
text** also skips anything that isn't an image.

## Rename files says it failed, but renamed nothing

With **Preview only** ticked, which is the default, the result is a preview, shown as a notice you
have to dismiss so it can't be mistaken for "done". Untick **Preview only** to apply. If the preview
names a problem, two files would end up with the same name. Add `{index}` to the pattern.

## Ticking a collection on an asset says "You are not allowed"

Changing an asset's collections needs **Add assets to collections** *and* permission to save that
asset. Without the second, the panel would be a way around volume permissions.

## Still stuck

Library logs to Craft's standard logs under the `library` category, in `storage/logs/`. Search for
`library` near the time of the problem. Then
[open an issue](https://github.com/justinholtweb/craft-library/issues) with what you find, your
Craft and Library versions, and the steps that reproduce it.
