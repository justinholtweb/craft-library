---
title: Console
slug: console
order: 40
summary: Every console command, its options, which edition it needs, and what to run from cron.
---

## Commands

| Command | Edition | What it does |
|---|---|---|
| `library/alt/report` | All | Counts images, described images and images missing alt text |
| `library/alt/fill` | All | Fills in missing alt text from the filename or title |
| `library/audit/prune` | All | Deletes collection rows whose asset no longer exists |
| `library/usage/scan` | Pro | Reads all content for typed asset reference tags and rebuilds that half of usage |
| `library/usage/unused` | Pro | Lists assets nothing points at |
| `library/audit/hash` | Pro | Hashes files that share a size with another file |
| `library/audit/duplicates` | Pro | Lists groups of identical files |

On Lite, the Pro commands print a message saying so and exit with an error status.

## Alt text

```sh
php craft library/alt/report
php craft library/alt/report --site=fr

php craft library/alt/fill --dry-run=1              # preview, saves nothing
php craft library/alt/fill                          # from the filename
php craft library/alt/fill --source=title
php craft library/alt/fill --site=fr
```

| Option | Default | Notes |
|---|---|---|
| `--source` | `filename` | `filename` or `title` (`fill` only) |
| `--site` | primary site | Site handle. Alt text is per site, so each site is filled on its own |
| `--dry-run` | off | Shows the first 50 images and what each would become, plus the total. Nothing is saved (`fill` only) |
| `--overwrite` | off | Replaces alt text that is already there (`fill` only) |

`fill` works in batches of 100 and shows progress. It never touches alt text somebody wrote unless
you pass `--overwrite`. Think twice before you do: it replaces hand-written descriptions with ones
made from filenames, on every image on the site outside the
[excluded volumes](configuration#excluded-volumes).

What `fill` writes is a first draft. Somebody should read it.

## Usage

```sh
php craft library/usage/scan
php craft library/usage/scan --progress=0          # quiet, for cron logs

php craft library/usage/unused
php craft library/usage/unused --limit=200
```

`scan` reads every content row that mentions an asset and rebuilds the stored reference-tag half
of usage. It skips drafts and revisions. Relations are always read live and aren't affected.

The old rows are only replaced at the end, in one transaction. If a scan dies halfway, the previous
results are still there. A failed scan never leaves every tag-only asset looking unused.

`unused` prints how many assets nothing points at, then a table of the first `--limit` (default 50)
with ID, filename, volume and size. If no typed reference tags are recorded at all, it reminds you
to run `scan` first.

## Duplicates

```sh
php craft library/audit/hash
php craft library/audit/hash --all=0               # one batch only

php craft library/audit/duplicates
php craft library/audit/duplicates --limit=100
```

`hash` keeps going until there is nothing left to hash, showing progress. `--all=0` stops after one
batch of **Hash batch size** files. Afterwards it reports how many duplicate copies it found, and how
many files it couldn't read, if any.

`duplicates` lists up to `--limit` groups (default 25), showing which file is kept and which are copies, with
the space each group wastes. If hashing isn't finished, it says the list is incomplete.

Merging is done in the control panel, on **Library → Audit → Duplicates**. There is no console
command for it, because a merge rewrites relations and should be a decision a person makes about
each group.

## Housekeeping

```sh
php craft library/audit/prune
```

Deletes collection rows that point at an asset row that no longer exists. Foreign keys already
handle normal deletes. This catches rows left behind when asset rows were removed outside
Craft, for example after a volume was dropped mid-migration. It also runs during Craft's
garbage collection, so you rarely need to run it yourself.

## Cron

The control panel equivalents cap how much they do per request, so a large library doesn't time
out. **Fill in the blanks** does 200 images and **Hash now** runs one batch per request. From the
console there's no cap. **Backfill** on an upload rule runs on Craft's queue, so it has no cap
either.

On a large library, run these two from cron:

```sh
# Nightly: hash anything new that has a possible twin
0 2 * * * cd /path/to/project && php craft library/audit/hash --interactive=0

# Weekly: catch reference tags typed into fields saved while tracking was off
0 3 * * 0 cd /path/to/project && php craft library/usage/scan --progress=0 --interactive=0
```

With **Track usage as content is saved** on, the weekly scan is a safety net, not a requirement.
