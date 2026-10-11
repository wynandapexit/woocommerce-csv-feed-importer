# WooCommerce CSV Product Feed Importer

This plugin imports WooCommerce products from a CSV file using the same column structure as the supplied feed template.

## Default mapping

The importer understands these CSV columns automatically:

- ProductName -> name
- ProductCode -> sku
- Category -> product_cat
- ProductSummary -> description
- Price -> regular_price
- AvailableQty -> stock_quantity
- Image -> image

## Example row

```csv
ProductName,ProductCode,Category,ProductSummary,Price,AvailableQty,Image
"Sa Medium Flag, Retail Packaged , ",MEDIUM FLAG,Mouse Pad,"Sa Medium Flag, Retail Packaged , ",10.8000180,488,https://www.xyz.co.za/ProdImg/Big_SMALLFLAG-01.png
```

## Installation

1. Copy the folder `woocommerce-csv-feed-importer` into your WordPress `wp-content/plugins` directory.
2. Activate the plugin in WordPress.
3. Open WooCommerce → CSV Feed Importer.
4. Either upload the CSV file or provide a public URL to the feed.
5. Click Import Products.

## Notes

- If a product with the same SKU is found, it is updated instead of duplicated.
- Product categories are created automatically when they do not already exist.
- Product images are fetched from the URL in the `Image` column and attached as the featured image.
- Product stock is managed using the `AvailableQty` column.
