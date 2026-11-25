---
title: Logs
parent: System
grand_parent: Administration guide
nav_order: 3
---

# Logs

## Audit Logs

**Logs → Audit Logs** (`/admin/audit-logs`)

The audit log records **who changed what, and when**, for every important change in the administration: products,
categories, features, prices and stock, orders and statuses, customers, settings, roles and staff, add-ons and more. It
is written in the same database transaction as the change itself, so a saved change cannot miss its log entry.

- **Search** by user, entity or description, and **filter** by action, entity type, outcome (*Success*, *Failure*,
  *Denied*) and time range (UTC).
- Open an entry for its **details**: the changed fields with before and after values, the user, the origin, the IP
  address and a request ID that links entries of the same operation.

Personal data such as customer addresses, notes, review texts, payment credentials and secrets is deliberately **not**
copied into the audit log.

The server operator controls what is stored with each entry (`AUDIT_ACTOR_EMAIL`, `AUDIT_IP_ADDRESS`,
`AUDIT_USER_AGENT`). Audit entries are kept forever unless a retention period is configured
(`AUDIT_RETENTION_DAYS`) and `php artisan cms:audit-prune` is run.

**Permission:** *View audit logs*.

## AI log

**Logs → AI log** (`/admin/settings/ai-log`)

Every call to the AI provider (banners, blog posts, newsletters, FAQs) is listed with the staff member, the source,
the outcome and the input / output tokens, so you can follow usage and cost. API keys, prompts and responses are not
stored in this log.

**Permission:** *View AI call history*.
