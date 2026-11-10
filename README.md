# Woo Stock Sync

Woo Stock Sync imports stock quantities, regular prices, and sale prices from
a Google Sheets CSV into WooCommerce. It matches products by SKU and runs
manually from the WordPress dashboard. The sheet is the source of truth for
each matched product, so check the values before running a sync.

## Install or update

1. Download the plugin ZIP from the [latest release](https://github.com/bitwanderer/woo-stock-sync/releases/latest).
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin** and select
   the ZIP. If WordPress offers to replace the existing plugin, confirm the
   replacement.
3. Activate the plugin. WooCommerce must be active.

The release ZIP installs into `wc-google-sheet-sync`. If an earlier ZIP
installed a second copy under `woo-stock-sync-main`, deactivate that copy
before upgrading and keep only `wc-google-sheet-sync` active. The saved
sheet URL is stored in WordPress and is shared by both copies.

## Prepare and sync a sheet

1. Create a sheet with the following columns.

   | Column | Accepted values |
   | --- | --- |
   | SKU | A unique SKU for each row. Format this column as plain text if your SKUs have leading zeros. |
   | Stock | A whole number zero or greater. |
   | Regular Price | A number zero or greater. Use a decimal point for fractional amounts. |
   | Sale Price | A positive price below the regular price. Leave blank or enter zero to clear the sale price. |

2. In Google Sheets, choose **File → Share → Publish to web**, select the tab
   to publish, and choose **Comma-separated values (.csv)**. Copy the HTTPS
   link. Supported links use `/pub?output=csv` or `/export?format=csv` on
   `docs.google.com`, with optional tab parameters.
3. In WordPress, open **WooCommerce → Sheet Sync** as an Administrator or Shop
   Manager, paste the link, and click **Run Sync Now**.
4. Check the result for updated products and unmatched SKUs.

Publish a dedicated tab containing only data you are comfortable sharing.
Extra columns are ignored by the importer but are still included in the
published CSV. A login wall on the WooCommerce site does not restrict the
Google-hosted file. WordPress must be able to fetch the CSV without signing
in to Google.

## What happens during a sync

- Imports are limited to 2 MiB and 10,000 rows. One import can run per store at
  a time.
- The plugin validates the entire CSV and checks product edit permissions
  before it starts writing. Duplicate SKUs and invalid rows stop the import.
  Unknown SKUs are skipped.
- The saved source URL and last successful sync time update after the import
  completes. If WooCommerce fails while saving, earlier saves can remain.
  The error reports how many saves completed so you can review the catalog
  before retrying.
