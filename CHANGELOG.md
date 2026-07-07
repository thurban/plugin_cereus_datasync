# Changelog

All notable changes to the Cereus Data Sync plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
