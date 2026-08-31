package main

import (
	"errors"
	"testing"
)

func TestElCajonEsUnaSecuenciaImpresa(t *testing.T) {
	// El cajón no cuelga del PC: va a la impresora por RJ11. Abrirlo es
	// imprimir unos bytes de control, y por eso sin impresora no hay cajón.
	var enviado []byte
	i := NuevaImpresora(ConfigImpr{Nombre: "TIRILLA", AbrirCajon: []int{27, 112, 0, 25, 250}},
		func(_ string, datos []byte) error { enviado = datos; return nil })

	if err := i.AbrirCajon(); err != nil {
		t.Fatal(err)
	}
	if string(enviado) != "\x1bp\x00\x19\xfa" {
		t.Errorf("secuencia = % x", enviado)
	}
}

func TestUnaSecuenciaImposibleNoSeManda(t *testing.T) {
	i := NuevaImpresora(ConfigImpr{Nombre: "TIRILLA", AbrirCajon: []int{27, 999}},
		func(string, []byte) error { t.Fatal("no debió llegar a la impresora"); return nil })
	if err := i.AbrirCajon(); err == nil {
		t.Fatal("un valor fuera de rango tiene que rechazarse")
	}
}

func TestSinImpresoraSeDiceQueNoLaHay(t *testing.T) {
	i := NuevaImpresora(ConfigImpr{}, func(string, []byte) error { return nil })
	if err := i.Imprimir([]byte("hola")); !errors.Is(err, ErrSinImpresora) {
		t.Errorf("err = %v", err)
	}
	if err := i.AbrirCajon(); !errors.Is(err, ErrSinImpresora) {
		t.Errorf("err = %v", err)
	}
}
