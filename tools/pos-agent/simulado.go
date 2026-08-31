package main

import (
	"fmt"
	"io"
	"sync"
	"time"
)

// Bascula simulada.
//
// No es un juguete: es lo que permite comprobar la cadena completa --pagina,
// agente, puerto, interprete, carrito-- sin tener la bascula delante. El dia
// del montaje solo hay cinco minutos con el equipo fisico, y llegar a ese
// momento sin saber si el resto funciona seria desperdiciarlos.
//
// La trama que emite es plausible pero INVENTADA: el formato real de esta
// bascula sigue sin conocerse (el fabricante del firmware cerro el soporte).
// Sirve para probar el transporte, nunca para deducir el patron.
type puertoSimulado struct {
	mu        sync.Mutex
	pendiente []byte
	cerrado   bool
	pesos     []string
	i         int
	ultimo    time.Time
	espera    time.Duration
}

func abrirSimulado(cfg ConfigBascula) (puertoSerie, error) {
	return &puertoSimulado{
		pesos:  []string{"0.735", "0.740", "1.250", "0.085"},
		espera: 500 * time.Millisecond,
	}, nil
}

func (p *puertoSimulado) Read(b []byte) (int, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.cerrado {
		return 0, io.EOF
	}
	if len(p.pendiente) == 0 {
		if time.Since(p.ultimo) < p.espera {
			// Devolver (0, nil) es exactamente lo que hace un puerto real de
			// Windows cuando vence el tiempo de espera. Se imita a proposito:
			// asi el bucle de lectura se prueba contra el comportamiento que
			// va a encontrar, y no contra uno mas comodo.
			return 0, nil
		}
		p.ultimo = time.Now()
		p.pendiente = []byte(fmt.Sprintf("ST,GS,+ %s kg\r\n", p.pesos[p.i%len(p.pesos)]))
		p.i++
	}
	n := copy(b, p.pendiente)
	p.pendiente = p.pendiente[n:]
	return n, nil
}

// Write acepta el comando de peso y responde de inmediato, para poder probar
// tambien las basculas que solo hablan cuando se les pregunta.
func (p *puertoSimulado) Write(b []byte) (int, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.cerrado {
		return 0, io.ErrClosedPipe
	}
	p.ultimo = time.Time{}
	return len(b), nil
}

func (p *puertoSimulado) SetReadTimeout(time.Duration) error { return nil }

func (p *puertoSimulado) Close() error {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.cerrado = true
	return nil
}
