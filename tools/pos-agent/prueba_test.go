package main

import (
	"bytes"
	"testing"
)

func TestElReciboDePruebaEmpiezaInicializando(t *testing.T) {
	// Sin ESC @ la impresora hereda el estado del trabajo anterior --negrita,
	// alineacion, tabla de caracteres-- y el recibo sale distinto segun lo que
	// se haya impreso antes.
	r := reciboDePrueba("1.0.0", "POS-58-Series")
	if !bytes.HasPrefix(r, escInicializar) {
		t.Errorf("el recibo empieza con % x", r[:4])
	}
}

func TestElReciboDePruebaNoLlevaTildes(t *testing.T) {
	// La tabla de caracteres por omision de estas impresoras no es UTF-8: una
	// tilde sale como un simbolo raro, y el recibo de prueba dejaria de servir
	// para lo unico que sirve, que es leerlo de un vistazo.
	for i, b := range reciboDePrueba("1.0.0", "POS-58-Series") {
		if b > 0x7F {
			t.Fatalf("byte no ASCII (0x%02x) en la posición %d", b, i)
		}
	}
}

func TestElReciboDePruebaDiceQueImpresoraUso(t *testing.T) {
	// Con dos impresoras instaladas --y aqui hubo dos colas-- saber cual salio
	// en el papel es la diferencia entre diagnosticar y adivinar.
	r := reciboDePrueba("1.0.0", "LA-DE-LA-CAJA")
	if !bytes.Contains(r, []byte("LA-DE-LA-CAJA")) {
		t.Error("el recibo no dice a qué impresora se envió")
	}
}
