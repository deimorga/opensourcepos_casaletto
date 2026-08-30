package main

import (
	"errors"
	"testing"
	"time"

	"go.bug.st/serial"
)

// scriptedPort devuelve una secuencia fija de lecturas y luego se queda
// callado, como un puerto real que agota el tiempo de espera.
type scriptedPort struct {
	reads   [][]byte
	i       int
	err     error
	timeout time.Duration
	// buf se reutiliza a propósito, igual que hace el puerto real, para
	// detectar si listen se queda con una referencia en vez de copiar.
	buf     [64]byte
	written []byte
	sets    int
}

func (s *scriptedPort) SetReadTimeout(t time.Duration) error { s.timeout = t; s.sets++; return nil }
func (s *scriptedPort) Close() error                         { return nil }
func (s *scriptedPort) Write(p []byte) (int, error) {
	s.written = append(s.written, p...)
	return len(p), nil
}

func (s *scriptedPort) Read(p []byte) (int, error) {
	if s.err != nil {
		return 0, s.err
	}
	if s.i < len(s.reads) {
		chunk := s.reads[s.i]
		s.i++
		n := copy(s.buf[:], chunk)
		return copy(p, s.buf[:n]), nil
	}
	// Agotado: se comporta como el puerto real de Windows, que devuelve
	// (0, nil) al vencer el tiempo de espera, NO un error.
	time.Sleep(s.timeout)
	return 0, nil
}

// TestListenTreatsZeroBytesAsSilenceNotEOF es la prueba central del bucle de
// lectura. En Windows, Read devuelve (0, nil) cuando vence el tiempo de espera
// (ver serial_windows.go en go.bug.st/serial v1.8.0). Un bucle que tratara eso
// como fin de datos cortaría la escucha en el primer silencio, que es
// exactamente lo normal en una báscula que transmite cada varios segundos: se
// perdería todo lo que llegara después.
func TestListenTreatsZeroBytesAsSilenceNotEOF(t *testing.T) {
	p := &scriptedPort{reads: [][]byte{
		nil,          // silencio
		nil,          // silencio
		moriscoFrame, // ...y recién aquí llega la trama
	}}
	var got []byte
	if err := listen(p, 900*time.Millisecond, func(_ time.Time, b []byte) {
		got = append(got, b...)
	}); err != nil {
		t.Fatalf("listen devolvió error: %v", err)
	}
	if string(got) != string(moriscoFrame) {
		t.Errorf("se perdió la trama que llegó tras el silencio: got %q", got)
	}
}

func TestListenRespectsDeadline(t *testing.T) {
	p := &scriptedPort{}
	start := time.Now()
	if err := listen(p, 300*time.Millisecond, func(time.Time, []byte) {}); err != nil {
		t.Fatalf("err = %v", err)
	}
	elapsed := time.Since(start)
	if elapsed < 300*time.Millisecond {
		t.Errorf("terminó antes de tiempo: %v", elapsed)
	}
	// El margen de sobrepaso tiene que ser pequeño: la deriva acumulada sobre
	// 17 ventanas por configuración y 6 configuraciones es lo que se come el
	// presupuesto de 4 minutos.
	if elapsed > 350*time.Millisecond {
		t.Errorf("se pasó del plazo: %v (máximo tolerado 350ms)", elapsed)
	}
}

func TestListenCopiesEachChunk(t *testing.T) {
	p := &scriptedPort{reads: [][]byte{[]byte("AAAA"), []byte("BBBB")}}
	var chunks [][]byte
	if err := listen(p, 250*time.Millisecond, func(_ time.Time, b []byte) {
		chunks = append(chunks, b)
	}); err != nil {
		t.Fatalf("err = %v", err)
	}
	if len(chunks) != 2 {
		t.Fatalf("se esperaban 2 bloques, hubo %d", len(chunks))
	}
	// Si listen no copiara, el segundo Read habría sobrescrito el primero.
	if string(chunks[0]) != "AAAA" || string(chunks[1]) != "BBBB" {
		t.Errorf("los bloques se pisaron: %q, %q", chunks[0], chunks[1])
	}
}

func TestListenReturnsPortErrors(t *testing.T) {
	want := errors.New("el puerto desapareció")
	p := &scriptedPort{err: want}
	err := listen(p, time.Second, func(time.Time, []byte) {})
	if !errors.Is(err, want) {
		t.Errorf("err = %v, want %v", err, want)
	}
}

func TestListenZeroDurationDoesNothing(t *testing.T) {
	p := &scriptedPort{}
	if err := listen(p, 0, func(time.Time, []byte) { t.Error("no debía leer nada") }); err != nil {
		t.Errorf("err = %v", err)
	}
}

