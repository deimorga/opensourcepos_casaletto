package main

import (
	"fmt"
	"sync"
	"time"
)

// Puerto simulado. Existe por dos razones concretas:
//
//  1. Permite ensayar la herramienta COMPLETA antes del dia de la captura, sin
//     bascula. Quien la va a ejecutar puede practicar, y quien la escribio
//     puede ver el archivo de resultados real.
//  2. Permite probar el flujo entero en la maquina de desarrollo, que no tiene
//     puerto COM.
//
// Imita el formato 3 (continuo) del manual hermano ACS-268: trama
// `N12.395<SP><SP><LF><CR>` (§5.10b del diseno tecnico), y ademas responde a
// `W` como haria el formato 9. Solo lo hace en 9600 8-N-1; en cualquier otra
// configuracion se queda mudo, que es como se comportaria el equipo real.

type fakePort struct {
	mu       sync.Mutex
	cfg      PortConfig
	timeout  time.Duration
	pending  []byte
	closed   bool
	weight   float64
	lastEmit time.Time
	// continuous simula que la bascula transmite sola.
	continuous bool
}

func newFakeOpener(continuous bool, weight *float64) opener {
	return func(name string, cfg PortConfig) (portHandle, error) {
		if name == "" {
			return nil, fmt.Errorf("nombre de puerto vacío")
		}
		return &fakePort{cfg: cfg, weight: *weight, continuous: continuous, timeout: 200 * time.Millisecond}, nil
	}
}

// speaks indica si el equipo simulado habla en esta configuracion.
func (f *fakePort) speaks() bool {
	return f.cfg.Baud == 9600 && f.cfg.DataBits == 8 && f.cfg.Parity == ParityNone && f.cfg.StopBits == 1
}

// frame arma la trama documentada para el formato continuo Moresco.
func frame(kg float64) []byte {
	// Dos digitos de kilos, punto, tres de gramos, dos espacios, LF, CR.
	s := fmt.Sprintf("N%05.3f  ", kg)
	if kg < 10 {
		s = fmt.Sprintf("N0%04.3f  ", kg)
	}
	return append([]byte(s), 0x0a, 0x0d)
}

func (f *fakePort) SetReadTimeout(t time.Duration) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.timeout = t
	return nil
}

func (f *fakePort) Read(p []byte) (int, error) {
	f.mu.Lock()
	if f.closed {
		f.mu.Unlock()
		return 0, fmt.Errorf("puerto cerrado")
	}
	// Modo continuo: una trama cada 500 ms.
	if f.speaks() && f.continuous && time.Since(f.lastEmit) > 500*time.Millisecond {
		f.lastEmit = time.Now()
		f.pending = append(f.pending, frame(f.weight)...)
	}
	if len(f.pending) > 0 {
		n := copy(p, f.pending)
		f.pending = f.pending[n:]
		f.mu.Unlock()
		return n, nil
	}
	t := f.timeout
	f.mu.Unlock()
	// Igual que el puerto real en Windows: al vencer el tiempo de espera se
	// devuelve (0, nil), NO un error.
	if t > 0 {
		time.Sleep(t)
	}
	return 0, nil
}

func (f *fakePort) Write(p []byte) (int, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.closed {
		return 0, fmt.Errorf("puerto cerrado")
	}
	if f.speaks() && len(p) > 0 && p[0] == 'W' {
		// Formato 9: responde el peso a la peticion.
		f.pending = append(f.pending, frame(f.weight)...)
	}
	return len(p), nil
}

func (f *fakePort) Close() error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.closed = true
	return nil
}

// fakePorts es lo que ve el detector de puertos en modo simulacion.
func fakePorts() []PortInfo {
	return []PortInfo{{
		Name:           "COM3",
		Description:    "USB-SERIAL CH340 (COM3)  [SIMULADO]",
		IsUSB:          true,
		VID:            vidQinheng,
		PID:            "7523",
		LooksLikeCH340: true,
	}}
}
