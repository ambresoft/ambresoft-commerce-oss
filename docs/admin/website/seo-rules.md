---
title: SEO rules
parent: Website
grand_parent: Administration guide
nav_order: 5
---

# SEO rules (redirects)

**Website → SEO Rules** (`/admin/seo-rules`)

Redirects send visitors and search engines from an old or changed address to its new place, for example after you
rename a category or move from another shop platform.

## Adding a redirect

Click **Add redirect** and fill in:

| Field | Notes |
| --- | --- |
| **Old address** | The path that moved, for example `/old-category` or `/phones.html`. Letter case, repeated and trailing slashes do not matter. No query string |
| **New address** | A path of this store (`/phones/smartphones`) or a full `http(s)://` address of another site |
| **Type** | **301 · Permanent** when the page moved for good (search engines transfer its ranking), **302 · Temporary** while the move is temporary |
| **Enabled** | A disabled redirect is kept but does nothing |

## Rules and warnings

- Each old address can have only one redirect, and it cannot point to itself.
- Addresses that belong to the store itself (the home page, administration, sign-in, cart, checkout, account, files)
  cannot be redirected.
- If the new address is itself redirected, you see a warning: visitors are sent straight to the final address, but
  pointing the rule there directly is cleaner.

Switch a redirect on or off directly in the list. Deleting a redirect stops it; to pause it, switch it off instead.

## Permissions

*View SEO rules*, *Manage SEO rules (redirects)*.
