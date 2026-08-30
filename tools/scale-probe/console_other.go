//go:build !windows

package main

// En Linux y macOS la terminal ya es UTF-8; no hay nada que ajustar.
// Existe solo para que el programa compile y se pueda ensayar fuera de Windows.
func enableUTF8Console() bool { return true }
