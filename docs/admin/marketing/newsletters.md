---
title: Newsletters
parent: Marketing
grand_parent: Administration guide
nav_order: 7
---

# Newsletters
{: .no_toc }

**Marketing → Newsletters** (a sub-menu with Newsletters, Templates, Autoresponders, Campaigns, Mailing lists and
Subscribers)

1. TOC
{:toc}

---

## The parts

| Screen | What it is |
| --- | --- |
| **Mailing lists** | Lists people subscribe to (for example *Weekly offers*). Name and description per language, status, optional sender name, sender email and reply-to, a confirmation autoresponder, and whether the list is offered at checkout or registration |
| **Subscribers** | One entry per email address with its language, status (*active* or *unsubscribed*) and list memberships (confirmed or not) |
| **Newsletters** | The emails you send: name, subject (optionally several random subjects, one per line), body, campaign and audience |
| **Templates** | Reusable bodies per language. **Load** copies a template into a newsletter or autoresponder |
| **Autoresponders** | Confirmation emails sent automatically when someone joins a list without being confirmed |
| **Campaigns** | Groups of newsletters with a count of messages sent |

## Getting subscribers

- **Storefront**: visitors subscribe through the newsletter sign-up block (in **Website → Layouts**). Unconfirmed
  subscribers receive the list's confirmation email and are added once they confirm.
- **Manually**: **Create** on the Subscribers screen. Subscribers you add are confirmed directly and receive nothing.
- **Import**: import subscribers from a file on the Subscribers screen; **export** them too.

Confirmation and unsubscribe links open a page and only change anything when the person clicks the button, so email
security scanners cannot subscribe or unsubscribe anyone by following links.

## Writing a newsletter

1. **Create** a newsletter, choose the campaign and the **audience**: active mailing lists and/or selected registered
   customers.
2. Write the subject and body per language (or load a template). With an AI key and the permission, the assistant can
   write it for you.
3. Insert variables written in double curly braces: {% raw %}`{{unsubscribe_link}}`, `{{subscriber_email}}`,
   `{{company_name}}`, `{{company_address}}`, `{{company_phone}}`{% endraw %} (and {% raw %}`{{activation_link}}`{% endraw %}
   in confirmation emails). Unknown variables are refused when you save.
4. **Preview**, then send a **test** to one address.
5. **Send**.

## How sending works

- One message is created per recipient address: confirmed subscribers of the chosen active lists plus the chosen
  customers. Unsubscribed addresses are skipped. Sending again only reaches addresses that did not receive it yet.
- Messages are sent in the background by the worker. Every minute the scheduler re-queues waiting, interrupted and
  failed messages (up to 3 attempts, 5 minutes apart) and marks the newsletter *sent* when everything is done.
- The sender is the mailing list's sender, otherwise the store's sender from **Settings → General settings → Emails**.
- Each recipient gets the newsletter in their language, or the default language if that translation is empty.

{: .important }
> Sending needs the background **worker** and **scheduler** to be running.

## Permissions

*View newsletters, templates, autoresponders, campaigns and mailing lists*; *Manage …* (same items); *Send newsletters
and test messages*; *Write newsletters with AI*; *View and export newsletter subscribers*; *Add, edit, import and delete
newsletter subscribers*.
