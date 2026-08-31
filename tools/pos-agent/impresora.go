package main

import (
	"errors"
	"fmt"
	"strings"
	"sync"
)

// ErrSinImpresora es la condicion normal de una caja a la que todavia no se le
// conecto la impresora. Se distingue de un fallo para que la pagina pueda
// decir "esta caja no imprime" en vez de "error".
var ErrSinImpresora = errors.New("no hay impresora configurada")

// Impresora manda bytes crudos al spooler de Windows.
//
// Crudos de verdad: el recibo se arma en ESC/POS y se entrega tal cual, sin
// pasar por el dialogo de impresion del navegador ni por la maquetacion de
// nadie. Ese dialogo es exactamente lo que este agente existe para eliminar.
type Impresora struct {
	cfg    ConfigImpr
	enviar func(nombre string, datos []byte) error

	// Un solo trabajo a la vez. Dos pestanas imprimiendo al tiempo
	// entrelazarian los bytes de dos recibos en uno solo, y el resultado no
	// se parece a un error: se parece a un recibo con el total de otra venta.
	mu sync.Mutex
}

func NuevaImpresora(cfg ConfigImpr, enviar func(string, []byte) error) *Impresora {
	if enviar == nil {
		enviar = enviarAlSpooler
	}
	return &Impresora{cfg: cfg, enviar: enviar}
}

func (i *Impresora) Configurada() bool { return strings.TrimSpace(i.cfg.Nombre) != "" }

// Imprimir manda el recibo ya compuesto.
func (i *Impresora) Imprimir(datos []byte) error {
	if !i.Configurada() {
		return ErrSinImpresora
	}
	if len(datos) == 0 {
		return errors.New("no hay nada que imprimir")
	}
	i.mu.Lock()
	defer i.mu.Unlock()
	return i.enviar(i.cfg.Nombre, datos)
}

// AbrirCajon manda la secuencia de control del cajon monetero.
//
// El cajon NO cuelga del PC: va a la impresora por RJ11, asi que abrirlo es
// imprimir. Por eso vive aqui y no en un modulo propio, y por eso sin impresora
// configurada tampoco hay cajon.
func (i *Impresora) AbrirCajon() error {
	if !i.Configurada() {
		return ErrSinImpresora
	}
	secuencia := make([]byte, 0, len(i.cfg.AbrirCajon))
	for _, b := range i.cfg.AbrirCajon {
		if b < 0 || b > 255 {
			return fmt.Errorf("la secuencia de apertura del cajón tiene un valor fuera de rango: %d", b)
		}
		secuencia = append(secuencia, byte(b))
	}
	if len(secuencia) == 0 {
		return errors.New("la secuencia de apertura del cajón está vacía")
	}
	i.mu.Lock()
	defer i.mu.Unlock()
	return i.enviar(i.cfg.Nombre, secuencia)
}
