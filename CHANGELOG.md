# Changelog

All notable changes to the Cereus Data Sync plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.1] - 2026-08-10

### Fixed
- **Graphs kept their old title after the device was tagged for deletion.** A
  device removed from the source inventory was correctly tagged on
  `host.description`, but its graphs continued to show the untagged name, so the
  graphs could not be found by searching for the deletion tag. Cacti substitutes
  `|host_description|` once and stores the result in
  `graph_templates_graph.title_cache` (and the data source name in
  `data_template_data.name_cache`); nothing recomputes those columns when the
  description changes except `api_device_save()`, which the sync calls only when
  adding a device. Both caches are now refreshed whenever the sync tags a device
  for deletion or updates its description or location — the same drift affected
  devices renamed or moved in the source inventory, which kept their old graph
  titles indefinitely.
- One-off backfill on upgrade refreshes the graph and data source title caches of
  every device that already drifted, so previously tagged or renamed devices
  catch up without waiting for their next change. Devices are selected by
  evidence (cached title no longer contains the device's current description),
  not by tag, and the backfill runs once.

  Note: `update_graph_title_cache()` declines to overwrite a non-empty cache when
  the substituted title still contains an unresolved `|host_` or `|query_`
  variable, so a graph whose data query index has disappeared stays stale.

## [1.5.0] - 2026-08-10

### Added
- **Operator-defined branch path on tree rule templates.** The tree branch a
  generated automation rule points at was fixed at Region / Country / Site. Each
  template now carries its own *branch path* — a `/`-separated list of levels,
  each either literal text or one of the `{region}` `{country}` `{site}`
  placeholders — so the hierarchy is chosen per template:
  `{region}/{country}/{site}`, `{region}/{country}`, `{site}`, or a fixed
  collector branch such as `Internet` or `Internet/{region}`. A level whose
  placeholder is empty for a device is skipped, so a device with no country
  still lands one level up instead of under a blank branch.
  Existing templates are migrated to `{region}/{country}/{site}`, which
  reproduces their previous branches and rule names exactly — no tree churn on
  upgrade.
- Locations that resolve to the same branch with the same conditions now share
  one generated rule rather than rebuilding it once per location, which is what
  makes a shallow or literal path collect graphs from many sites into a single
  branch. Where a fixed path is combined with a location-specific condition
  (such as `{site_id}`), the rules are kept apart by name instead of
  overwriting one another.

## [1.4.0] - 2026-07-24

### Added
- **Delete empty containers on device deletion.** The core Console → Devices
  delete-confirmation screen now offers an extra opt-in checkbox: *"Also delete
  any associated Site(s) and Tree branch(es) that become empty"*. When ticked,
  after the device(s) are removed the plugin deletes any Site left with no live
  devices and prunes any tree branch (header) left with no children — walking
  upward so a chain of now-empty parent headers is removed in one pass.
  Containers that still hold another live device are never touched. The option
  is delivered entirely through Cacti's hook API (`device_remove` snapshots the
  containers before deletion; `device_action_bottom` prunes the empty ones
  afterwards) with no core modification, and is gated to Professional tier and
  above. This complements the existing sync-time *empty container flagging*: the
  sync flags containers for manual review, while this removes them immediately
  on an operator-driven device delete.

## [1.3.0] - 2026-07-22

### Fixed
- **Tree not updated when a device's office changes.** Tree placement previously
  ran only when a device was first added. When an existing device moved to a
  different location/site the sync updated its location and site but left it in
  its old tree branch (and could leave it in both). The update path now
  reconciles tree placement: the device is removed from any of the profile's
  managed rule-parents that no longer match and added under every rule that
  matches its new location. Only parents referenced by the profile's own tree
  rules are touched — manual placements are left alone.
- **Device wrongly marked for deletion when its name changed but its IP did not.**
  The "still present in the inventory?" check compared against a device field
  (`ip`) that does not exist on the loaded rows, so it could only match by
  friendly name. A device whose SolarWinds hostname changed while its IP stayed
  the same failed the check and was tagged for deletion (then revived by the
  update pass — inflating counts and logging a false deletion). The check now
  matches on the device's actual network address, so the rename is applied
  cleanly as an update with no spurious deletion.

