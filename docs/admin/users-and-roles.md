---
title: Staff users and roles
parent: Administration guide
nav_order: 5
---

# Staff users and roles
{: .no_toc }

**Users → Staff users** (`/admin/users`) and **Users → Roles** (`/admin/roles`)

Staff users are the people who work in the administration. What each person can see and do is decided by their
**roles**, and each role is a set of **permissions**.

1. TOC
{:toc}

---

## Roles

| Reference role | Starts with |
| --- | --- |
| **Super Admin** | Every permission, automatically, including permissions added later by add-ons. Cannot be edited or deleted |
| **Store Admin** | Products, categories, orders, order statuses and emails, call requests, payment and shipping methods, coupons, promotions, gift cards, newsletters and subscribers, reports, and viewing staff and roles |
| **Catalog Manager** | Products and categories |
| **Order Manager** | Orders (including payments and shipments) and call requests |
| **Content Manager** | Viewing and updating categories |

These are starting points: a Super Admin can change the permissions of every role except Super Admin, create new roles
(**Create**) and delete roles that no user has. A user with several roles gets all of their permissions together.

### Editing a role

1. Open the role and enter its **name** and optional **description**.
2. On the **Permissions** tab, tick the permissions. They are grouped by area (*Catalog*, *Orders*, *Customers*,
   *Website*, *Marketing*, *Payments, shipping and taxes*, *Settings and reports*, *Staff and access*, *Add-ons and
   other*) with an *All permissions* view, counters and a search box.
3. Save.

See the [Permissions reference](../../reference/permissions/) for every permission and what it allows.

{: .important }
> New permissions (for example from a new version or an add-on) are given to **nobody** automatically. After an
> upgrade, review your roles and grant what your staff need. Super Admins always have everything.

## Staff users

### Inviting a staff member (recommended)

1. Click **Create staff user**.
2. Enter the name and email, choose the **roles**, and keep the invitation option ticked
   (*Email a private registration invitation*).
3. Save. The person receives an email with a private link (valid **7 days**, usable once) to choose a password of at
   least 15 characters or register a **passkey**.

Until they accept, the user shows *Awaiting verification*; you can resend the invitation from their **Security** tab.

### Creating a staff member with a password

Untick the invitation option and enter a password yourself (at least 15 characters). This requires the permission to
set staff passwords. The person still has to verify their email before entering the administration.

### Editing and deactivating

- Edit name, email, roles and the **Account enabled** switch. Changing the email requires a new verification.
- **Deactivate** removes access on the person's next request. The account is kept, never deleted.
- You cannot deactivate yourself, and the store always keeps at least one active Super Admin.

### Security tab

The **Security** tab shows the user's sign-in history (Super Admins only) and lets authorized staff resend an
invitation or set a password.

## Who can manage staff

By default only **Super Admins** can create, edit, deactivate staff, set passwords, assign roles and change roles. A
Super Admin can delegate parts of this through a role, with built-in limits:

- nobody can grant permissions they do not have themselves or assign a stronger role;
- only Super Admins can edit roles that contain staff-management permissions, or edit Super Admin accounts;
- *Update staff users* does not include setting passwords or changing a recovery email
  (*Set staff passwords and recovery email* is separate).

## Recovering access

If no Super Admin can sign in, the server operator can promote an existing, verified user from the command line:

```sh
docker compose exec -T app php artisan cms:admin-grant someone@example.com
```

## Permissions

*View staff users*, *Create staff users*, *Update staff users*, *Deactivate staff users*, *Set staff passwords and
recovery email*, *Assign staff roles*, *View roles*, *Create roles*, *Update roles*, *Delete unused roles*, *Assign
permissions*.
