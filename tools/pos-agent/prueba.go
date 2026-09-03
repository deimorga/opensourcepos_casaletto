package main

import (
	"fmt"
	"strings"
)

// Recibo de prueba y apertura de cajon desde la linea de ordenes.
//
// Existe porque el camino que importa --bytes crudos por el spooler-- no se
// puede ensayar desde el navegador ni con curl, y el dia del montaje hace falta
// responder una pregunta concreta: "¿esta impresora, con este nombre y este
// puerto, saca papel?". Sin esto, la primera vez que se prueba la impresion es
// con una venta real de por medio.
//
// No abre ningun camino nuevo: usa exactamente el mismo codigo que atiende a
// printer.raw, y para invocarlo hay que estar en la maquina.

// anchoTirilla son los caracteres por linea de una tirilla de 58 mm con la
// fuente A. Pasarse no da error: la impresora parte la linea donde le toque.
const anchoTirilla = 32

// Ordenes ESC/POS. Se dejan con nombre porque un byte suelto en medio del
// codigo no se puede leer.
var (
	escInicializar = []byte{0x1B, 0x40}       // ESC @
	escCentrar     = []byte{0x1B, 0x61, 0x01} // ESC a 1
	escIzquierda   = []byte{0x1B, 0x61, 0x00} // ESC a 0
	escNegritaOn   = []byte{0x1B, 0x45, 0x01} // ESC E 1
	escNegritaOff  = []byte{0x1B, 0x45, 0x00} // ESC E 0
	escAvanzar     = []byte{0x1B, 0x64, 0x04} // ESC d 4: cuatro lineas
)

// reciboDePrueba arma una tirilla que responde a simple vista si la impresora
// esta bien conectada Y bien configurada.
//
// Va SIN TILDES a proposito: la tabla de caracteres por omision de estas
// impresoras no es UTF-8, y un acento sale como un simbolo raro. Es el mismo
// motivo por el que la bitacora no las lleva.
func reciboDePrueba(version, impresora string) []byte {
	var b []byte
	b = append(b, escInicializar...)
	b = append(b, escCentrar...)
	b = append(b, escNegritaOn...)
	b = append(b, []byte("PRUEBA DE IMPRESION\n")...)
	b = append(b, escNegritaOff...)
	b = append(b, []byte("pos-agent "+version+"\n")...)
	b = append(b, escIzquierda...)
	b = append(b, []byte(strings.Repeat("-", anchoTirilla)+"\n")...)
	b = append(b, []byte("Impresora: "+impresora+"\n")...)
	b = append(b, []byte("Si lee esto, la impresion cruda\n")...)
	b = append(b, []byte("funciona: el recibo no pasa por\n")...)
	b = append(b, []byte("el dialogo del navegador.\n")...)
	b = append(b, []byte(strings.Repeat("-", anchoTirilla)+"\n")...)
	b = append(b, escCentrar...)
	b = append(b, []byte("0,735 kg    $ 19.110\n")...)
	b = append(b, escIzquierda...)
	b = append(b, escAvanzar...)
	return b
}

// probarImpresion imprime el recibo de prueba y cuenta que paso.
func probarImpresion(cfg Config) int {
	impr := NuevaImpresora(cfg.Impresora, nil)
	if !impr.Configurada() {
		fmt.Println("No hay impresora configurada.")
		fmt.Printf("Escriba el nombre exacto de la impresora en \"impresora\" > \"nombre\" dentro de %s\n", nombreArchivoConfig)
		fmt.Println("El nombre es el que aparece en Dispositivos e impresoras, tal cual.")
		return 1
	}

	fmt.Printf("Enviando el recibo de prueba a %q...\n", cfg.Impresora.Nombre)
	if err := impr.Imprimir(reciboDePrueba(version, cfg.Impresora.Nombre)); err != nil {
		fmt.Printf("FALLO: %v\n", err)
		return 1
	}
	fmt.Println("Enviado. Si no sale papel, el nombre es correcto pero el problema")
	fmt.Println("esta en el puerto de la cola o en el cable.")
	return 0
}

// probarCajon manda la secuencia de apertura.
func probarCajon(cfg Config) int {
	impr := NuevaImpresora(cfg.Impresora, nil)
	if !impr.Configurada() {
		fmt.Println("No hay impresora configurada, y el cajon cuelga de la impresora.")
		return 1
	}
	fmt.Printf("Abriendo el cajon por %q (secuencia %v)...\n", cfg.Impresora.Nombre, cfg.Impresora.AbrirCajon)
	if err := impr.AbrirCajon(); err != nil {
		fmt.Printf("FALLO: %v\n", err)
		return 1
	}
	fmt.Println("Enviado. Si el cajon no abre pero la impresora si imprime,")
	fmt.Println("revise el cable RJ11 y la secuencia \"abrir_cajon\" de la configuracion.")
	return 0
}
