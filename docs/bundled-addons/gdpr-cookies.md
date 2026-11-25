---
title: GDPR Cookies
parent: Bundled add-ons
nav_order: 7
---

# GDPR Cookies

A cookie consent banner for the storefront. Visitors **accept**, **reject** or **choose by category** with equal ease, and
nothing optional runs before they consent.

## Setting it up

1. **Add-ons**: install and enable **GDPR Cookies**.
2. Grant *View GDPR cookie settings* and *Manage GDPR cookie settings* to the right roles. Grant *Manage GDPR cookie
   scripts* only to trusted staff: scripts run in visitors' browsers.
3. Restart the scheduler (it purges old consent records daily): `docker compose restart worker scheduler`.
4. Open the add-on's screen from its link on the **Add-ons** page.

## The tabs

| Tab | Settings |
| --- | --- |
| **General** | Banner title and text, *about cookies* text and the **Privacy policy address** per language (empty uses the built-in text); **Remember a choice for (months)** (at most 12; 6 is common); **Show the cookie button** so visitors can reopen the settings at any time; **Ask everyone again** |
| **Categories** | The consent categories with name and description, and which optional switches start on for visitors without a saved choice |
| **Cookies** | Declare every cookie the store sets: name (a name ending in `*` covers a family such as `_ga_*`), provider, duration, purpose and category |
| **Scripts** | Snippets that run only after a visitor consents to their category, and only on public storefront pages |
| **Consent log** | The recorded decisions (totals and the last 30 days). Each decision is kept without IP addresses or other personal data |

{: .warning }
> In the EU/EEA, preselected optional categories do not establish valid consent. Keep optional switches **off** by
> default when consent is required, including for marketing.

Saving scripts asks every visitor for consent again. While the add-on is enabled, Google Analytics and Google Tag
Manager wait for consent too.

{: .note }
> The add-on gives you the tools; deciding which cookies need consent and writing your cookie texts remains your
> responsibility. Without a consent add-on, nothing is held back.
