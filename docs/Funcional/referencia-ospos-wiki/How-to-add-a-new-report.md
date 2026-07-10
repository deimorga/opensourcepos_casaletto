
[← Back to Developer Docs](Development-Index) | [Home](Home)

---

The application uses the Model-View-Controller paradigm. Therefore you need to work on the three parts in order to create a new report.

Model -> application\models\reports\yourreportmodelname.php
Controller -> application\controllers\reports.php (add your controller entry to the list)
View -> application\views\reports (if you mimic one listing or graphical that already exist you should not care too much about this)

You should check this file too: application\helpers\table_helper.php because deals with the tabular view of certain reports in particular the row format.

And then make sure your new Report is added to application\config\routes.php

The best would be to start looking into one similar report to understand how it's structured and copy and paste it renaming to your new report. Once renamed you can play with query part and etc.

If you add new text strings then you need to deal with the translations, but one step at time.

If you let say just want to add Location as parameter picked up from a list before showing an existing report, you need to select a different selector view that is mentioned in the routes.php.

It takes a bit of time to get your mind around all the logic, so play a bit, fail and learn :-)