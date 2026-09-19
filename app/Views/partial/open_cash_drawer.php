<?php
/**
 * Abre el cajon monetero al terminar una venta.
 *
 * @var bool $open_cash_drawer
 */

// Sin el ajuste encendido no se emite nada, y la pagina se comporta EXACTAMENTE como antes. Es lo
// que protege a los negocios que no tienen cajon -- o que venden a domicilio, donde abrirlo no
// significa nada.
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
     * Los bytes exactos NO se deciden aqui. Los decide el programa de la caja desde su propia
     * configuracion, porque cada cajon tiene su gusto y cambiarlos no puede exigir tocar el
     * servidor. Aqui solo se pide la accion.
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
