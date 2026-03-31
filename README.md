# PrestaShift — PrestaShop Migration Module

Professional data migration tool for PrestaShop. Migrate your shop data between PrestaShop 1.7, 8, and 9 with ease.

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

## Support

- [Report issues](https://github.com/GajewskiMarcin/prestashift/issues)
- [Discussions](https://github.com/GajewskiMarcin/prestashift/discussions)
- [Buy me a coffee](https://buymeacoffee.com/marcingajewski)

## Author

Created by [marcingajewski.pl](https://marcingajewski.pl)

## License

MIT
