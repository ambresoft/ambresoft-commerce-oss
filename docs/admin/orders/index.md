---
title: Orders
parent: Administration guide
nav_order: 2
has_children: true
permalink: /admin/orders/
---

# Orders

The **Orders** menu holds everything about orders after a customer places them (or after you create one yourself):

| Screen | Use it to |
| --- | --- |
| [All orders](orders/) | Find, open, edit and process orders: status, payments, shipments, comments, invoices and packing slips |
| [Call requests](call-requests/) | Follow up customers who asked to be called back |
| [Order statuses](order-statuses/) | Define the steps an order goes through, what each step does to stock and which email it sends |
| [Email templates](email-templates/) | Edit the emails customers receive for each status and the order summary email |
| [Files](files/) | Edit the packing slip you print and put in the parcel |

## The life of an order

1. A customer completes checkout (or a staff member creates the order). The order starts in the **initial status**,
   *Open* by default, and the customer receives the email of that status. Staff who can view and update orders get a
   notification.
2. You move the order through statuses: *Processing* → *Ready for shipment* → *Shipped* → *Completed*. Each status
   decides whether stock is **taken**, **returned** or **left unchanged**, and emails the customer.
3. Payments are recorded on the order (automatically for online payments such as Stripe, by staff for cash on
   delivery or bank deposit). The **payment status** (*Not paid*, *Payment pending*, *Partially paid*, *Paid*) is
   separate from the order status.
4. If a carrier add-on is configured, you create the shipment (voucher), print the label and refresh tracking from the
   order.
5. When the order reaches a status that sends the **order summary** (*Completed* by default), the customer receives it
   by email and can download it from their account.
