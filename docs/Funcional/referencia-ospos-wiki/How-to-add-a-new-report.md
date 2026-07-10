
[← Volver a Documentación para Desarrolladores](Development-Index) | [Inicio](Home)

---

La aplicación utiliza el paradigma Modelo-Vista-Controlador. Por lo tanto, necesitas trabajar en las tres partes para crear un nuevo reporte.

Modelo -> application\models\reports\yourreportmodelname.php
Controlador -> application\controllers\reports.php (agrega la entrada de tu controlador a la lista)
Vista -> application\views\reports (si estás imitando un listado o gráfico que ya existe, no deberías preocuparte mucho por esto)

También deberías revisar este archivo: application\helpers\table_helper.php porque se encarga de la vista tabular de ciertos reportes, en particular el formato de fila.

Y luego asegúrate de que tu nuevo Reporte esté agregado en application\config\routes.php

Lo mejor sería empezar mirando un reporte similar para entender cómo está estructurado, copiarlo y pegarlo renombrándolo a tu nuevo reporte. Una vez renombrado puedes trabajar con la parte de la consulta (query), etc.

Si agregas nuevas cadenas de texto, entonces necesitas ocuparte de las traducciones, pero un paso a la vez.

Si digamos que solo quieres agregar Ubicación como parámetro seleccionable de una lista antes de mostrar un reporte existente, necesitas seleccionar una vista de selector diferente que esté mencionada en routes.php.

Toma un poco de tiempo entender toda la lógica, así que juega un poco, falla y aprende :-)