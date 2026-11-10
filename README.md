This is a really simple WordPress plugin that syncs a Google Inventory Sheet with WooCommerce stock.

The sync is one-way only, from the Google Sheet to the Woo stock (your Woo stock will be overwritten).

The plugin uses SKU to identify the products.

How to use:

1. Download and install the plugin.
2. Create a Google Sheet with the following columns:

   - SKU
   - Stock
   - Regular Price
   - Sale Price
     
3. Publish the sheet as CSV and copy the URL
4. Go to WooCommerce --> Sheet Sync in your Dashboard
5. Fill in the sheet URL and click "Run Sync Now"

That's all!

You can add other columns with any information you like, but only the 4 listed columns will be synced.

## Import validation

Use an HTTPS Google Sheets CSV link. Supported links end in `/pub?output=csv`
or `/export?format=csv`, with optional tab parameters. The Google URL must be
readable by WordPress without signing in. Publish a dedicated tab containing
only information you intend to share, including any extra columns.

Version 1.0.4 limits each import to 2 MiB and 10,000 CSV rows. Each row needs a
unique SKU, a whole nonnegative stock quantity, and a nonnegative regular price.
Prices can use a decimal point and correctly grouped thousands separators.
Leave Sale Price blank or set it to zero to clear the sale price. A positive
sale price must be below the regular price.

The plugin checks the file and product permissions before writing. Unknown SKUs
are skipped. Only one sync can run per store at a time. The source URL and last
successful sync time are saved after the import completes. If WooCommerce fails
while saving products, the sync stops and reports how many saves completed.
Review those products before retrying.
