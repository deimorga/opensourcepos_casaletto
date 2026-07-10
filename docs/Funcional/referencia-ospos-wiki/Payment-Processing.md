[← Back to Usage Guide](Getting-Started-usage) | [Home](Home)

---

## Overview

In normal POS operations a sale is made and the amount paid for the sale is entered. The sale cannot be completed until at least the amount due is entered as payment. If more than the amount due is received then the Change Due is reported and it is assumed that the buyer received the change due.

The amount of the payment and the amount refunded is tracked (along with when and who).

There is support for a manual payment transaction (used for POS sales) that allows the seller to close the sale even though there is a balance due.  This payment transaction is used when the site administrator wants the clerk to make a deliberate decision to close the sale with a balance due on it.  Only sales with a customer assignment can be closed with a balance due.

In an invoice sale it is normal for there to be a balance due.  All invoices require a customer be associated with the sale.  Although it's possible to enter a "due" payment transaction against an invoice, it is unnecessary.

Development is currently underway to better track balance due amounts (including "due" transactions) and to slightly improve the support for post sale payments.  This work is to resolve issue #3072
