
[← Volver a Configuración](Configuration) | [Inicio](Home)

---

La función de impresión depende de la configuración de la impresora y de su comportamiento de impresión.

## Tabla de contenidos

* [Soporte general de impresión](#general-printing-support)
* [Soporte de impresión por dispositivo](#device-printing-support)
* [Soporte de impresión de códigos de barras](#barcode-printing)
* [Cómo imprimir](#how-to-printing)
  * [Preparación de impresión para firefox/palemoon](#preparation-printing-for-firefoxpalemoon)
  * [Preparación de impresión para Chrome/Chromium](#preparing-printing-for-chomechromium)
* [Impresión avanzada y automática](#advanced-and-autoprinting)
  * [Firefox, Palemon, Icecat](#firefox-palemoon-icecat)
  * [Chrome](#chrome)
  * [Impresión fiscal](#fiscal-printing)

## Soporte general de impresión

Actualmente OSPOS puede imprimir en cualquier entorno, se puede usar cualquier impresora instalada. La única limitación es con los KIT's fiscales, debido a que requieren una configuración especial AD-HOC dependiendo de los requisitos de cada país.

## Soporte de impresión por dispositivo

Una impresora podría ser la Star TSP 100 ECO usando los navegadores Firefox o Chrome. Una impresora de etiquetas podría ser la Zebra LP 2824 Plus con etiquetas de 2 x 1.
Otros clientes han tenido éxito con impresoras de otras marcas. Si planeas comprar una impresora de recibos diferente, procura conseguir una que imprima en papel de 72 mm o mayor.

Obviamente la impresión depende del navegador, por lo que primero debe configurarse, y luego en ospos
la configuración de impresión tiene algunas opciones, que solo se habilitan con algunos cambios en el navegador.
Para la Zebra también se recomienda buscar en https://www.peninsula-group.com/mac-thermal-printer-driver/
la interfaz CUPS para sistemas Unix trae soporte básico suficiente que funciona tal cual.

## Impresión de códigos de barras

Esta función no necesita configuraciones especiales, pero la impresora debe estar correctamente configurada. Para instrucciones de impresión, consulta la sección [Guía de uso: Inventario](Getting-Started-usage#3-inventory).

Pero recuerda, como se mencionó anteriormente, que nuestra impresora de etiquetas recomendada es la Zebra LP 2824 Plus con etiquetas de 2 x 1. Otros clientes han tenido éxito con impresoras de otras marcas.


# Cómo imprimir

Tanto para impresión normal como para dispositivos/impresión de recibos, primero se necesita configurar/instalar la impresora en el SO, luego configurar el navegador, y por último ir a **Oficina->ConfiguraciónTienda->Recibo**, donde las opciones más importantes son *Formato* y *Tamaño de fuente*.

![configuración de impresión:](https://user-images.githubusercontent.com/10962177/36354930-c33ea10c-1498-11e8-8b0b-a4eb2b2ecbdb.png)

* **Formato** define la cantidad de información que se imprimirá en el recibo. La opción Reducido puede ayudar con algunas configuraciones de impresión.
* **Tamaño de fuente** define el tamaño de la fuente; debe configurarse con el número de tamaño correcto según el resultado de la impresión.

El recibo **predeterminado muestra más información que el recibo corto (orden)**. Por lo tanto, **para imprimir en impresoras de 78mm y similares, debe usarse "orden" como formato**.

La **impresión automática** se explica más abajo, en la sección específica.

Salvo los márgenes y la opción de mostrar el diálogo de impresión, el resto de las opciones se explican por sí mismas; por favor lee la sección siguiente.

  * [Preparación de impresión para firefox/palemoon](#preparation-printing-for-firefoxpalemoon)
  * [Preparación de impresión para Chome/Chromium](#preparing-printing-for-chomechromium)
* [Impresión avanzada y automática](#advanced-and-autoprinting)
  * [Firefox, Palemon, Icecat](#firefox-palemoon-icecat)
  * [Chrome](#chrome)
  * [Impresión fiscal](#fiscal-printing)


### Preparación de impresión para firefox/palemoon

1. Descarga, configura e instala la impresora en el SO, y confirma que pueda imprimir al menos una carta simple.
2. En firefox, Palemoon, Icecat, haz clic en el botón de menú en la esquina superior derecha de la pantalla (tres barras horizontales)
3. Elige Imprimir
4. Haz clic en Configurar página (asegúrate de que "Imprimir imágenes de fondo" esté desmarcado)
5. Haz clic en Márgenes y encabezado/pie de página
6. Configura todos los márgenes en 0.
7. Configura Encabezados y Pies de página en --En blanco--

### Preparación de impresión para Chome/Chromium

1. Descarga, configura e instala la impresora en el SO, y confirma que pueda imprimir al menos una carta simple.
2. En el botón de menú en la esquina superior derecha de la pantalla (tres barras horizontales) selecciona Imprimir
3. En el cuadro de diálogo que aparece, selecciona el destino de impresora que se acaba de instalar y configurar en el SO
4. En el mismo cuadro de diálogo, haz clic en "Más ajustes" o "configuración avanzada"
5. y selecciona Diseño: Vertical, Papel: 75mm, Márgenes: predeterminado,
6. También en Opciones, desactiva encabezados y desactiva el fondo

## Configuración de impresión recomendada

Recuerda que se recomienda configurar la impresora con papel de 75mm.

1. Ve a la interfaz de impresoras del SO; en MAC/Linux se accede a CUPS en http://localhost:631
2. Selecciona la impresora y luego Propiedades de la impresora
3. Haz clic en la pestaña Configuración del dispositivo u Opciones generales
4. En Asignación de formulario a bandeja o Tamaño de medio, elige 72mm x Recibo
5. En las opciones del cajón de dinero selecciona 1 y 2
6. Elige el medio de 2x1 y la resolución

# Impresión avanzada y automática

La impresión avanzada permite configurar márgenes y la "impresión directa", solamente seleccionando el formato "Orden".

Esta función actualmente está disponible en combinación con los navegadores Palemoon, Icecat, Iceweasel, Firefox, y con algunos pequeños cambios en Chrome. Para la mayoría de los navegadores modernos, otra forma de hacerlo es cambiando configuraciones avanzadas en la interfaz `about:config` de firefox.

  * [Firefox, Palemon, Icecat](#firefox-palemoon-icecat)
  * [Chrome](#chrome)
  * [Impresión fiscal](#fiscal-printing)

### Firefox, Palemoon, Icecat

El soporte de impresión automática, así como la función avanzada, se pueden habilitar de dos maneras: la primera usando el complemento jsPrint en
https://addons.mozilla.org/es/firefox/addon/js-print-setup Los navegadores compatibles deben ser **firefox << 49.9**, **Palemoon >> 24**, e **Icedcat >> 16**. La segunda forma es preconfigurando el navegador para imprimir directamente.

Habilita las configuraciones de jsprint/seamlessprint en Firefox; debe configurarse habilitado para todos los sitios (baja seguridad, lo sentimos). En algunos casos, en versiones más nuevas de firefox se puede probar https://addons.mozilla.org/es/firefox/addon/seamless-print/ pero no en Firefox Quantum.

Después de habilitar/configurar este complemento, la configuración de impresión de ospos estará disponible y podrá configurarse.

Ve a la configuración de la tienda y vuelve a seleccionar las impresoras; ocasionalmente hay que seleccionar una impresora diferente. Envía el formulario.
Luego regresa y selecciona la impresora correcta. Envía el formulario nuevamente.

**Segunda forma alternativa para navegadores más nuevos**: Escribe about:config en la barra de direcciones de Firefox/Palemoon y presiona Enter. Haz clic derecho en cualquier parte de la página y selecciona Nuevo > Booleano. Ingresa el nombre de la preferencia como print.always_print_silent y haz clic en Aceptar. Luego configura el valor booleano en true. Reinicia firefox. Esto requiere que la impresora predeterminada del SO sea la impresora de recibos deseada.

### Chrome

No recomendado, pero la impresión automática de recibos también está disponible en Chrome usando el modo kiosko. Debe seleccionarse una impresora una vez, después de iniciar Chrome usando un acceso directo personalizado.

Un nuevo ícono de lanzador de aplicación, o al lanzar/invocar el binario, agregando la opción `--kiosk --kiosk-printing` al ejecutable. Por cierto, para que el modo kiosko surta efecto necesitas cerrar todas las aplicaciones de chrome y reiniciar, de lo contrario no funciona. Abre una Terminal y escribe el comando: `google-chrome --kiosk --kiosk-printing `

Después de habilitar/configurar el modo kiosko, la configuración de impresión de ospos estará disponible y podrá configurarse.

Ve a la configuración de la tienda y vuelve a seleccionar las impresoras; ocasionalmente hay que seleccionar una impresora diferente. Envía el formulario.
Luego regresa y selecciona la impresora correcta. Envía el formulario nuevamente.

## Impresión fiscal

Algunos países requieren comunicaciones especiales con el chip interno de las impresoras; esta función solo puede realizarse localmente y no es posible a menos que se pueda hacer un desarrollo específico ad-hoc, o con un dispositivo especial que reciba la impresión en bruto y la envíe desde el navegador a la impresora.

Puedes solicitar aquí algunos servicios por unos pocos dólares; las adaptaciones se realizan de forma sencilla para algunos países. Abre un issue [un ticket de soporte (haz clic aquí)](https://github.com/opensourcepos/opensourcepos/issues/new), ¡solo unas palabras y estarás cerca de tu solución!

# Ver también:

* [Guía de uso](Getting-Started-usage)
