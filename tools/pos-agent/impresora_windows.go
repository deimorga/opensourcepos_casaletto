//go:build windows

package main

import (
	"fmt"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Impresion cruda por el spooler de Windows.
//
// Se llama a winspool.drv directamente porque es la unica via que entrega los
// bytes a la impresora SIN que el driver los reinterprete: el tipo de datos
// "RAW" le dice al spooler que no toque nada. Cualquier camino que pase por la
// impresion normal convertiria el ESC/POS en una pagina grafica y el recibo
// saldria como un dibujo, o no saldria.
//
// API consultada:
//   OpenPrinterW / StartDocPrinterW / StartPagePrinter / WritePrinter /
//   EndPagePrinter / EndDocPrinter / ClosePrinter
//   https://learn.microsoft.com/windows/win32/printdocs/printdocs-printing

var (
	winspool             = windows.NewLazySystemDLL("winspool.drv")
	procOpenPrinterW     = winspool.NewProc("OpenPrinterW")
	procClosePrinter     = winspool.NewProc("ClosePrinter")
	procStartDocPrinterW = winspool.NewProc("StartDocPrinterW")
	procEndDocPrinter    = winspool.NewProc("EndDocPrinter")
	procStartPagePrinter = winspool.NewProc("StartPagePrinter")
	procEndPagePrinter   = winspool.NewProc("EndPagePrinter")
	procWritePrinter     = winspool.NewProc("WritePrinter")
)

// docInfo1 es DOC_INFO_1W. Tres punteros, sin relleno en 32 ni en 64 bits.
type docInfo1 struct {
	DocName    *uint16
	OutputFile *uint16
	Datatype   *uint16
}

func abrirImpresora(nombre string) (windows.Handle, error) {
	n, err := windows.UTF16PtrFromString(nombre)
	if err != nil {
		return 0, fmt.Errorf("nombre de impresora inválido: %w", err)
	}
	var h windows.Handle
	// El tercer argumento es PRINTER_DEFAULTS; nulo basta para imprimir.
	r, _, e := procOpenPrinterW.Call(uintptr(unsafe.Pointer(n)), uintptr(unsafe.Pointer(&h)), 0)
	if r == 0 {
		return 0, fmt.Errorf("no se pudo abrir la impresora %q: %w", nombre, e)
	}
	return h, nil
}

// ComprobarImpresora abre y cierra, para que el estado pueda decir si el nombre
// configurado corresponde a una impresora que Windows conoce. Es la diferencia
// entre descubrir el error al montar y descubrirlo con el cliente esperando su
// recibo.
func ComprobarImpresora(nombre string) error {
	h, err := abrirImpresora(nombre)
	if err != nil {
		return err
	}
	procClosePrinter.Call(uintptr(h))
	return nil
}

func enviarAlSpooler(nombre string, datos []byte) error {
	h, err := abrirImpresora(nombre)
	if err != nil {
		return err
	}
	defer procClosePrinter.Call(uintptr(h))

	docName, _ := windows.UTF16PtrFromString("Recibo POS")
	tipo, _ := windows.UTF16PtrFromString("RAW")
	info := docInfo1{DocName: docName, Datatype: tipo}

	r, _, e := procStartDocPrinterW.Call(uintptr(h), 1, uintptr(unsafe.Pointer(&info)))
	if r == 0 {
		return fmt.Errorf("no se pudo iniciar el documento en %q: %w", nombre, e)
	}
	defer procEndDocPrinter.Call(uintptr(h))

	r, _, e = procStartPagePrinter.Call(uintptr(h))
	if r == 0 {
		return fmt.Errorf("no se pudo iniciar la página en %q: %w", nombre, e)
	}
	defer procEndPagePrinter.Call(uintptr(h))

	// WritePrinter puede escribir menos de lo pedido; hay que insistir hasta
	// agotar el buffer. Un recibo truncado es peor que ningun recibo, porque
	// nadie se da cuenta hasta que falta el total.
	escrito := 0
	for escrito < len(datos) {
		var n uint32
		r, _, e = procWritePrinter.Call(
			uintptr(h),
			uintptr(unsafe.Pointer(&datos[escrito])),
			uintptr(len(datos)-escrito),
			uintptr(unsafe.Pointer(&n)),
		)
		if r == 0 {
			return fmt.Errorf("falló la escritura a %q tras %d de %d bytes: %w", nombre, escrito, len(datos), e)
		}
		if n == 0 {
			return fmt.Errorf("la impresora %q dejó de aceptar datos tras %d de %d bytes", nombre, escrito, len(datos))
		}
		escrito += int(n)
	}
	return nil
}
