# nbpcurrency — NBP exchange rates for PrestaShop

A PrestaShop 8/9 module that fetches currency exchange rates from the National Bank of Poland (NBP) public API and writes them into PrestaShop's own currency conversion rates — no manual updates, no third-party paid services.

## Why this exists

- *Why am I still updating exchange rates by hand every day?*
- *Why do currency-rate modules on the marketplace charge a subscription for something a free central-bank API already provides?*
- *I only need a couple of currencies tracked (not a full 150-currency dashboard) — why is every existing option so heavy?*

This module talks directly to `api.nbp.pl`, so rates are grounded in the actual published NBP tables (A/B/C) rather than a paraphrased or scraped copy — pick which currencies to track and it keeps `ps_currency.conversion_rate` in sync, on a schedule you control.

## What's inside

| Item | What it does |
|---|---|
| **`nbpcurrency.php`** | Module class — NBP API client, cross-rate math (PLN-anchored), tracked-currency CRUD, hook + cron entry points |
| **`controllers/admin/AdminNbpCurrencyController.php`** | Back-office page: add/edit/remove tracked currencies, manual refresh, rate log |
| **`cron/update.php`** | Token-authenticated HTTP endpoint for external cron, for shops without the CronJobs module |
| **`views/templates/admin/configuration.tpl`** | Back-office configuration screen |

### Install

1. Zip the `nbpcurrency/` folder.
2. PrestaShop back office → **Modules** → **Upload a module** → select the zip.
3. Open the module's configuration page, add the currencies you want tracked and pick an NBP table (A, B, or C) per currency.

## How to use

- Each tracked currency is matched against one of NBP's three published tables — **A** (32 major currencies, daily mid rate), **B** (116 less-liquid currencies, weekly mid rate), **C** (13 currencies, daily bid/ask). The module validates your choice against the currency codes NBP actually publishes in each table.
- If your shop's default currency isn't PLN, that default currency must itself be tracked — NBP always quotes against PLN, so it's the anchor used to compute every other tracked currency's cross rate.
- Enable **auto update** to refresh rates via the `actionCronJob` hook (PrestaShop's CronJobs module), or call `cron/update.php?token=...` from an external cron — the token is generated on install and shown on the configuration page.
- Every fetch (success or failure) is written to a rate log, visible on the configuration page, for troubleshooting a missed or rejected update.

## Requirements

- **Verified against:** PrestaShop 9.1.4, PHP 8.x, MariaDB.
- **Declared compatibility:** PrestaShop 8.0+ (`ps_versions_compliancy` in `nbpcurrency.php`) — not yet manually tested on 8.x, only on 9.1.4.
- Outbound HTTPS access to `api.nbp.pl` is required from the shop's server.
- NBP's currency lists (which codes live in which table) are hardcoded as class constants, checked directly against the live API — table membership can change over time; open an issue if NBP adds/removes a currency and the module rejects it incorrectly.

## Contributing

Corrections and additions welcome — NBP's published tables and PrestaShop's core both keep moving. Open an issue or PR with the PrestaShop version you observed.

## License

[MIT](LICENSE).

## Author

Marcin Bręczewski ([@Breczek](https://github.com/Breczek)) — PrestaShop development.
