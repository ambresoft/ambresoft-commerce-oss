---
title: Customer groups
parent: Customers
grand_parent: Administration guide
nav_order: 2
---

# Customer groups

**Customers → Customer groups** (`/admin/customer-groups`)

Customer groups are segments of customers, such as *Retail*, *VIP* or *Wholesale*. Membership alone changes nothing; the
groups are used by other features:

- **Visibility**: categories, products, pages and banners can be restricted to certain groups;
- **Prices and discounts**: product sale rules and promotions can target groups;
- **Shipping**: shipping methods can be limited to groups.

A customer can belong to several groups.

## Creating a group

Click **Create customer group** and enter:

| Field | Notes |
| --- | --- |
| **Name** | Per language; unique per language |
| **Description** | Optional |
| **Default group** | New customers join this group unless you choose otherwise. Only one group can be the default, and it must be active |
| **Status** | Disabled groups stay on existing customers but cannot be newly assigned |

**Guests** (visitors who are not signed in) belong to no group: they do not see content restricted to groups and do not
match group-restricted promotions. Only for product **sale rules** that target a group are guests treated as members of
the default group.

## Deleting a group

- The default group cannot be deleted.
- A group with members asks for another active group; the members move there before the group is deleted.
- A group that still restricts the visibility of products or categories, or is used by sale rules, cannot be deleted.

## Permissions

*View customer groups*, *Manage customer groups*.
