# Cereus Data Sync

Synchronise external inventory (SolarWinds exports / Excel workbooks) into Cacti
devices, and automate their placement into the Cacti tree.

A commercial [Cereus](https://urban-software.de) plugin for
[Cacti](https://www.cacti.net) 1.2.x.

## Features

- **Inventory sync** — import devices from an Excel/CSV inventory file, mapping
  columns to Cacti device fields, with add/update/delete reconciliation and a
  configurable deletion tag.
- **Profiles** — multiple named sync profiles, each with its own file source,
  column map, SNMP defaults, and schedule (manual, every poller, hourly, daily,
  weekly).
- **Tree placement rules** — template-driven Cacti automation rules that place
  matching devices/graphs into the tree per unique location. Conditions support
  full boolean grouping (**AND**/**OR** connectors with parentheses) and
  location placeholders (`{site_id}`, `{site}`, `{region}`, `{country}`).
- **Aggregate graph rules** — build/rebuild aggregate graphs from all member
  graphs matching a graph template, an unbounded list of device match
  conditions (**AND**/**OR** connectors with parenthesis grouping), and an
  optional graph title filter.
- **OID graph rules** — create graphs from SNMP generic-OID templates for
  matching devices.
- **Sync log** — full audit log of every run with per-device detail and CSV
  export.

## Licensing

Cereus Data Sync is gated by the [Cereus License
Manager](https://github.com/thurban/plugin_cereus_license). The plugin requires
at least a **Professional** licence.

| Capability                         | Professional | Enterprise |
|------------------------------------|:------------:|:----------:|
| Sync profiles                      |      3       | unlimited  |
| Tree automation rules per profile  |     10       | unlimited  |
| Scheduled (unattended) sync        |      —       |     ✓      |

## Requirements

- Cacti 1.2.0 or newer
- PHP 8.0+
- Cereus License Manager plugin (Professional or Enterprise)

## Installation

1. Copy this directory to `<cacti>/plugins/cereus_datasync/`.
2. In Cacti, go to **Console → Configuration → Plugins** and install/enable
   **Cereus Data Sync**.
3. Grant the *Plugin: Cereus Data Sync* realm to the appropriate user groups.
4. Create a sync profile under **Console → Data Sync**.

## License

GPL-2.0-or-later. © Thomas Urban / Urban-Software.de
