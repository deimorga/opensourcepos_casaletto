<?php
/**
 * Emits the page geometry for the receipt, when the business has said what paper it uses.
 *
 * @var array $config
 */

use App\Libraries\Sale_lib;

$ancho = Sale_lib::receipt_printable_width_mm($config['receipt_paper'] ?? '');

// Sin papel declarado no se emite nada, y la impresion se comporta EXACTAMENTE como hasta hoy.
// Es lo que protege a los negocios que ya imprimen bien.
if ($ancho === null) {
    return;
}
?>
<style>
/*
 * Geometria de la tirilla.
 *
 * `size` con altura `auto` es lo que evita que el navegador reserve una hoja
 * entera: la tirilla mide lo que mida la venta.
 *
 * `margin: 0` es el arreglo que de verdad importa. El navegador aplica margenes
 * por defecto de aproximadamente 10 mm por lado, y sobre los <?= $ancho ?> mm
 * imprimibles de este papel eso se come mas de la mitad del ancho util: el
 * recibo sale estrujado en una columna estrecha. El aire lo pone el padding de
 * abajo, que no roba ancho.
 */
@page {
    size: <?= $ancho ?>mm auto;
    margin: 0;
}

@media print {
    html,
    body {
        width: <?= $ancho ?>mm;
        margin: 0;
        padding: 0;
        background: #fff;
    }

    /* Bootstrap centra y acolcha pensando en una pantalla. En 48 mm eso es ancho perdido. */
    .container,
    .container-fluid,
    .row,
    [class^="col-"],
    [class*=" col-"] {
        width: auto !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        float: none !important;
    }

    #receipt_wrapper {
        width: <?= $ancho ?>mm;
        margin: 0;
        padding: 2mm 1.5mm 6mm;
        /*
         * Monoespaciada a proposito: en una tirilla las cifras tienen que
         * alinearse por columna, y una tipografia proporcional descuadra los
         * totales aunque la tabla este bien.
         */
        font-family: "Consolas", "DejaVu Sans Mono", monospace;
        font-size: 8.5pt;
        line-height: 1.25;
        color: #000;
    }

    /* La regla general de OSPOS encoge el recibo al 75%; aqui el tamaño ya esta
       decidido arriba y volver a encogerlo lo dejaria ilegible. */
    #receipt_wrapper,
    #table {
        font-size: 8.5pt;
    }

    #company_name {
        font-size: 11pt;
    }

    #company_name img {
        max-width: <?= $ancho - 6 ?>mm;
        max-height: 20mm;
    }

    #receipt_items {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        margin-top: 2mm;
        margin-bottom: 2mm;
    }

    #receipt_items td,
    #receipt_items th {
        padding: 0.3mm 0;
        /* Un nombre largo parte de linea en vez de ensanchar la tabla y salirse
           del papel, que es como se pierde la columna del total. */
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    #sale_return_policy {
        width: 100%;
        margin: 2mm 0 0;
    }

    /* Cada recibo es su propia tirilla: nada debe arrastrar un salto de pagina. */
    #receipt_wrapper * {
        page-break-inside: auto;
    }
}
</style>
