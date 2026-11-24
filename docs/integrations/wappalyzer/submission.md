# Ambresoft Commerce identification

The shared HTML document publishes this stable fingerprint on storefront and admin Inertia pages:

```html
<meta name="generator" content="Ambresoft Commerce">
```

This identifies the platform independently of the merchant's name, logo, favicon, domain, language or theme. It deliberately does not publish a release version. The merchant's favicon remains unchanged; Wappalyzer chooses technology icons from its own catalogue, not from the site's favicon.

## Submission packet

Use Wappalyzer's official [Suggest a technology](https://www.wappalyzer.com/technologies/suggest/) form:

| Field | Value |
| --- | --- |
| Technology name | Ambresoft Commerce |
| Website | https://ambresoft.com |
| Categories | CMS; Ecommerce |
| Description | Ambresoft Commerce is a self-hosted ecommerce CMS for managing products, storefront layouts, orders, payments and customer operations. |
| Logo | [Ambresoft.png](Ambresoft.png), the existing transparent 32×32 brand icon |
| Open source | Yes (AGPL-3.0-only) |
| SaaS | No; this submission describes the self-hosted CMS |
| Pricing | Free (open source); separately distributed add-ons may be paid |
| Fingerprint | HTML generator meta tag whose content is exactly `Ambresoft Commerce`; suggested regular expression: `^Ambresoft Commerce$` |
| Dependencies | Laravel; PHP; Inertia.js; React; Tailwind CSS; PostgreSQL. List these as platform facts, not additional browser fingerprints. |

Provide 2–3 publicly accessible example storefront URLs that actually run this release. `https://ambresoft.com` is the product website; do not also claim it as a detection example unless it serves the CMS and the marker can be verified. Localhost is not a public example. Contact details must belong to the submitting maintainer.

## Registration status and limitations

Prepared locally; **not submitted or accepted**. Adding the meta tag alone does not add an entry or icon to the official Wappalyzer extension. Wappalyzer must register the technology and distribute the definition before stock installations can recognise it.

As checked on 2026-11-11, the official form lists public detectability and an established user base of at least 1,000 users as criteria. Describe actual adoption honestly; acceptance and timing are controlled by Wappalyzer. The current official form accepts a square SVG or, alternatively, a 32×32 PNG. Do not rely on contribution instructions in historical GitHub forks as the current submission process.

## Verification

Inspect the initial HTML source of a storefront page for the exact generator tag. A detection rule should match that value and reject different products or suffixes. The normal storefront/favicon and admin functionality should continue unchanged. After Wappalyzer accepts the listing and updates its definitions, separately verify the CMS/Ecommerce classification and icon in the official extension on a public example site.
