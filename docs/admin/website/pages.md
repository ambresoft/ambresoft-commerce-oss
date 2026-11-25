---
title: Pages
parent: Website
grand_parent: Administration guide
nav_order: 3
---

# Pages

**Website → Pages** (`/admin/pages`)

Content pages hold information such as *About us*, *Privacy policy*, *Shipping information* or *Returns and exchanges*.

## Creating a page

Click **Create** and fill in the tabs:

| Section | Fields |
| --- | --- |
| **General → Information** | Parent page (pages can be nested), **name** per language (default language required), the **content** (rich text: headings, lists, tables, links, images by address, YouTube videos, HTML source mode), position and status |
| **General → SEO / Meta data** | Page title, meta description, meta keywords per language |
| **General → Availability** | Customer groups (*All* = everyone), creation date and an optional availability period |
| **Additional** | Facebook (Open Graph) object type and the **SEO name** per language, for example `privacy-policy`. Left empty, it is generated from the name |
| **FAQs** | General FAQs shown on the page |

Statuses: **Active** (listed and reachable), **Hidden** (reachable by its address only), **Disabled** (not reachable).

Unsafe HTML (scripts, forms, event handlers, other embeds) is removed when you save.

## Publishing a page on the storefront

A page appears on the storefront once a layout is linked to it:

1. Go to **Website → Layouts** and add a layout page (**Add layout page…**), or use an existing merchant-added layout.
2. Open its **Layout page settings** (gear icon) and choose the page to link.
3. The page is now served at `/{SEO name}`, inside the layout's **Main content** block. Add other blocks around it as
   you like.

After linking, the page editor shows **Preview** to open the page in a new tab whatever its status (staff only, not
indexed by search engines). Pages limited to customer groups show only to members; disabled, scheduled or expired pages
answer "not found".

## Forms

The **Form** tab lets you define a form for the page (fields such as text, email, phone, select boxes, checkboxes, date,
countries, file), the recipients, the email subject and the confirmation text.

{: .warning }
> In the current version the form is **stored but not yet shown on the storefront**, and submissions are not received
> or emailed. Do not rely on it for a contact form yet; put your contact details in the page content instead.

## The page list

The list shows the page tree with SEO name, position and status. Tick pages to **Activate**, **Hide**, **Disable** or
**Delete** them together. A page with sub-pages can only be deleted together with its sub-pages.

## Permissions

*View pages*, *Manage pages*.
