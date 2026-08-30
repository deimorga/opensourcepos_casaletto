//go:build windows

package main

import "golang.org/x/sys/windows"

// codePageUTF8 es CP_UTF8. Fuente:
// https://learn.microsoft.com/en-us/windows/win32/intl/code-page-identifiers
const codePageUTF8 = 65001

// enableUTF8Console pone la consola en UTF-8 para que los acentos del español
// se vean bien.
//
// Sin esto, una consola en su página de códigos heredada (850 o 437) muestra
// "báscula" como "bÃ¡scula", y el operario —que no es técnico— concluye que el
// programa está roto. Si la llamada falla se devuelve false y el programa
// transcribe los mensajes a ASCII, que es feo pero legible.
//
// SetConsoleOutputCP: https://learn.microsoft.com/en-us/windows/console/setconsoleoutputcp
func enableUTF8Console() bool {
	if err := windows.SetConsoleOutputCP(codePageUTF8); err != nil {
		return false
	}
	// La de entrada es secundaria (solo se leen ENTER); si falla, no importa.
	_ = windows.SetConsoleCP(codePageUTF8)
	return true
}
