---
title: Basics
parent: Administration guide
nav_order: 1
---

# Basics: signing in, the dashboard and your account
{: .no_toc }

1. TOC
{:toc}

---

## Getting access

Administration accounts are **by invitation only**. There is no public sign-up for staff.

- The **first Super Admin** is created by whoever installs the store, with a private setup link
  (see [Installation](../../getting-started/installation/#option-b-start-a-real-store)).
- **Other staff** are invited by a Super Admin (or by staff a Super Admin allowed to) in **Users → Staff users**. The
  invitation email contains a private link that works **once** and expires after **7 days**. Open it and either choose a
  password or register a **passkey**.

A customer account on the storefront never gives access to the administration.

## Signing in

1. Open `/admin/login`.
2. Enter your **Email address** and **Password** and click **Sign in**, or click **Sign in with a passkey**.
3. Tick **Remember me** on your own computer only.

Staff passwords must be **at least 15 characters** (up to 128). A passphrase of several words is easiest to remember.

**Forgot your password?** Click **Forgot password?**, enter your email and follow the link in the email. The link is
valid for 60 minutes and can be used once.

{: .note }
> After too many wrong attempts, sign-in is paused for a short time. Wait for the number of seconds shown and try again.

## Passkeys

A passkey lets you sign in with your device (fingerprint, face recognition or device PIN) instead of typing a password.

- **Add a passkey:** open your account menu (your name at the top right) → **Passkeys** → **Register a passkey**, and
  confirm with your current password.
- **Remove a passkey:** in the same screen. Only you can add or remove your own passkeys.
- Your password and password recovery keep working; a passkey is an additional way to sign in, not a second factor.

{: .important }
> Passkeys are tied to the store's exact address. On a live store, the address must use HTTPS.

## The top bar

| Element | What it does |
| --- | --- |
| **Search products** | Type a name or SKU / EAN and press Enter to open the product list filtered by it |
| **View storefront** | Opens the public store in a new tab |
| **Notifications** (bell) | New orders placed at checkout, with an unread counter |
| **Language** | Changes the administration language for you only |
| **Dark theme** | Switches the administration between light and dark |
| **Your name** | Account menu: **Passkeys** and **Sign out** |

### Notifications

When a customer places an order at checkout, every staff member who can **view and update orders** receives a
notification immediately; nobody else does. Click a notification to open the order (it is marked as read), or mark all
as read. Notifications are personal: reading or deleting one does not affect your colleagues.

### Your language

Pick any **active** store language from the language menu. It changes only your own screens and is remembered for your
next visit. If a Super Admin later deactivates your language, you see the store's default language until it is active
again.

## The dashboard

The dashboard (**Dashboard**, the first menu entry) shows the store at a glance. Choose the **Period** at the top:
**Day** (last 24 hours), **Week** (last 7 days) or **Month** (last 30 days); every figure is compared with the previous
period of the same length.

| Card | Meaning |
| --- | --- |
| **Sales** | Value of orders in statuses that reserve stock, with a chart against the previous period. Follow **Paid sales and detailed reports** for the reports |
| **Orders** | Orders placed in the period |
| **Still open** | Orders waiting to be paid |
| **Active products** | Active products, with how many are out of stock |
| **Registered customers** | Customers who signed up in the period |
| **Recent orders** | The latest orders with customer, status, date and total |
| **Activity** | Recent events: orders placed, order statuses changed, products updated, customers registered |

Super Admins also see the latest **account security** events (sign-ins and failed passwords) on the dashboard.

## Account security history (Super Admins)

For every staff and customer account the store records sign-ins, failed password attempts, registration origin, IP
addresses and browsers. Super Admins open it from a customer's page or from a staff user's **Security** tab.

- New IP addresses and new browsers are flagged for information; nothing is blocked automatically.
- Five failed passwords within 15 minutes show a warning.
- Approximate location and local time appear only if the server has a local GeoLite2/GeoIP2 City database
  (`SECURITY_GEOIP_DATABASE`); no IP address is sent to an outside service.

Only active, verified Super Admins can see this history; it cannot be delegated to other roles.

## Signing out

Account menu → **Sign out**. Changing your password signs you out on your other devices too.
