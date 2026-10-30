<p align="center">
  <img src="../assets/icon-256.png" alt="PrestaShift" title="PrestaShift" width="128" height="128">
</p>

<h1 align="center">PrestaShift</h1>

<p align="center">
  <strong>PrestaShop Migration Module</strong>
  <br>
  <strong>Simple · Elegant · Efficient</strong>
  <br>
  <a href="https://github.com/GajewskiMarcin/prestashift/releases">Download</a> ·
  <a href="#">Product page</a>
</p>

<p align="center">
  <a href="README.md">English</a> ·
  <a href="translations/README.fr.md">Français</a> ·
  <a href="translations/README.de.md">Deutsch</a> ·
  <a href="translations/README.es.md">Español</a> ·
  <a href="translations/README.pt.md">Português</a> ·
  <a href="translations/README.it.md">Italiano</a> ·
</p>

> **One module.** A professional data migration tool for PrestaShop. Easily migrate your store data between PrestaShop 1.7, 8 and 9.

<p align="center">
  <img src="https://img.shields.io/badge/PrestaShop-1.7%2B-DF0067?logo=prestashop&logoColor=white" alt="PrestaShop 1.7+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL 5.7+">
  <img src="https://img.shields.io/badge/MariaDB-10.3%2B-003545?logo=mariadb&logoColor=white" alt="MariaDB 10.3+">
  <img src="https://img.shields.io/badge/license-AFL--3.0-orange.svg" alt="Academic Free License 3.0 (AFL-3.0)">
</p>

**The PrestaShift project is a data migration module for PrestaShop.**

**In short:** it allows you to migrate data from an older PrestaShop store (1.7+) to a newer store (8 or 9), in batches, with resumable transfers, logging and a preview before migration.

Two-part architecture:

- **Source (old store)** → PSConnector module (read-only, token-secured API)
- **Target (new store)** → PrestaShift module (migration engine)

## Features

- **39-step migration pipeline** — products, categories, customers, orders, images, carriers, CMS, and much more
- **Two connection modes** — Bridge Connector (works across servers) or Direct Database (faster, same server)
- **Version-aware** — automatic detection of source/target versions with compatibility warnings
- **PS 1.7 → 8/9 transformations** — `redirect_type`, `id_type_redirected` auto-converted
- **Incremental sync** — migrate only changes since last sync
- **Batch processing** — configurable batch sizes with pause/resume support
- **Image transfer** — downloads images and generates all thumbnail sizes
- **Multistore support** — choose target shop ID
- **Pre-flight checks** — validates PHP limits, disk space, cURL before starting
- **Post-migration tasks** — auto rebuilds search index, category tree, clears cache
- **Dry run preview** — see record counts before migrating
- **File logging** — detailed logs in `var/logs/prestashift.log`
- **Redirect map** — generates 301 redirect file for SEO preservation
- **Status mapping** — map order statuses between source and target
- **Interface languages** — English, Polish, French, Spanish, Portuguese, Italian, German
- **Selective configuration** — migrates safe shop settings (name, SEO, shipping, etc.)
- **Multi-language** — English + Polish (translatable via PrestaShop Back Office)

## What gets migrated

| Area | Data |
|------|------|
| **Catalog** | Products, Categories, Attributes, Features, Stock, Packs, Virtual Products, Customization Fields, Tags |
| **Pricing** | Specific Prices, Catalog Price Rules |
| **Media** | Product Images (with thumbnails), Attachments, Manufacturer logos |
| **Customers** | Customers, Groups, Addresses, Wishlists |
| **Orders** | Orders, Order Details, History, Payments, Invoices, Credit Slips, Carts |
| **Brands** | Manufacturers, Suppliers, Product-Supplier links |
| **Shipping** | Carriers, Ranges, Delivery zones, Fees |
| **Content** | CMS Pages & Categories, Meta/SEO, Contacts, Physical Stores |
| **Localization** | Countries, States/Regions, Zones, Currencies, Languages, Tax Rules |
| **Admin** | Employees, Profiles, Cart Rules (with conditions), Shop Configuration |
| **Stock** | Stock Available, Stock Movements |

