
[← Volver a Configuración](Configuration) | [Inicio](Home)

---

## Tabla de contenidos

* [Preguntas frecuentes](#faqs)
* [Soporte general de impresión](#general-printing-support)
* [Soporte de impresión por dispositivo](#device-printing-support)
* [Soporte de impresión de códigos de barras](#barcode-printing)
* [Cómo imprimir](#how-to-printing)
  * [Preparación de impresión para firefox/palemoon](#preparation-printing-for-firefoxpalemoon)
  * [Preparación de impresión para Chrome/Chromium](#preparing-printing-for-chomechromium)
* [Impresión avanzada y automática](#advanced-and-autoprinting)
  * [Firefox, Palemon, Icecat](#firefox-palemoon-icecat)
  * [Waterfox Classic](#waterfox-classic)
  * [Chrome](#chrome)
  * [Impresión fiscal](#fiscal-printing)

## Preguntas frecuentes
* ¿Por qué no puedo ajustar la configuración de impresión en OSPOS > configuración > recibo?
> <img src="https://user-images.githubusercontent.com/38707933/161402193-4201e802-d995-4abd-ad65-f1703daf7b0d.png" width="200">

> Esta configuración solo está disponible si ya tienes configurada la [Impresión avanzada y automática](#advanced-and-autoprinting). La impresión automática evita que aparezca el cuadro de diálogo de impresión, permitiendo que OSPOS imprima recibos sin que tengas que confirmarlo al final de una venta.

## Soporte general de impresión

Actualmente OSPOS puede imprimir en cualquier entorno, se puede usar cualquier impresora instalada. La única limitación son los KIT's fiscales, ya que requieren una configuración especial AD-HOC según los requisitos de cada país.

## Soporte de impresión por dispositivo

La impresora de recibos recomendada es la Star TSP 100 ECO usando los navegadores Firefox o Chrome; con esto, si no hay un kit fiscal, se puede imprimir directamente. Nuestra impresora de etiquetas recomendada es la Zebra LP 2824 Plus con etiquetas de 2 x 1.
Otros clientes han tenido éxito con impresoras de otras marcas. Si planeas comprar una impresora de recibos distinta a la recomendada, procura conseguir una que imprima en papel de 72 mm o mayor.

Obviamente la impresión depende del navegador, por lo que primero debe configurarse ahí, y luego en ospos
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
  * [Waterfox Classic](#waterfox-classic)
  * [Chrome](#chrome)
  * [Impresión fiscal](#fiscal-printing)


### Preparación de impresión para firefox/palemoon/waterfox classic

1. Descarga, configura e instala la impresora en el SO, y confirma que pueda imprimir al menos una carta simple.
2. En el navegador, haz clic en el botón de menú en la esquina superior derecha de la pantalla (tres barras horizontales)
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

Esta función actualmente está disponible en combinación con los navegadores Palemoon, Icecat, Iceweasel, Waterfox, Firefox, y con algunos pequeños cambios en Chrome. Para la mayoría de los navegadores modernos, otra forma de hacerlo es cambiando configuraciones avanzadas en la interfaz `about:config` de firefox.

  * [Firefox, Palemon, Icecat](#firefox-palemoon-icecat)
  * [Waterfox Classic](#waterfox-classic)
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

### Waterfox Classic
1. Descarga [Waterfox Classic](https://classic.waterfox.net/#:~:text=Waterfox%20Classic%20is%20a%20legacy,of%20XPCOM%20and%20XUL%20extensions.)

2. Descarga [JS-Print-Setup](https://web.archive.org/web/20171005165505/https://addons.mozilla.org/en-US/firefox/addon/js-print-setup/) Este es un repositorio de complementos obsoletos de Firefox
> <img src="https://user-images.githubusercontent.com/38707933/161368065-eb688413-85ff-48aa-ab82-d606b7a26e10.png" width="300">
> Selecciona descargar de todos modos.

3. Instala JS-Print-Setup en Waterfox
* En el botón de menú en la esquina superior derecha de la pantalla (tres barras horizontales) selecciona _**'Complementos'**_
> <img src="https://user-images.githubusercontent.com/38707933/161379852-0d3add7e-a861-48bc-9962-405b6b4e6c76.png" width="300">
* En el menú de configuración (ícono de engranaje/ajustes) selecciona **_'Instalar complemento desde archivo...'_**
> <img src="https://user-images.githubusercontent.com/38707933/161379863-d387b19d-8785-4af2-8d7f-7eef622a707e.png" width="300">
* Acepta cualquier advertencia (¡si te sientes cómodo haciéndolo!)
> <img src="https://user-images.githubusercontent.com/38707933/161379874-1d5d3f23-7075-43e6-8a08-0bee9f06fbe6.png" width="300">
* Ahora verás JS-Print como un complemento instalado
* Reinicia Waterfox.
> <img src="https://user-images.githubusercontent.com/38707933/161379882-9d1d7825-0835-40fc-9f36-1cb99b47cc56.png" width="300">
4. Verifica OSPOS > Configuración > Recibo
* Asegúrate de que todo, desde _**'Mostrar diálogo de impresión'**_ hacia abajo, ahora se pueda editar.
> <img src="https://user-images.githubusercontent.com/38707933/161379886-cef08bee-de12-414d-8a32-f0c3ac371d3a.png" width="300">

5. Selecciona tus impresoras, ajusta los márgenes y haz clic en **_'Enviar'_**

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