func TestToSerialMode(t *testing.T) {
	cases := []struct {
		c    PortConfig
		want serial.Mode
	}{
		{PortConfig{9600, 8, ParityNone, 1, ""}, serial.Mode{BaudRate: 9600, DataBits: 8, Parity: serial.NoParity, StopBits: serial.OneStopBit}},
		{PortConfig{9600, 7, ParityEven, 1, ""}, serial.Mode{BaudRate: 9600, DataBits: 7, Parity: serial.EvenParity, StopBits: serial.OneStopBit}},
		{PortConfig{2400, 7, ParityOdd, 2, ""}, serial.Mode{BaudRate: 2400, DataBits: 7, Parity: serial.OddParity, StopBits: serial.TwoStopBits}},
	}
	for _, c := range cases {
		got, err := toSerialMode(c.c)
		if err != nil {
			t.Fatalf("%s: %v", c.c.ID(), err)
		}
		if *got != c.want {
			t.Errorf("%s: got %+v, want %+v", c.c.ID(), *got, c.want)
		}
	}
	// Toda la tabla por omisión tiene que ser traducible; si no, esa
	// configuración se perdería en silencio el día de la captura.
	for _, c := range DefaultConfigs() {
		if _, err := toSerialMode(c); err != nil {
			t.Errorf("no se pudo traducir %s: %v", c.ID(), err)
		}
	}
}

func TestToSerialModeRejectsUnknown(t *testing.T) {
	if _, err := toSerialMode(PortConfig{9600, 8, Parity('X'), 1, ""}); err == nil {
		t.Error("una paridad desconocida debe dar error")
	}
	if _, err := toSerialMode(PortConfig{9600, 8, ParityNone, 3, ""}); err == nil {
		t.Error("3 bits de parada debe dar error")
	}
}

func TestLooksLikeCH340(t *testing.T) {
	cases := []struct {
		p    PortInfo
		want bool
	}{
		{PortInfo{VID: "1A86"}, true},                            // WCH/QinHeng, el fabricante del CH340
		{PortInfo{VID: "1a86"}, true},                            // sin distinguir mayúsculas
		{PortInfo{Description: "USB-SERIAL CH340 (COM3)"}, true}, // lo que muestra Windows
		{PortInfo{Description: "USB-Serial CH341A"}, true},       //
		{PortInfo{Description: "USB-SERIAL (COM5)"}, true},       // CH340 con nombre genérico
		{PortInfo{Description: "Puerto de comunicaciones (COM1)"}, false},
		// Un adaptador FTDI NO es la báscula. Marcarlo mandaría al operario al
		// puerto equivocado, y la marca solo sirve si es de fiar.
		{PortInfo{VID: "0403", Description: "FTDI USB Serial Port (COM4)"}, false},
		{PortInfo{}, false},
	}
	for _, c := range cases {
		if got := looksLikeCH340(c.p); got != c.want {
			t.Errorf("looksLikeCH340(%+v) = %v, want %v", c.p, got, c.want)
		}
	}
}

func TestEqualFoldAndContainsFold(t *testing.T) {
	if !equalFold("1A86", "1a86") || equalFold("1A86", "1A8") || equalFold("abc", "abd") {
		t.Error("equalFold falla")
	}
	if !containsFold("USB-SERIAL CH340 (COM3)", "ch340") {
		t.Error("containsFold debía encontrar ch340")
	}
	if containsFold("COM1", "ch340") {
		t.Error("containsFold no debía encontrar ch340")
	}
	if !containsFold("cualquier cosa", "") {
		t.Error("la subcadena vacía siempre está")
	}
}

// TestFakePortMimicsWindowsTimeout deja fijado que el puerto simulado se
// comporta como el real en lo único que importa para el bucle de lectura.
func TestFakePortMimicsWindowsTimeout(t *testing.T) {
	weight := 1.245
	open := newFakeOpener(false, &weight)
	p, err := open("COM3", DefaultConfigs()[0])
	if err != nil {
		t.Fatal(err)
	}
	defer p.Close()
	p.SetReadTimeout(10 * time.Millisecond)
	buf := make([]byte, 64)
	n, err := p.Read(buf)
	if n != 0 || err != nil {
		t.Errorf("sin datos debe devolver (0, nil), devolvió (%d, %v)", n, err)
	}
	// Y en 9600 8-N-1 tiene que responder a W, como el formato 9.
	if _, err := p.Write([]byte("W")); err != nil {
		t.Fatal(err)
	}
	n, err = p.Read(buf)
	if err != nil || n == 0 {
		t.Fatalf("debía responder a W: (%d, %v)", n, err)
	}
	if got := string(buf[:n]); got[0] != 'N' {
		t.Errorf("la trama simulada debe empezar por la bandera N: %q", got)
	}
}

// TestFakePortStaysSilentOnWrongConfig confirma que la simulación solo habla en
// la configuración documentada, para que el ensayo recorra el mismo camino que
// el día real: cinco configuraciones mudas y una que contesta.
func TestFakePortStaysSilentOnWrongConfig(t *testing.T) {
	weight := 1.245
	open := newFakeOpener(true, &weight)
	for _, c := range DefaultConfigs()[1:] {
		p, err := open("COM3", c)
		if err != nil {
			t.Fatal(err)
		}
		p.SetReadTimeout(time.Millisecond)
		buf := make([]byte, 64)
		if n, _ := p.Read(buf); n != 0 {
			t.Errorf("%s debía quedarse muda, devolvió %d bytes", c.ID(), n)
		}
		p.Close()
	}
}
