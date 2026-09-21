<?php
/**
 * Abre el cajon monedero al terminar una venta.
 *
 * Quien decide SI abrirlo es Sale_lib::should_open_cash_drawer(): mira el ajuste del negocio y,
 * cuando dice «solo efectivo», tambien con que se pago. Aqui ya solo llega el si o el no.
 *
 * @var bool $open_cash_drawer
 */

// Sin permiso no se emite nada, y la pagina se comporta EXACTAMENTE como antes. Es lo que protege
// a los negocios que no tienen cajon, a los que venden a domicilio -- donde abrirlo no significa
// nada -- y a la venta que se acaba de cobrar con tarjeta.
if (empty($open_cash_drawer)) {
    return;
}
?>
<script type="text/javascript">
(function () {
    'use strict';

    /*
     * El cajon NO cuelga del computador: va a la impresora por RJ11, y se abre mandandole una
     * secuencia de control (ESC p). Eso NO es imprimir: la impresora ejecuta la orden y no saca
     * papel. Por eso esto es independiente de si el recibo se imprime o no, que era justamente
     * lo que el negocio pedia: llegar al efectivo sin gastar una tirilla por venta.
     *
     * Los bytes exactos NO se deciden aqui: los manda el programa de la caja. La secuencia por
     * omision --ESC p 0 25 250, el ejemplo de la especificacion de Epson-- funciona tal cual en
     * practicamente cualquier termica, asi que no hay nada que configurar por cliente; queda del
     * lado del programa por si alguna vez hay que tocarla, y para no exigir un despliegue del
     * servidor para hacerlo. Aqui solo se pide la accion.
     */
    var url = 'ws://127.0.0.1:7878/ws';

    try {
        var socket = new WebSocket(url);

        socket.onopen = function () {
            socket.send(JSON.stringify({ id: String(Date.now()), op: 'drawer.open' }));
        };

        socket.onmessage = function (evento) {
            var d;
            try { d = JSON.parse(evento.data); } catch (e) { return; }

            // El saludo llega primero; no es la respuesta a nada.
            if (d.op === 'hello') { return; }

            /*
             * Se cierra en cuanto hay respuesta, sea buena o mala. Un socket abierto en la pagina
             * del recibo no aporta nada: la orden ya se dio.
             *
             * Y un fallo NO se le muestra al cajero. La venta ya esta cerrada y el dinero ya se
             * cobro; un aviso rojo aqui solo asustaria por algo que no puede deshacer. Queda en la
             * consola para quien vaya a diagnosticarlo.
             */
            if (d.op === 'error') {
                console.warn('No se pudo abrir el cajon:', d.code, d.message);
            }
            socket.close();
        };

        // Si el programa de la caja no esta corriendo -- o esta es una caja sin cajon -- no pasa
        // nada: el navegador falla al conectar y la pagina sigue su curso.
        socket.onerror = function () {
            console.warn('No hay programa local en esta caja; no se abrio el cajon.');
        };
    } catch (e) {
        console.warn('No se pudo contactar el programa local:', e);
    }
})();
</script>