## Requirements

- **Target shop:** PrestaShop 1.7+ (where PrestaShift is installed)
- **Source shop:** PrestaShop 1.7+ (where PSConnector is installed)
- PHP 7.4+ with cURL extension
- MySQL 5.7+ / MariaDB 10.3+

## Installation

### On the TARGET shop (new shop):
1. Upload `prestashift/` folder to `/modules/`
2. Install via Back Office → Modules → "PrestaShift Migration"

### On the SOURCE shop (old shop):
1. Upload `psconnector/` folder to `/modules/`
2. Install via Back Office → Modules → "PrestaShift Connector"
3. Copy the generated secure token from the module configuration page

### Run migration:
1. Open PrestaShift on the target shop
2. Enter source shop URL + token
3. Select data scope
4. Configure options (batch size, clean target, etc.)
5. Launch migration

## Architecture

```
Source Shop (PS 1.7/8)          Target Shop (PS 8/9)
┌─────────────────┐            ┌──────────────────┐
│   PSConnector    │◄──HTTP──► │   PrestaShift     │
│   (read-only     │   Bridge  │   (migration      │
│    API bridge)   │           │    engine)         │
└─────────────────┘            └──────────────────┘
```

PSConnector exposes a secure, read-only API endpoint. PrestaShift connects to it via HTTP, fetches data in batches, transforms it for version compatibility, and imports it into the target database.

Alternative: Direct Database connection (PDO) for same-server migrations — faster, no bridge needed.

## Security

- Token-based authentication (64-char hex)
- Connector is **read-only** — write operations blocked
- File access restricted to `img/`, `download/`, `upload/` directories
- Path traversal protection with `realpath()` validation
- Timing-safe token comparison (`hash_equals`)

## Error reports (opt-in)

The module can e-mail a failure report to the author, to help diagnose migration problems.
**It is disabled by default** — you enable it with the *Send Error Reports to Developer* switch
in the Options step.

When enabled, a failed migration sends: the error message and debug payload, your PrestaShop and
PHP versions, `memory_limit` / `max_execution_time`, the source shop URL, the step the migration
stopped on and the settings it ran with (batch sizes, delays, scope), what the last call to the
source shop did, the last lines of the migration log, and the e-mail address of the logged-in
employee. Nothing is sent while the switch is off, and no customer, order or catalogue data is
ever transmitted. See [TelemetryService.php](prestashift/src/Service/TelemetryService.php) — it is
the only code path that sends anything to the author. The module's other outbound connection
(`ConnectorClient`) talks solely to the source shop URL you configure yourself.

## Support

- [Report issues](https://github.com/GajewskiMarcin/prestashift/issues)
- [Discussions](https://github.com/GajewskiMarcin/prestashift/discussions)
- [Buy me a coffee](https://buymeacoffee.com/marcingajewski)

## Author

Created by [marcingajewski.pl](https://marcingajewski.pl)

## License

[Academic Free License 3.0 (AFL-3.0)](LICENSE) — the standard licence for PrestaShop modules.

### Attribution Notice

The following is an **Attribution Notice** under Section 6 of the AFL-3.0. Section 6 requires that all derivative works retain it:

> PrestaShift was created by Marcin Gajewski — [https://marcingajewski.pl](https://marcingajewski.pl)
> Original project: [https://github.com/GajewskiMarcin/prestashift](https://github.com/GajewskiMarcin/prestashift)
> Support the work: [https://buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)
>
> Derivative works and forks must retain this notice, the author credit, and the support link displayed
> on the module's final screen. “PrestaShift” is the name of the original module: if you publish a
> modified version, please give it your own name.

Forks are welcome — fixes submitted as pull requests benefit all module users.

Contributions go through pull requests to `main`.

### Acknowledgments

PrestaShift is improved by these people:

| Who | Contribution |
|---|---|
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |

The same list is available in the module: **Settings → About → Thanks**
