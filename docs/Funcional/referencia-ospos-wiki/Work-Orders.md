_The following is a proposed enhancement to OSPOS.  Coming soon to a pull request near you._

In the service and repair industry there is the notion of a Work Order.  A work order is one phase of the life a sale.  It is a way to communicate to those doing the service and repair work what the customer wants and it is a way to communicate back to the sales rep and customer what actually happened.  The work order is usually found in a plastic protective sleeve.

This change will allow the sales clerk to prepare a work order and when the work is complete then the work order is updated to reflect what actually took place and then the sale is invoiced.

## Requirements/Features

* Introduce a new document type of work order that can be used to keep track of material, parts, and services that are performed for repair or service work.
* Be able to add a Cash Deposit payment or a Credit Deposit payment against a work order.
* Retain the work order so that if the customer cancels the work order then we can track the reason why in the sales comment.

## Definitions/Structures

The Work Order feature doesn't introduce any new tables.  However it does do the following

* Add new `sales_type` field to the `sales` table.  Sales type values are (0=POS Sale, 1=Invoice, 2=Work Order, 3=Quote, 4=Return)
* Add a new `sale_status` value of CANCELED.  So now the values are (0=Complete, 1=Suspended, 2=Canceled)
* Adds 3 new configuration values
  * work_order_enabled
  * work_order_format
  * last_used_work_order_number
* Adds 2 new payment types
  * Cash Deposit
  * Credit Deposit

## Rules/Constraints

* A new sale can be created as a work order.
* Any suspended sale can be converted to a work order.
* By default prices will not be included in the printed work order, however the user has on option to include prices in the printed work order.
* A work order is essentially a suspended sale that can be retrieved at any point in time to update with more information.
* Work orders must not be physically deleted because we want to be able to report why the work order was canceled.  The reason for cancellation should be noted in the sales comment field.
* Once work is completed on a work order it can be retrieved from its "suspended" state, updated and then invoiced.

## Operations


## Configuration

* To enable work orders go to the Config --> Invoice table, and page down to where the check box labeled Work Order Support can be found and check it.
* This change introduces a new token named {WSEQ:9} which follows the same convention as the ISEQ and QSEQ tokens. The default for the work order number takes advantage of that token and the starting work order number can be set in the Last Used Work Order Number field.

