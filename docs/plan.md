# Library — design notes

Reference point: WordPress's *Enhanced Media Library*. Same ground, done the Craft way.

## The load-bearing decision: how a collection filters

A collection has to filter assets **everywhere assets are chosen** — the index, the selector modal
inside an entry's asset field, custom sources — and it has to do it in SQL, because a media library
is exactly the place where "show me everything in Brand" means five thousand rows.

Craft offers four mechanisms and three of them are traps. They were all tried:

### `defineSources()` with a custom query param — rejected

The natural approach: register a source whose `criteria` is `['libraryCollection' => 3]`, and teach
`AssetQuery` the param. Craft has no way to teach it one.

`ElementQuery::behaviors()` is a plain method with no `EVENT_DEFINE_BEHAVIORS` alongside it, and
`Asset::find()` does `new AssetQuery(...)` directly rather than going through the container, so the
class cannot be swapped either. Setting an unknown property on the query throws
`UnknownPropertyException` from `yii\base\Component::__set()`.

There *is* a way — `CustomFieldBehavior::$fieldHandles` is public and static, and adding a key to it
makes an arbitrary property settable on every element query in the system. It also makes a fake
field handle exist on every element type, and collides silently with a real field of that name. That
is not a thing to do on other people's production sites.

### `relatedTo` with a Collection element — rejected

Elegant if it worked: make collections elements, store membership in Craft's `relations` table, and
the source criteria becomes `['relatedTo' => ['sourceElement' => $id]]` — a native param, no custom
anything.

`relations.fieldId` is `notNull` with a foreign key to `fields`. Plugin-owned relation rows are not
possible without inventing a fake field. Dead end, confirmed against the schema rather than assumed.

### `defaultFilter` on a source — rejected

A source may carry a `defaultFilter` condition, which sounds exactly right. It is seeded into the
filter toolbar **only when no filter HUD has been created yet**:

```js
!this.filterHudExists() && this.$source.data('default-filter')
  ? this.createFilterHud({showOnInit: false, conditionConfig: …})
  : this.updateFilterBtn()
```

So a reader who has touched the funnel button once, for any source, gets no filter at all from then
on — a source labelled "Logos" quietly listing the whole library. A source whose label promises a
subset and whose criteria only sometimes delivers one is worse than no source.

### What Library actually does

**Condition rules are the enforcement mechanism.** `BaseCondition::EVENT_REGISTER_CONDITION_RULES`
on `AssetCondition` puts a rule into the index toolbar, the selector modal, asset field settings and
user-built custom sources at once, and `modifyQuery()` can do arbitrary SQL. Conditions added
through `$query->andWhere()` land on the element query's **subquery**, where `elements` and
`elements_sites` are already joined — so a correlated `EXISTS` against Library's own tables is both
safe and index-friendly.

**Sidebar sources carry only criteria that are complete.** Collections use an explicit asset ID list,
which is the one native param that can express "these particular files". "Missing alt text" uses
`['kind' => 'image', 'hasAlt' => false]` — entirely native, unbounded, exact.

**"Unused" and "Duplicates" are deliberately not sources.** Neither can be expressed in a native
param and both can run to thousands of rows, so an ID list would be either enormous or truncated.
They are reachable as condition rules (unbounded, enforced in SQL) and on the Audit screen
(paginated, with actions). No source in Library lies about what it contains.

Registered sources are left as `TYPE_NATIVE`. A `TYPE_CUSTOM` source registered from a plugin is
silently dropped the moment anyone customises the asset source order, because
`ElementSources::defineSources()` only re-adds missing sources whose type is native.

## Data model

Membership is a plain join table with a unique index on `(collectionId, assetId)`, which is what
makes bulk assignment one insert and the unique index — not a read-then-write — the authority on
what already exists.

`library_usage` caches only the reference-tag half of usage. The relation half is read live, because
Craft already indexes it and a cache of an index is a second thing to get wrong.

`library_hashes` stores the size alongside the hash, so a replaced file is detected without being
read again, and the duplicate finder can shortlist by size before hashing anything.

## Control panel integration traps

Both found by the trigger silently doing nothing:

- **Craft strips `name` off every input in an element action trigger.** It snapshots their initial
  values into the action's settings first, then removes the attributes so nothing serialises. A
  multi-select trigger only works if the values are read from the DOM at click time and handed to
  `submitAction()`. It also turns any submit button into a `.formsubmit` that fires the action again
  with the stale snapshot — hence `type="button"` throughout.
- **A `.menubtn` menu is rebuilt as a listbox and custom markup is thrown away.** A form inside one
  disappears. `data-disclosure-trigger` with `class="menu menu--disclosure"` is the variant that
  keeps arbitrary content, and Garnish reparents it onto `<body>`, so the fields have to be found by
  a document-wide data attribute rather than by searching inside the trigger.

And one found by an "unsaved changes" warning appearing after Library had already saved: **a named
input in the asset edit sidebar joins Craft's asset form**, so ticking a collection marked the asset
dirty. The panel's checkboxes carry no `name` at all; every reader goes through `data-collection-id`.

## Editions

Lite is the taxonomy: collections, sources, filters, bulk assignment, alt text. Pro is everything
that needs a scan or touches the filesystem: usage, duplicates, renaming, cross-volume moves, upload
rules. The edition check lives in `Plugin::isPro()` and is enforced in controllers as well as in the
nav, so a bookmarked URL stops working when a trial ends.
