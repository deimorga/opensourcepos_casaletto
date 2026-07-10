
[← Back to Configuration](Configuration) | [Home](Home)

---

## Table of Contents

* [FAQ's](#faqs)
* [General Printing Support](#general-printing-support)
* [Device Printing Support](#device-printing-support)
* [Barcode Printing Support](#barcode-printing)
* [How to printing](#how-to-printing)
  * [Preparation printing for firefox/palemoon](#preparation-printing-for-firefoxpalemoon)
  * [Preparing printing for Chrome/Chromium](#preparing-printing-for-chomechromium)
* [Advanced and Autoprinting](#advanced-and-autoprinting)
  * [Firefox, Palemon, Icecat](#firefox-palemoon-icecat)
  * [Waterfox Classic](#waterfox-classic)
  * [Chrome](#chrome)
  * [Fiscal printing](#fiscal-printing)

## FAQ's
* Why cant I adjust the print settings in OSPOS> configuration > receipt?
> <img src="https://user-images.githubusercontent.com/38707933/161402193-4201e802-d995-4abd-ad65-f1703daf7b0d.png" width="200">

> These settings are only available if you have setup [Advanced and Autoprinting](#advanced-and-autoprinting). Auto printing prevents the print-dialog box from appearing allowing OSPOS to print receipts without you prompting it at the end of a sale.

## General Printing Support

Currently OSPOS can print in any environment, you can use a installed printer. The only limitation its Fiscal KIT's due on that need AD-HOC special setup depends on each country requirements.

## Device Printing Support

The recommend receipt is the Star TSP 100 ECO using the Firefox or Chrome browsers, with this if there's no fiscal kit, we can print directly. Our recommended label printer is the Zebra LP 2824 Plus printer with 2 x 1 labels. 
Other customers have had success with other printers from companies. If you plan to purchase a different receipt printer than the recommend, try to get one that prints on paper that is 72 mm or greater.

Obviously prints depends of the browser, so must be first configure here, and then in ospos 
the printing configuration has some options, only enable with some changes over the browser.
For the Zebra its recommended also search at https://www.peninsula-group.com/mac-thermal-printer-driver/ 
the CUPS interface for Unixes comes with basic enough support that works as its.

## Barcode Printing

This feature doesn't need special settings, but a printer must be correctly configured. For printing instructions, see the [Getting Started Usage: Inventory](Getting-Started-usage#3-inventory) section.

But remenber as we previously mentioned, Our recommended label printer is the Zebra LP 2824 Plus printer with 2 x 1 labels. Other customers have had success with other printers from companies.


# How to printing

Either for normal printing or for devices/receipt printing, first need OS configuration/installation printer, then browser configuration and lasted go to **Office->StoreConfig->Receipt**, here the most important settings are *Format* and *Font size*.

![printing settings:](https://user-images.githubusercontent.com/10962177/36354930-c33ea10c-1498-11e8-8b0b-a4eb2b2ecbdb.png)

* **Format** defines amount of information to the receipt will be printed. Reduced can be help with some printing settings.
* **FontSize** defines size of the font this must be set in correct size number depending of the output of the printing.

Then the **default receipt shows more info than the short (order)** receipt. So **for printing in 78mm printers and related, must use "order" as format**.

The **Autoprinting** are explained here in below specific section.

Unless margings and show dialog printing rest of the options are self explanatory, please read section below for.

  * [Preparation printing for firefox/palemoon](#preparation-printing-for-firefoxpalemoon)
  * [Preparing printing for Chome/Chromium](#preparing-printing-for-chomechromium)
* [Advanced and Autoprinting](#advanced-and-autoprinting)
  * [Firefox, Palemon, Icecat](#firefox-palemoon-icecat)
  * [Waterfox Classic](#waterfox-classic)
  * [Chrome](#chrome)
  * [Fiscal printing](#fiscal-printing)


### Preparation printing for firefox/palemoon/waterfox classic

1. download, configure and install the printer in the OS, and config that can print a simple letter at least.
2. In the browser click the menu button at top right of screen (three horizontal bars)
3. Choose Print
4. Click Page Setup (Make sure print background images is unchecked)
5. Click Margins & Header/Footer
6. Set all margins to 0.
7. Set Headers and Footers to --Blank--

### Preparing printing for Chome/Chromium

1. download, configure and install the printer in the OS, and confir that can print a simple letter at least.
2. In the menu button at top right of screen (three horizontal bars) select Print
3. In the dialog the pops up, select the printer destination that just instaled and configured in OS
4. In same dialog, click "More settings", or "advanced settings"
5. and select Layout:Portrait, Paper:75mm, Margings:default, 
6 also in Options deactivate headers and deactivate background

## Recommended Printing settings

Remember that the printer its recommended 75mm paper setup.

1. Go to OS printers interface, in MAC/Linux got CUPS at http://localhost:631
2. Select the printer and then Printer Properties
3. Click Device Settings Tab or General Options
4. In the Form to Tray Assignment or Media size choose 72mm x Receipt
5. Cash Drawer options select 1 and 2
6. Choose media of 2x1 and resolute

# Advanced and Autoprinting

Advanced printing allows set margins, and the "direct printer" to, only by selecting the "Order" format.

This feature is currently available in combination with browsers Palemoon, Icecat, Iceweasel, Waterfox, Firefox and with some little changes in Chrome. For most modern browsers another way to do by changing deep settings in firefox `about:config` interface options.

  * [Firefox, Palemon, Icecat](#firefox-palemoon-icecat)
  * [Waterfox Classic](#waterfox-classic)
  * [Chrome](#chrome)
  * [Fiscal printing](#fiscal-printing)

### Firefox, Palemoon, Icecat

AutoPrinting support also advanced feature are enabled in two ways, the first using the jsPrint addon at
https://addons.mozilla.org/es/firefox/addon/js-print-setup The supported browsers must be **firefox << 49.9**, **Palemoon >> 24**, and **Icedcat >> 16**. The second way its preconfiguring the browser to printing directly.

Enable jsprint/seamlessprint settings in Firefox, must configure with enable for all the sites (low security, sorry). In some cases newer firefox can try https://addons.mozilla.org/es/firefox/addon/seamless-print/ but not Quamtun Firefox.

After enabling/configure this addon, the ospos print settings will be available and can be configured.

Go to Store config and reselect the printers, occasionally had to select a different printer. Submit. 
Then go back and select the correct printer. Then submit.

**Alternate second way for newer browsers**: Type about:config at Firefox’s/Palemoon's location bar and hit Enter. Right click at anywhere on the page and select New > Boolean.  Enter the preference name as print.always_print_silent and click OK. Then set the boolean value to true. Restart firefox. This need that the default OS printer must be the desired receipt printer.

### Waterfox Classic
1. Download [Waterfox Classic](https://classic.waterfox.net/#:~:text=Waterfox%20Classic%20is%20a%20legacy,of%20XPCOM%20and%20XUL%20extensions.)

2. Download [JS-Print-Setup](https://web.archive.org/web/20171005165505/https://addons.mozilla.org/en-US/firefox/addon/js-print-setup/) This is a repository of deprecated Firefox add-ons
> <img src="https://user-images.githubusercontent.com/38707933/161368065-eb688413-85ff-48aa-ab82-d606b7a26e10.png" width="300">
> Select download anyway.

3. Install JS-Print-Setup in Waterfox
* In the menu button at top right of screen (three horizontal bars) select _**'Add-ons'**_
> <img src="https://user-images.githubusercontent.com/38707933/161379852-0d3add7e-a861-48bc-9962-405b6b4e6c76.png" width="300">
* In the settings menu (cog/settings icon) select **_'Install Add-on From File...'_**
> <img src="https://user-images.githubusercontent.com/38707933/161379863-d387b19d-8785-4af2-8d7f-7eef622a707e.png" width="300">
* Accept any warnings (if you're comfortable to do so!)
> <img src="https://user-images.githubusercontent.com/38707933/161379874-1d5d3f23-7075-43e6-8a08-0bee9f06fbe6.png" width="300">
* You will now see JS-Print as an installed Add-on
* Restart Waterfox.
> <img src="https://user-images.githubusercontent.com/38707933/161379882-9d1d7825-0835-40fc-9f36-1cb99b47cc56.png" width="300">
4. Check OSPOS > Configuration > Receipt
* Ensure everything from _**'Show Print Dialog'**_ and below can now be edited.
> <img src="https://user-images.githubusercontent.com/38707933/161379886-cef08bee-de12-414d-8a32-f0c3ac371d3a.png" width="300">

5. Select your printers and adjust your margins and **_'Submit'_**

### Chrome

Not recommended, but receipt auto printing is also available in Chrome by using kiosk mode. A printer 
should be selected once after launching Chrome using a custom shortcut.

A new icon application launcher or launch/invoke the binary adding the `--kiosk --kiosk-printing ` option 
to the executable. By the way, to make the kiosk mode effecting you need to close all the chrome 
apps and restart otherwise it doesn't work. Open a Terminal, and write the command: `google-chrome --kiosk --kiosk-printing `

After enabling/configure kiosk, the ospos print settings will be available and can be configured.

Go to Store config and reselect the printers, occasionally had to select a different printer. Submit. 
Then go back and select the correct printer. Then submit.

## Fiscal printing

Some countries need some special communications with internal chip in the printers, this feature are 
only done locally and its not possible unless a specific ad-hoc can be done or with special device that 
receive the raw printing and send from the browser to the printer.

You can ask here for some services by few bucks, adaptations are made easy for some countries. Open an issue [a support ticket Issue (by click here)](https://github.com/opensourcepos/opensourcepos/issues/new) just few words ahead to your solution!

# See also:

* [Getting Started usage](Getting-Started-usage)