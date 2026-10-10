=== SAAI Knowledge for WooCommerce ===
Contributors:         shinobiashi
Tags:                 woocommerce, faq, knowledge-base, glossary, product
Requires at least:    6.9
Tested up to:         7.1
Stable tag:           1.0.0
Requires PHP:         8.2
WC requires at least: 11.0
WC tested up to:      11.2
License:              GPLv2 or later
License URI:          https://www.gnu.org/licenses/gpl-2.0.html

Link FAQs, knowledge base articles, and glossary terms from SAAI Knowledge to WooCommerce products, and show them on product pages.

== Description ==

SAAI Knowledge for WooCommerce connects the FAQ, Knowledge Base, and Glossary content of [SAAI Knowledge](https://wordpress.org/plugins/saai-knowledge/) to your WooCommerce catalog, so each product page answers the questions shoppers actually have about that product.

= Link content to products =

* A "Linked Products" panel in the editor sidebar of every FAQ, knowledge base article, and glossary term: search for products and product categories and link them
* A "SAAI Knowledge" box on the product edit screen lists everything linked to the product, and lets you link or unlink content right there
* Linking a product category covers every product in it and in its subcategories

= Show it on product pages =

* **FAQ tab** — the linked FAQs as an accordion, with FAQPage structured data
* **Related documentation** — links to the linked knowledge base articles, below the product summary
* **Glossary tooltips** — the linked glossary terms are highlighted in the product's short and long descriptions, with a definition on hover or tap

Each insertion can be turned off in the WooCommerce section of SAAI Knowledge > Settings. It works with block themes (including Single Product templates customized in the Site Editor) and classic themes alike.

= Place it anywhere =

* **Product FAQ**, **Product Documentation**, and **Product Glossary** blocks, for the Single Product template, product loops, or any page — they use the product being displayed, or one you choose
* Matching shortcodes: `[saai_product_faq]`, `[saai_product_docs]`, `[saai_product_glossary]` (each takes an optional `product_id`)

= Built for support AI =

The SAAI Knowledge RAG export (REST and the admin download) gains `products` and `product_categories` for every linked record, so a support chatbot can narrow its search to the content about the product a customer is asking about.

= Permissions =

Anyone who can edit FAQs, articles, or glossary terms can link them to products — an Editor does not need WooCommerce's product permissions.

== Installation ==

1. Install and activate WooCommerce and [SAAI Knowledge](https://wordpress.org/plugins/saai-knowledge/) (the free plugin this add-on extends).
2. Upload and activate SAAI Knowledge for WooCommerce.
3. Open a FAQ, knowledge base article, or glossary term, and link it to products in the "Linked Products" panel of the editor sidebar — or open a product and use the "SAAI Knowledge" box.
4. View the product: the FAQ tab, related documentation, and glossary tooltips appear automatically. Turn any of them off in the WooCommerce section of SAAI Knowledge > Settings.

== Frequently Asked Questions ==

= Do I need the free SAAI Knowledge plugin? =

Yes. The FAQs, knowledge base articles, and glossary terms live in SAAI Knowledge (version 1.0.0 or later); this add-on links them to products and displays them.

= Which themes are supported? =

Block themes and classic themes. With a block theme, the content is added to WooCommerce's Single Product template, whether it shows the classic product tabs or the accordion of the Product Details block.

= Can I show the content somewhere other than the product page? =

Yes. Use the Product FAQ, Product Documentation, or Product Glossary block, or the matching shortcode, and choose the product in the block settings (or with `product_id`).

= Is High-Performance Order Storage supported? =

Yes. The add-on declares compatibility with High-Performance Order Storage and with the Cart and Checkout blocks; it does not read or write orders.

== Changelog ==

= 1.0.0 =
* Initial release.
