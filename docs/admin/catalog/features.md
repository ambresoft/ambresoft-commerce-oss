---
title: Features
parent: Catalog
grand_parent: Administration guide
nav_order: 2
---

# Features
{: .no_toc }

**Catalog → Products → Features** (`/admin/features`)

A **feature** is a characteristic of products, such as *Colour*, *Storage*, *Screen size* or *Material*. Features are
shown in the product's specifications, can be used as **filters** in category pages, in **product comparison**, and to
define **variations**.

1. TOC
{:toc}

---

## Feature types

| Type | Use for | Example |
| --- | --- | --- |
| **Text** | Free text per language | *Care instructions* |
| **Single choice** | One value from a predefined list | *Colour: Black* |
| **Multiple choices** | Several values from a predefined list | *Connectivity: Wi-Fi, Bluetooth* |
| **Number** | A number with an optional unit (zero and negative allowed) | *Weight: 1.8 kg* |
| **Yes/No** | Yes or No | *Waterproof: Yes* |

The type cannot be changed after creation.

## Creating a feature

1. Click **Create**.
2. **General**: the **Name** (per language; required in the default language), the **Feature Group** and the type. A
   permanent **Code** is generated from the name.
3. **Settings**:
   - **Required**: products cannot be activated without a value;
   - **Use in product filters** (not for Text);
   - **Show in product comparison**;
   - **Feature Purpose** (see below).
4. **Categories**: **Category applicability**. Leave it unrestricted to apply the feature to every category, or choose
   categories (subcategories included).
5. For choice types, add the **Predefined options** (each with a label per language) and save.
6. Set the status to **Active**.

A feature is **incomplete** until it has a default-language name and, for choice types, at least one active option with
a default-language label. Incomplete features can be saved as drafts but not activated.

## Feature purpose

| Purpose | Meaning |
| --- | --- |
| **Standard Attribute** | Ordinary product information: shown on the product, usable for filters, comparison and required fields |
| **Brand / Author Landing Pages** | Single choice only. Each value can have its own page (description, image, SEO and URL slug), for example an author page listing all their books. Used only when **Settings → General settings → Catalog → Brand landing pages** uses features instead of dedicated brands |
| **Variations as separate products** | Single choice only. Each value identifies a separate product in a [variation group](../variation-groups/), for example *Red* or *Size 40* |

For variation features you also choose the **Selector style** customers see on the product page: **Text buttons**,
**Color swatches**, **Image swatches** or **Dropdown**, and set a swatch colour or image per value. Values without a
swatch show their text label.

The purpose cannot be changed away from *Variations* while a variation group uses the feature.

## Feature groups

Every feature belongs to one group (for example *Display*, *Battery*, *General*). Groups organize the specifications
table and the product editor's Features tab. Manage them at **Features → Groups**. The protected *General* group always
exists.

## Ordering

**Features → Ordering** (`/admin/features/ordering`) arranges groups and the features within them:

- drag a feature by its handle within or between groups, or drag a group to reorder groups;
- without a mouse: focus a handle and use the arrow keys, the row's up/down buttons or **Move to group**;
- the side panel lists pending moves with **Undo**; nothing changes until you click **Save order** (**Discard changes**
  cancels).

## Archiving and deleting

- **Archive** a feature or option to stop using it. Products keep their values as history; archived items are read-only
  until restored.
- **Delete** permanently only features or options that no product has ever used.

## Remediation

If a feature becomes required (or applies to new categories) after products were already active, those products are
listed at **Features → Remediation** with links to fix them. Active products are never disabled automatically.

## Permissions

*View features*, *Create features*, *Edit features and options*, *Activate, archive and restore features*,
*Permanently delete features*, *Manage Feature Groups*, *Order features and groups*.
