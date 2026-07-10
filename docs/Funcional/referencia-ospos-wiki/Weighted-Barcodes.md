
[← Volver a Configuración](Configuration) | [Inicio](Home)

---

La aplicación se puede usar para negocios basados en **artículos pesados**, como mercados de lácteos, tiendas de abarrotes, tostadurías y mercados de frutas/verduras.

## Cómo usar básculas como dispositivo de entrada

La idea es que configure la báscula para que muestre las variables en un formato de cadena fija. La mayoría de las básculas son dispositivos USB comunes que se pueden configurar para enviar pulsaciones de teclas como si fueran un teclado. Una cosa que necesita hacer es configurar el formato en OSPOS para que sepa qué dato va en cada lugar.

### Dónde configurar

Esta funcionalidad le permite usar un formato de código de barras específico para interpretar el código de barras, configurado en el panel de configuración como se muestra a continuación:

![Campo de entrada](http://ospos.wshells.org/Wiki/Input.png)

Como puede ver, el campo de entrada debe completarse con el formato de código de barras que está imprimiendo con la báscula.

A continuación se muestra un ejemplo completado:

![Campo completado](http://ospos.wshells.org/Wiki/Filled.jpg)

### El formato antes de la versión 3.3.2

```02(\d{5})(\w{6})```

* Los primeros 2 caracteres representan el código de departamento
* Los siguientes 5 dígitos representan el peso del artículo
* Los últimos 6 caracteres representan el código de barras del artículo

### El formato en la versión 3.3.2 y posteriores

El formato sigue el mismo formato de tokens que se usa también en ([facturas, cotizaciones y órdenes de trabajo](https://github.com/opensourcepos/opensourcepos/pull/2797)).

```02{W:5}{I:6}```

* Los primeros 2 caracteres representan el código de departamento
* Los siguientes 5 dígitos representan el peso del artículo
* Los últimos 6 caracteres representan el código de barras del artículo
* También se puede usar el precio como {P:3} como última variable

Configure los **decimales de cantidad** en 2 para que el sistema interprete correctamente las cantidades.
Para más información, [estamos a un clic de distancia](https://github.com/opensourcepos/opensourcepos/issues/new).

### Explicación adicional/en profundidad

Los códigos de barras con datos incorporados, como peso, precio o ID de artículo, se pueden interpretar con un código regex (esto es más simple de lo que suena).

Supongamos que su código de barras es un código de barras EAN13 (en teoría, esto debería funcionar con cualquier tipo de código de barras), y su código de barras se ve así:

2000021019409

podemos dividir este código de barras en los siguientes segmentos:

* 20 (código de empresa o país)
* 0002 (ID del artículo)
* 1 (dígito aleatorio para la unicidad del código de barras)
* 01940 (precio)
* 9 (dígito de verificación/checksum)

para escribir el código regex para este código de barras (asumiendo que todos los demás códigos de barras se generan de la misma manera) necesitaría usar lo siguiente:
* 20{I:4}\d{P:5}\d 
* (vale la pena señalar que el último "\d" se puede omitir)

entendamos los componentes de esto, y veamos cuáles son nuestras opciones:
* 20 es el código de empresa, como se mencionó anteriormente. Este es un componente que no cambia.
* {:} indica que lo que esté dentro de estas llaves especifica un rango de datos que coincide con lo que está entre los corchetes.
* I = ID del artículo, y siempre separará lo que esté dentro del rango especificado como el ID
* W = peso/cantidad del artículo especificado. Este valor se divide por 1000 de manera predeterminada.
* P = precio. Por defecto, el precio no considera los centavos.
* \d = marcador de posición de dígito. Este dígito se ignorará de manera predeterminada.

para más información sobre códigos regex, consulte este enlace: https://www.w3schools.com/php/php_regex.asp


el código regex que construya debe colocarse en el campo "Formatos de código de barras", que se encuentra en Configuración > Código de barras

el ID del artículo para el artículo que está escaneando debe colocarse en el segmento "Código de barras" del artículo que desea agregar al escanear.

En caso de que el precio esté incorporado en el código de barras, asegúrese de marcar el botón de opción "ingreso de monto" en la pantalla de configuración del artículo.


ADVERTENCIA: La siguiente sección solo se recomienda si tiene conocimientos técnicos avanzados

si desea acomodar centavos en el precio de sus códigos de barras, deberá editar el siguiente archivo en consecuencia:

* Archivo: Application/libraries/Token_lib.php
* busque la línea número 94
* edite esta línea agregando " / 100" después de "(double) $parsed_results['P']"
