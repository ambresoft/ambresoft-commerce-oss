---
title: Banners
parent: Marketing
grand_parent: Administration guide
nav_order: 3
---

# Banners

**Marketing → Banners** (`/admin/banners`)

The banner designer creates promotional banners with a live preview for **PC** (desktop), **tablet** and **phone**.
Banners are shown on the storefront by banner blocks in **Website → Layouts** (a single banner or a carousel with one
full-width banner per slide).

## Designing a banner

Click **Create** and work through the sections. The preview on the right shows your unsaved changes in the chosen
language.

| Section | Settings |
| --- | --- |
| **General** | Name per language (default language required), optional group, position, status, creation date |
| **Block** | Colour scheme (light or dark, which sets automatic text, button and mask colours), content alignment, inner padding, content on full width or half width |
| **Title** | Text per language with presets (line above the title, another colour, thin font, highlight, new row), size, colour, weight, HTML tag (div or h1–h6), shadow |
| **Description** | Short text per language, size, colour, background colour |
| **Object inside** | An image (per language), a YouTube video (per language) or products (grid, small items or thumbnails, up to 24 products) |
| **Background** | An image or MP4 video (per language), cover or contain, colour and position |
| **Button** | Show a button with text per language, style (standard, outline, text) and colours |
| **Additional** | Mask (semi-transparent, blurred, smooth dimming, colour), CSS classes with ready-made presets, a link (`https://…`, `/path` or `#anchor`) opening in the same or a new window, an availability period and customer groups |

### Tablet and phone

Turn on **Use own settings** to add a tablet or phone tab. Each device-dependent setting (alignment, padding, sizes,
background, button, mask, CSS classes) has an **Override** switch. Texts and images left empty use the PC version.

## When a banner is shown

A banner is live when it is **Active** and inside its optional **availability period** (date and time, entered in your
own timezone). Before the start it shows as *Scheduled* and after the end as *Expired*, so a Black Friday banner
disappears on its own. If you choose customer groups, only members of those groups see it.

## Groups, cloning and bulk actions

- **Groups** only organize the admin list (create, rename, disband, move banners between groups).
- **Clone** copies a banner as a disabled banner whose names start with `[CLONE]`.
- **Bulk actions**: enable, disable, move to a group, remove from a group, delete.

## Generating a banner with AI

With an AI key configured and the *Generate banners with AI* permission, describe the banner you want and the assistant
creates it in the store's languages. Review it in the preview before saving.

## Permissions

*View banners*, *Manage banners*, *Generate banners with AI*.