### Added
- **Empty container flagging.** After a sync tags devices for deletion (devices
  no longer present in the source inventory), the run now flags the containers
  those devices left behind so an operator can find and remove them manually in
  the same pass:
  - **Empty sites** — any site with no live devices (either no host rows at all,
    or every host already tagged for deletion) has the deletion tag prefixed onto
    its name and a dated note appended.
  - **Empty tree branches** — the top-most tree header of any branch left with no
    live devices is prefixed with the deletion tag. Only the outermost empty
    header of a subtree is flagged, so removing it takes the whole branch. Marking
    is scoped to the trees the profile actually places devices into.
  - **Automatic tag removal.** Because deletion is manual, a flagged site or
    branch can still be present when a later sync adds devices to that location
    again. The sync now reuses the still-existing flagged container (rather than
    creating a duplicate) and strips the deletion tag off any site/branch that is
    populated with live devices again — the flag is reconciled against actual
    contents on every run.
  - Two profile toggles, **Flag Empty Sites** and **Flag Empty Tree Branches**
    (both on by default), control the behaviour. Dry runs count candidates
    without modifying anything. New run counters and result cards (*Empty Sites*,
    *Empty Branches*, *Sites Revived*, *Branches Revived*) surface the outcome in
    the run log.
- **Device change tracking in notes.** When a sync updates a device's description
  or its location/site, it now appends a timestamped, human-readable entry to the
  device's Notes field (e.g. `[Data Sync 2026-07-22 09:14] Location: 'Berlin' →
  'Munich'`), so every change made by the sync is visible on the device itself.
  The field is bounded to keep repeated syncs from growing it without limit.

## [1.2.0] - 2026-07-07

### Added
- **Boolean grouping for aggregate graph rules.** Each aggregate rule's device
  match is now an unbounded list of conditions instead of a fixed primary +
  secondary pair. Every condition joins to the previous one with an explicit
  **AND** or **OR** connector and can carry opening/closing parentheses, so
  grouped logic is possible, e.g.
  `Location contains "DC1" AND ( Description contains "core" OR Description begins with "dist" )`.
- Aggregate conditions gain a full operator set — *contains, does not contain,
  begins with, ends with, equals* — where previously only substring (contains)
  matching was available.

### Changed
- The device-match SQL for aggregate rules is assembled from the condition list
  with whitelisted field names and fixed operators; only the bound pattern is
  user-supplied. Unbalanced parentheses cause the rule to be skipped (logged)
  rather than emitting malformed SQL.
- Profile copy now carries aggregate conditions as well as `placement_mode` and
  `site_name`.

### Migration
- Existing aggregate rules' primary/secondary conditions are migrated into the
  new condition list automatically on upgrade (operator = *contains*).

## [1.1.0] - 2026-07-07

### Added
- **Boolean grouping for tree placement rules.** Each condition in a tree rule
  template now joins to the previous one with an explicit **AND** or **OR**
  connector, and every condition carries optional opening/closing parentheses.
  This makes grouped placement logic possible, e.g.
  `h.site_id equals {site_id} AND ( h.location begins with "Stuttgart" OR h.location begins with "München" OR h.location begins with "Lübeck" )`.
  Conditions are materialised into a full Cacti `automation_match_rule_items`
  token stream (`[connector] [ '(' … ] <field op pattern> [ … ')' ]`), so the
  generated automation rule reproduces the grouping exactly.

### Changed
- Generated tree automation rules are now rebuilt idempotently on each sync run.
  Previously a rule was skipped once its name existed, which froze its match
  conditions at first creation; edited conditions (including new OR/parenthesis
  grouping) now take effect on the next run.
- Profile copy now carries the new connector and parenthesis fields.

## [1.0.1] - 2026-06-16

### Fixed
- Aggregate and OID rule inserts returning `{"id":0}` in the web request
  context, caused by a pre-migration column list. Migrations are now forced on
  upgrade.

## [1.0.0]

### Added
- Initial release: SolarWinds/Excel inventory synchronisation into Cacti
  devices, profile management with scheduling, tree placement rules, aggregate
  graph rules, OID graph rules, and a sync audit log.
