
[← Back to Configuration](Configuration) | [Home](Home)

---

The application can be used for businesses based on **weighted items**, such as dairy markets, grocery shops, roasteries, and fruit/vegetable markets.

## How to Use Scales as an Input Device 

The idea is that you configure the scale to readout the variables in a fixed string format. Most scales are regular USB devices that can be configured to send keys as if they were a keyboard. One thing you need to do is setup the format in OSPOS so it knows which data goes where.

### Where to configure

The feature allows you to use a specific barcode format to parse the barcode set in configuration panel as below:

![Input Field](http://ospos.wshells.org/Wiki/Input.png)

As you can see, the input field should be filled with the barcode format you are printing using the weighing scale.

Below is a filled in example:

![Input Filled](http://ospos.wshells.org/Wiki/Filled.jpg)

### The format before 3.3.2

```02(\d{5})(\w{6})```

* 2 first characters represent the department code
* 5 following digits represent the item weight
* last 6 characters represent the item barcode

### The format in 3.3.2 and beyond

The format adheres to the token formatting used also in ([invoices, quotes and work orders](https://github.com/opensourcepos/opensourcepos/pull/2797)).

```02{W:5}{I:6}```

* 2 first characters represent the department code
* 5 following digits represent the item weight
* last 6 characters represent the item barcode
* One can also use price as {P:3} as a last variable

Please set the **quantity decimals** to 2 in order to make the system parse the quantities correctly.
For further info, [we are just a click away](https://github.com/opensourcepos/opensourcepos/issues/new)!

### Further/in-depth explanation

Barcodes embedded with data, such as weight or price or item ID can be parsed with a regex code (this is simpler than it sounds).

let's assume your barcode is an EAN13 barcode (in theory, this should work for any type of barcode), and your barcode looks like this:

2000021019409

we can break this barcode up into the following segments:

* 20 (company or country code)
* 0002 (item ID)
* 1 (random digit for barcode uniqueness)
* 01940 (price)
* 9 (checksum digit)

in order to write out regex code for this barcode (assuming all other barcodes are generated the same) you would need to use the following:
* 20{I:4}\d{P:5}\d 
* (worth noting, the last "\d" can be left out)

let's understand the components of this, and see what our options are:
* 20 is the company code, as mentioned above. this is an unchanging component.
* {:} is to indicate that whatever is within these curly braces specifies a range of data that matches what is in the brackets.
* I = item ID, and will always separate out whatever is within the specified range as the ID
* W = weight/quantity of the item specified. this value is divided by 1000 by default.
* P = price. by default, price does not consider cents. 
* \d = digit placeholder. this digit will be ignored by default.

for more information about regex codes, please refer to this link: https://www.w3schools.com/php/php_regex.asp


the regex code you build ought to be placed into the "Barcode Formats" field, found in Configuration > Barcode

the item ID for the item you are scanning should be placed into the "Barcode" segment of the item you are wishing to add by scanning.

Should the price be embedded into the barcode, be sure to check the "amount entry" radio button on the item config screen.


WARNING: The following section is only recommended if you are technically inclined

should you wish to accomodate cents into your barcodes price, you will need to edit the following file accordingly:

* File: Application/libraries/Token_lib.php
* find line number 94
* edit this line by adding " / 100" after "(double) $parsed_results['P']"