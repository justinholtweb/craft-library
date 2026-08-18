# Library — Craft CMS 5 Plugin

## Project Overview

Library is a media-library plugin for Craft CMS 5: a cross-volume taxonomy for assets (collections),
filters that work everywhere assets are chosen, bulk editing, usage tracking, duplicate detection and
an alt text audit. Distributed as `justinholtweb/craft-library`. **Lite/Pro, $79 / $59 renewal.**

Reference point: the WordPress *Enhanced Media Library* plugin. Same ground, done the Craft way —
condition rules and native sources rather than a bolted-on UI.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step: `src/web/assets/cp/dist/library-cp.{js,css}` are hand-written and are what ship
- No runtime dependencies beyond Craft's own

## Architecture

- Namespace `justinholtweb\library`, package `justinholtweb/craft-library`, handle `library`
- Services: `collections`, `membership`, `usage`, `scanner`, `duplicates`, `alt`, `renamer`, `rules`

### The load-bearing idea: condition rules, not query params

Collections filter through `BaseCondition::EVENT_REGISTER_CONDITION_RULES` on `AssetCondition`. One
hook reaches the index toolbar, asset field selector modals, asset field settings and user-built
custom sources, because Craft asks the same condition class in all four.

**`docs/plan.md` records why the three obvious alternatives were rejected** (custom query param,
`relatedTo` with a Collection element, `defaultFilter`), each against the actual Craft source. Read
it before changing how filtering works — all three look right and all three fail.

Sidebar sources carry only criteria that are *complete*: collections use an explicit asset ID list,
missing-alt uses native `kind` + `hasAlt`. Unused and Duplicates are deliberately not sources.

## Traps found while building this

- **`relations.fieldId` is `notNull` with a foreign key to `fields`.** Plugin-owned relation rows are
  impossible. Any design that wants to reuse Craft's relation index for its own links is dead on
  arrival; check the schema before building on it.
- **`Db::upsert()` only inserts the columns given as `$insertColumns`.** `$updateColumns` applies to
  the ON DUPLICATE KEY branch alone. Passing just the key inserts a row with null everywhere else
  and updates nothing — silently, because the row now exists so nothing retries it. Every hash read
  back as null. Pass the full row to both halves. (Same family as the note in `[[craft-abacus-gotchas]]`.)
- **Yii skips inline validators when the attribute is empty.** `UploadRule::validateHasEffect()`
  hangs off `collectionIds` and exists to catch an *empty* `collectionIds`, so it needs
  `'skipOnEmpty' => false` or it never runs. Same trap as `[[project_craft_fold]]`.
- **Craft strips `name` off every input in an element action trigger** after snapshotting the initial
  values into the action's settings. Multi-select triggers must read the DOM at click time and pass
  params to `submitAction()`. Submit buttons also become `.formsubmit` and re-fire the action, so
  every button in a trigger is `type="button"`.
- **A `.menubtn` menu is rebuilt as a listbox and arbitrary markup is discarded.** Use
  `data-disclosure-trigger` + `class="menu menu--disclosure"`, and find the fields by a document-wide
  data attribute — Garnish reparents the menu onto `<body>`.
- **A named input in the asset edit sidebar joins Craft's asset form** and marks it dirty, producing
  an "unsaved changes" warning for something already saved by AJAX. The picker emits no `name`.
- **`defaultFilter` on a source only applies if no filter HUD exists yet.** Not an enforcement
  mechanism. See `docs/plan.md`.
- **Element field values are memoised on the memoised element.** Re-fetching an entry with
  `Entry::find()->id()->one()` hands back the same instance and the same already-resolved field
  value, so a test that changes relations underneath it reads stale — or empty, once the old target
  is trashed. Assert with a fresh `relatedTo` query instead.
- **A console request has no user**, so `getUser()->checkPermission()` in a `registerSources`
  handler must be skipped when `getRequest()->getIsConsoleRequest()`.
- **`Asset::getFileKinds()` does not exist** — the registry is on `craft\helpers\Assets`.
- **`Asset::sources()` memoises statically per request**, so a source list fetched after a collection
  is created will not contain it. Trigger `EVENT_REGISTER_SOURCES` directly to test source shape.
- **Drafts and revisions must be excluded from usage**, in the count, the list, the unused query and
  the condition rule *alike*. They started as four pieces of SQL and immediately disagreed. One
  definition now: `Usage::relationExistsQuery()` / `refExistsQuery()`.

See `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-library/tests/integration/checks.php   # 78 checks
```

The checks switch the plugin to Pro for the run and restore the original edition afterwards. They
build their own collections and their own image files in a temporary folder on a real volume, and
clean both up in a `finally` block so a failure still leaves the site as it found it.

Switching editions from a bare script needs `Craft::$app->getProjectConfig()->flush()` afterwards, or
the change is buffered and lost — see `[[craft-plugin-gotchas]]`.
