
[← Back to Usage Guide](Getting-Started-usage) | [Home](Home)

---

The application provides support for many countries, and currency amounts are printed in the local denomination. In some countries (and some locations) it may be convenient to print a receipt or invoice in another denomination.

To make this happen each sale to be printed in an alternative denomination requires that the exchange rate be provided.

To configure the system to support a second currency ... 

* Go to Configuration and select the Localization tab.
* Click the check box for "Use Alternate Currency"
* Determine the language code that indicates how the number should be formatted for display and enter it into the "Alternate Localization" field. 
* If the currency code isn't correct (such as you want to use Euro other than Pound Sterling) you can provide the actual symbol in the "Alternate Currency Symbol" field.

Now, when you enter a sale, at the bottom of the section on the right there should now be a check box for "Apply Exchange Rate" and to the rate is where you enter the exchange rate.  The last exchange rate used is provided for you (the last exchange rate entered is stored internally to the config table) and you can override it with the exchange rate that should be used for this sale.

If you close out a sale without selecting "Apply Exchange Rate" you can go to daily sales, locate the sale, go to "Update", change the exchange rate to be used. Then return to the list of sales documents and there will be two "print icons"  The first will print the document in the local and the second (the one with the two arrows pointing in different directions) will print the sales document using the alternate currency.

The exchange rate, alternate language, and alternate currency symbol is saved with each sale so that the sales document can be reprinted with the exchange rate and currency code that the system was configured for at the time of the sale.


 