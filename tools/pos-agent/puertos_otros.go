//go:build !windows

package main

import "errors"

// Fuera de Windows no se enumeran puertos: el enumerador de la libreria usa
// cgo en macOS, y el agente solo corre en la caja. Existe para que el resto
// compile y se pueda probar en el equipo de desarrollo.

func BuscarBascula() (string, error) {
	return "", errors.New("la búsqueda automática del puerto solo está implementada en Windows")
}

func ResumenPuertos() string { return "(enumeración de puertos solo disponible en Windows)" }

// PuertoPresente no puede responder fuera de Windows, y decir "si" es lo
// prudente: la alternativa seria cerrar un puerto que funciona.
func PuertoPresente(string) bool { return true }
