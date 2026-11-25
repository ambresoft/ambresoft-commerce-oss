---
title: Social login
parent: Website
grand_parent: Administration guide
nav_order: 6
---

# Social login

**Website → Social login** (`/admin/social-login`)

Social login lets customers sign in to the storefront with an account they already have. **Google** is supported.

## Setting up Google sign-in

1. In the [Google Cloud Console](https://console.cloud.google.com/), open **APIs & Services → Credentials** and create
   an **OAuth client ID** for a web application.
2. In the administration, click **Create** (or open the Google provider) and open the **Callback URL** tab. Copy the
   address shown (it ends in `/auth/social/google/callback` and is based on your store's `APP_URL`) and add it to the
   OAuth client's **Authorized redirect URIs** in Google.
3. Back in the administration, enter the **Name**, the **Client ID** (ending in `.apps.googleusercontent.com`) and the
   **Client secret**, set the status to active and save.

Customers now see **Continue with Google** on the sign-in and registration pages.

The client secret is encrypted and never shown again; leave the field empty to keep it, or type a new one to replace
it. Deleting the provider deletes its stored credentials.

{: .important }
> The callback address must match your store's real address exactly (HTTPS on a live store). If you change the store's
> domain, update the redirect URI in Google too.

## Permissions

*View social login providers*, *Manage social login providers and credentials*.
