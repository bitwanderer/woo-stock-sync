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

## Connect a private Google Workspace sheet with OAuth

Use this setup if your organization blocks service-account key creation. It
keeps the spreadsheet private and requires an HTTPS WordPress admin URL and PHP
OpenSSL.

1. Install Woo Stock Sync 1.0.6 or later. As a WordPress administrator, open
   **WooCommerce → Sheet Sync** and copy the **Google OAuth callback URL**.
2. In a Google Cloud project owned by your Workspace organization, enable the
   **Google Sheets API**. Go to **Google Auth Platform → Branding**, complete
   the app details, and set **Audience** to **Internal**.
3. Under **Google Auth Platform → Clients**, create a **Web application**
   client. Paste the callback URL exactly into **Authorized redirect URIs**,
   including the query string. Leave Authorized JavaScript origins empty.
4. Add these settings to `wp-config.php` before the line that loads WordPress:

   ```php
   define( 'WC_GSS_GOOGLE_CLIENT_ID', 'YOUR_WEB_CLIENT_ID' );
   define( 'WC_GSS_GOOGLE_CLIENT_SECRET', 'YOUR_WEB_CLIENT_SECRET' );
   define( 'WC_GSS_GOOGLE_SPREADSHEET_ID', 'ID_FROM_THE_NORMAL_EDIT_LINK' );
   define( 'WC_GSS_GOOGLE_SHEET_TAB', 'products' );
   ```

   Use the ID between `/spreadsheets/d/` and `/edit`. Keep the client secret
   out of Git and chat. Put the four import columns in A–D, with headers in
   row 1, and format stock and prices without currency symbols.
5. Return to **WooCommerce → Sheet Sync**, click **Connect Google**, and sign
   in as a Workspace user who can view the sheet. Keep your WordPress session
   open during consent. Then click **Run Sync Now**.

Google grants read-only access to all spreadsheets the authorizing account can
view. For least access, use a dedicated Workspace account with Viewer access
to only the inventory file. If Workspace blocks authorization, ask your admin
to approve the OAuth client ID for Google Sheets. Use **Disconnect Google** to
remove the stored connection from WordPress. Revoke the app in your Google
account if you also want to remove Google's grant.

## Use a service account for a private sheet

1. Enable the Google Sheets API in your Google Cloud project and share a
   dedicated inventory spreadsheet with the service account as **Viewer**.
   Keep the sheet private. Domain-wide delegation is unnecessary.
2. In Google Cloud, open the service account and choose **Keys → Add key →
   Create new key → JSON**, if your organization permits it. Put the JSON file
   on the WordPress server outside the web root, readable only by the site
   user running PHP (mode `0600` on Linux). PHP needs OpenSSL. Keep the key
   out of Git, WordPress uploads, and chat.
3. Add these settings to `wp-config.php` before the line that loads WordPress:

   ```php
   define( 'WC_GSS_GOOGLE_KEY_FILE', '/absolute/private/path/woo-stock-sync.json' );
   define( 'WC_GSS_GOOGLE_SPREADSHEET_ID', 'ID_FROM_THE_NORMAL_EDIT_LINK' );
   define( 'WC_GSS_GOOGLE_SHEET_TAB', 'products' );
   ```

   Use the ID between `/spreadsheets/d/` and `/edit` in the normal Sheets URL.
   Put the four import columns listed below in columns A–D, with the headers
   in row 1. Format SKUs with leading zeros as plain text. Format stock and
   prices as numbers without currency symbols, using a decimal point.
4. Open **WooCommerce → Sheet Sync** and click **Run Sync Now**. The plugin
   uses read-only Sheets API access and keeps the credential on the server.

If your organization disallows service-account keys or prevents sharing with
the service account, use the internal OAuth setup above.
Public CSV links still work on sites that do not configure a private sheet.

## Prepare and sync a public sheet

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
