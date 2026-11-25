---
title: FAQs
parent: Website
grand_parent: Administration guide
nav_order: 4
---

# FAQs

**Website → FAQs** (`/admin/faqs`)

FAQs are reusable questions and answers that you link to products, categories and pages, where they appear in a
*Questions* section.

## Types

| Type | Can be linked to |
| --- | --- |
| **General** | Products, categories and pages |
| **Category** | Categories only |
| **Product** | Products only |

One FAQ can be linked to many objects of its type. There is no automatic inheritance: an FAQ linked to a category is
not shown on its products.

## Creating an FAQ

Click **Create**, choose the type, then enter for **every active language** the **question** (up to 500 characters) and
the **answer** (rich text, up to 20,000 characters). Set the status (Active or Disabled) and the position.

## Linking FAQs

Open a product, category or page and use its **FAQs** tab: pick FAQs, order them (up to 100) and save the object. Only
active FAQs are shown on the storefront. A linked FAQ cannot change type or be deleted; disable it instead.

## Generating FAQs with AI

With an Anthropic API key in **Settings → General settings** and the *Generate FAQ drafts with AI* permission (plus
*Manage FAQ content*), the assistant suggests questions and answers for a product, category or page in the store's
languages. You review the drafts and save the ones you want. Every generation is listed in **Logs → AI log**.

## Permissions

*View and link FAQs*, *Manage FAQ content*, *Generate FAQ drafts with AI*.
