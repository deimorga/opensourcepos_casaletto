//go:build !windows

package main

import "errors"

// Fuera de Windows no hay spooler al que hablarle. Existe para que el agente
// compile y se pueda probar en el equipo de desarrollo (macOS): todo lo demas
// --bascula, servidor, contrato-- es identico, y solo esta funcion cambia.
func enviarAlSpooler(nombre string, datos []byte) error {
	return errors.New("la impresión cruda solo está implementada en Windows")
}

// ComprobarImpresora existe con la misma firma por la misma razon.
func ComprobarImpresora(nombre string) error {
	return errors.New("la impresión cruda solo está implementada en Windows")
}
