# Cereus Data Sync

Synchronise external inventory (SolarWinds exports / Excel workbooks) into Cacti
devices, and automate their placement into the Cacti tree.

A commercial [Cereus](https://urban-software.de) plugin for
[Cacti](https://www.cacti.net) 1.2.x.

## Features

- **Inventory sync** — import devices from an Excel/CSV inventory file, mapping
  columns to Cacti device fields, with add/update/delete reconciliation and a
  configurable deletion tag. Description/location/site changes are recorded as
  timestamped entries in each device's Notes field.
- **Empty container cleanup** — after devices are tagged for deletion, any site
  with no live devices (including sites with no hosts at all) and any tree branch
  left with no live devices are automatically flagged with the deletion tag so
  they are easy to find and remove manually (toggleable per profile). Because
  deletion is manual, a later sync that adds devices to a still-existing flagged
  container reuses it and automatically removes the tag again.
- **Delete empty containers on device deletion** — Cacti's own Console →
  Devices delete-confirmation screen gains an opt-in checkbox to also remove any
  Site or tree branch that the deleted device(s) leave empty. Empty parent
  headers are pruned upward in a single pass; containers still holding another
  live device are never touched. Delivered via Cacti's hook API — no core files
  are modified.
- **Profiles** — multiple named sync profiles, each with its own file source,
  column map, SNMP defaults, and schedule (manual, every poller, hourly, daily,
  weekly).
- **Tree placement rules** — template-driven Cacti automation rules that place
  matching devices/graphs into the tree per unique location. Conditions support
  full boolean grouping (**AND**/**OR** connectors with parentheses) and
  location placeholders (`{site_id}`, `{site}`, `{region}`, `{country}`). Each
  template defines its own **branch path**, so you choose the hierarchy the rule
  builds — `{region}/{country}/{site}`, `{region}/{country}`, `{site}`, or a
  fixed collector branch such as `Internet/{region}` that gathers matching
  graphs from every site into one place. Locations that land on the same branch
  with the same conditions share one rule.
- **Auto-created branch paths** — rule templates and aggregate rules name their
  target branch as a path such as `EMEA/Germany/Munich`, and any header in that
  path that does not exist yet is created during the run. Use `\/` for a literal
  slash inside one header name; leave a template's path blank to build the
  branch from Region / Country / Site.
- **Co-requisite rule templates** — graph templates that share a group name are
  placed as a unit: at a location, their tree rules, branch and graph placements
  are created only when every template in the group matches at least one graph
  there (e.g. an Internet graph only where an AnyConnect graph also exists).
- **Aggregate graph rules** — build/rebuild aggregate graphs from all member
  graphs matching a graph template, an unbounded list of device match
  conditions (**AND**/**OR** connectors with parenthesis grouping), and an
  optional graph title filter.
- **OID graph rules** — create graphs from SNMP generic-OID templates for
  matching devices.
- **Sync log** — full audit log of every run with per-device detail and CSV
  export.

## Documentation

The full guide is [HELP.md](HELP.md). It is also available inside Cacti under
**Console → Data Sync → Help**, and each plugin page links to its section.

## Licensing

Cereus Data Sync is gated by the [Cereus License
Manager](https://github.com/thurban/plugin_cereus_license). The plugin requires
at least a **Professional** licence.

| Capability                         | Professional | Enterprise |
|------------------------------------|:------------:|:----------:|
| Sync profiles                      |      3       | unlimited  |
| Tree automation rules per profile  |     10       | unlimited  |
| Scheduled (unattended) sync        |      —       |     ✓      |
| Site licence, per year (USD)       |    $899      |   $1,599   |

## Requirements

- Cacti 1.2.0 or newer
- PHP 7.4+ with the `zip` and `xml` extensions
- Cereus License Manager plugin (Professional or Enterprise)

## Installation

1. Copy this directory to `<cacti>/plugins/cereus_datasync/`, including
   `vendor/` (PhpSpreadsheet) and `lib/sync/`; the plugin needs nothing from
   Cacti's `cli/` directory. To rebuild `vendor/`, run `composer install` in the
   plugin directory.
2. In Cacti, go to **Console → Configuration → Plugins** and install/enable
   **Cereus Data Sync**.
3. Grant the *Plugin: Cereus Data Sync* realm to the appropriate user groups.
4. Create a sync profile under **Console → Data Sync**.

## License

GPL-2.0-or-later. © Thomas Urban / Urban-Software.de
