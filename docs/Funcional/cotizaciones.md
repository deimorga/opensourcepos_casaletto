# Cotizaciones

> **Estado (2026-09-24):** revisadas y ajustadas. Hasta ese día **nadie había hecho una cotización**
> en ningún negocio: todas las ventas eran ventas normales. Al probarlas aparecieron varios defectos,
> ya corregidos (§4). Falta una sola cosa que no depende del programa: **el envío por correo** (§5).
>
> La wiki de OSPOS copiada en `referencia-ospos-wiki/Sales.md` dice que las cotizaciones «todavía no
> son compatibles». Eso ya no es cierto en esta versión; este documento describe lo que hay hoy.
>
> Documento hermano: `docs/Tecnico/cotizaciones.md`.

---

## 1. Para qué sirve

Una cotización es un documento con precios para un cliente que **todavía no compra**: no cobra, no
descuenta inventario y no cuenta como venta. Si el cliente acepta, la misma cotización se convierte en
venta o en factura.

## 2. Cómo se hace

1. En la caja, en **Registrar Modo**, elegir **«Cotizar»**. (Aparece mientras la facturación esté
   encendida en Configuración → Facturación.)
2. Agregar los artículos como en cualquier venta.
3. **Elegir el cliente.** Sin cliente no aparece el botón: una cotización es para alguien.
4. **No registrar pagos.** Si se registró alguno, la caja no deja cotizar y lo dice: *«Una cotización
   no lleva pagos: quite los pagos y vuelva a cotizar.»*
5. Pulsar **«Cotizar»**. Sale el documento.

## 3. El documento de cotización

Se titula **«Cotización»** y lleva:

- los datos del negocio (nombre, dirección, teléfono, logo) y los del cliente;
- el **número de cotización** (por ejemplo `Q26000002`: Q, año y consecutivo);
- la fecha y la **vigencia**: *«Válida hasta …»*;
- los artículos con cantidad, precio, descuento y total;
- el subtotal, los impuestos si los hay, y el **total cotizado**;
- el comentario de las cotizaciones, si el negocio escribió uno.

Botones del documento:

| Botón | Qué hace |
|---|---|
| **Imprimir** | Lo imprime |
| **Descargar PDF** | Baja el documento en PDF, para enviarlo por WhatsApp o guardarlo |
| **Enviar cotización** | Lo manda por correo al cliente, si tiene correo. **Hoy no funciona:** ver §5 |
| **Registro de ventas** | Vuelve a la caja |
| **Descartar** | Anula esta cotización |

**Volver a verla o imprimirla después:** en la caja, **Suspendidas** → **«Ver cotización»**. Antes, al
salir del documento no había forma de volver a verlo.

**Convertirla en venta:** en **Suspendidas** → **«Retomar»**. La cotización vuelve a la caja; se cambia
el modo a *Recibo de venta* o *Factura* y se cobra como siempre. En ese momento sí descuenta inventario.

## 4. Lo que se corrigió el 2026-09-24

| Antes | Ahora |
|---|---|
| El documento se titulaba «Cotizar» (un verbo), el número decía «Número de presupuesto» y el total «Total Facturado» | «Cotización», «Número de cotización», «Total cotizado» |
| Imprimía «This is a default quote comment», en inglés | Sin comentario, hasta que el negocio escriba el suyo. Lo mismo el comentario en inglés de las **facturas** («This is a default comment»), que ya había salido impreso en las facturas de Casaletto, y el mensaje en inglés del correo |
| No decía hasta cuándo valía | «Válida hasta», configurable (15 días por defecto) |
| Al salir del documento no se podía volver a imprimir | «Ver cotización» en Suspendidas |
| No había PDF para descargar | «Descargar PDF» |
| Un pago registrado antes de cotizar se guardaba, y descontaba tarjetas de regalo y puntos | La caja no deja cotizar con pagos |
| Si el navegador de quien cotizaba no estaba en español, el número podía salir con dígitos árabes (`Q٢٦000001`) y quedaba así para siempre | El número usa siempre el idioma del negocio |

Además, un arreglo de la caja que apareció al probar: **teclear o escanear el código de un combo**
(por ejemplo `C20013`) daba error. Ahora se agrega igual que eligiéndolo de la búsqueda.

## 5. Lo que falta: el envío por correo

El botón **«Enviar cotización»** necesita que el sistema tenga una cuenta de correo desde la cual
enviar. Hoy no la tiene: está configurado para usar un servicio de correo que el servidor no trae.
Para activarlo hace falta **una cuenta de correo SMTP** (servidor, usuario y contraseña) que el negocio
o Micronuba decida usar. Mientras tanto, **«Descargar PDF»** cubre la necesidad.

## 6. Cómo se configura

En **Configuración → Facturación** (visible con la facturación encendida):

| Ajuste | Para qué |
|---|---|
| **Formato del número de cotización** | Cómo se arma el número. Por defecto `Q%y{QSEQ:6}` |
| **Último número de cotización usado** | El consecutivo |
| **Comentario de las cotizaciones** | Texto al pie de cada cotización (condiciones, forma de pago…) |
| **Vigencia de la cotización (días)** | Cuántos días vale. Con 0 no se imprime la vigencia |

La fecha sale en el formato de fecha del negocio (Configuración → Local). Hoy es mes/día/año; cambiarlo
cambia todas las pantallas y documentos, así que es una decisión del negocio y no de las cotizaciones.
