package main

import (
	"fmt"
	"time"

	"go.bug.st/serial"
	"go.bug.st/serial/enumerator"
)

// Unica capa que toca go.bug.st/serial v1.8.0.
//
// Documentacion consultada (source-driven, todo verificado contra la fuente):
//   - API:        https://pkg.go.dev/go.bug.st/serial
//   - Enumerador: https://pkg.go.dev/go.bug.st/serial/enumerator
//   - Windows:    https://github.com/bugst/go-serial/blob/master/serial_windows.go
//
// Dos hechos de esa lectura condicionan todo el codigo de abajo:
//
//  1. En Windows, Read devuelve (0, nil) cuando VENCE EL TIEMPO DE ESPERA, no
//     un error. El bucle de lectura NO puede tratar n==0 como fin de datos: si
//     lo hiciera, cortaria la escucha en el primer instante de silencio, que es
//     justo lo normal en una bascula que transmite cada varios segundos.
//     Fuente: serial_windows.go, funcion Read -> "if hasTimeout { return 0, nil }".
//
//  2. La libreria antepone sola el prefijo `\\.\` al nombre del puerto, asi que
//     COM10 y superiores funcionan sin tratamiento especial. Fuente:
//     serial_windows.go, nativeOpen -> `if !strings.HasPrefix(portName, "\\\\.\\")`.
//
// Ademas: la libreria evita cgo salvo en el enumerador de macOS, por lo que la
// compilacion cruzada a Windows con CGO_ENABLED=0 funciona sin dependencias.

// portHandle es lo minimo que este programa necesita de un puerto. Existe como
// interfaz para poder correr todo el flujo contra un puerto simulado (-simular)
// sin bascula ni driver.
type portHandle interface {
	Read(p []byte) (int, error)
	Write(p []byte) (int, error)
	SetReadTimeout(t time.Duration) error
	Close() error
}

// opener abre un puerto con una configuracion dada.
type opener func(name string, cfg PortConfig) (portHandle, error)

// PortInfo es un puerto COM detectado, con lo que Windows sepa decir de el.
type PortInfo struct {
	Name string
	// Description sale de SPDRP_FRIENDLYNAME en Windows: es literalmente el
	// texto que el Administrador de dispositivos muestra, p. ej.
	// "USB-SERIAL CH340 (COM3)". Es lo que permite al operario reconocer la
	// bascula sin saber que es un puerto serie.
	Description string
	IsUSB       bool
	VID, PID    string
	// LooksLikeCH340 marca el puerto que casi seguro es la bascula.
	LooksLikeCH340 bool
}

// vidQinheng es el identificador de fabricante de WCH/QinHeng, el que fabrica
// el chip CH340 que lleva esta bascula (§5.8). Se usa solo para SUGERIR un
// puerto, nunca para descartar otros: si el chip fuera distinto igual hay que
// poder elegirlo a mano.
const vidQinheng = "1A86"

// ListPorts enumera los puertos serie con su descripcion.
//
// Se usa GetDetailedPortsList y no GetPortsList porque el operario no es
// tecnico: "COM3" no le dice nada, "USB-SERIAL CH340 (COM3)" si.
func ListPorts() ([]PortInfo, error) {
	details, err := enumerator.GetDetailedPortsList()
	if err != nil {
		// Si el enumerador detallado falla, se cae a la lista simple antes de
		// darse por vencido: un puerto sin descripcion sirve igual.
		names, err2 := serial.GetPortsList()
		if err2 != nil {
			return nil, fmt.Errorf("no se pudieron enumerar los puertos: %v (y la lista simple también falló: %v)", err, err2)
		}
		out := make([]PortInfo, 0, len(names))
		for _, n := range names {
			out = append(out, PortInfo{Name: n, Description: "(sin descripción disponible)"})
		}
		return out, nil
	}
	out := make([]PortInfo, 0, len(details))
	for _, d := range details {
		if d == nil {
			continue
		}
		p := PortInfo{
			Name:        d.Name,
			Description: d.Product,
			IsUSB:       d.IsUSB,
			VID:         d.VID,
			PID:         d.PID,
		}
		if p.Description == "" {
			p.Description = "(sin descripción disponible)"
		}
		p.LooksLikeCH340 = looksLikeCH340(p)
		out = append(out, p)
	}
	return out, nil
}

// looksLikeCH340 reconoce el puente USB-serie de la bascula, por identificador
// de fabricante o por el nombre que Windows le pone.
func looksLikeCH340(p PortInfo) bool {
	if equalFold(p.VID, vidQinheng) {
		return true
	}
	return containsFold(p.Description, "CH340") ||
		containsFold(p.Description, "CH341") ||
		containsFold(p.Description, "USB-SERIAL")
}

// toSerialMode traduce nuestra configuracion a la de la libreria.
func toSerialMode(c PortConfig) (*serial.Mode, error) {
	m := &serial.Mode{BaudRate: c.Baud, DataBits: c.DataBits}
	switch c.Parity {
	case ParityNone:
		m.Parity = serial.NoParity
	case ParityEven:
		m.Parity = serial.EvenParity
	case ParityOdd:
		m.Parity = serial.OddParity
	default:
		return nil, fmt.Errorf("paridad desconocida: %q", string(c.Parity))
	}
	switch c.StopBits {
	case 1:
		m.StopBits = serial.OneStopBit
	case 2:
		m.StopBits = serial.TwoStopBits
	default:
		return nil, fmt.Errorf("bits de parada no soportados: %d", c.StopBits)
	}
	return m, nil
}

// openAttempts y openRetryDelay implementan la advertencia del §5.10c del
// diseno tecnico: si la bascula ya esta transmitiendo cuando se abre el puerto,
// Windows puede reportarlo como ocupado. Hay que reintentar, no rendirse.
const openAttempts = 3
const openRetryDelay = 700 * time.Millisecond

// openReal abre un puerto COM de verdad, con reintentos.
func openReal(name string, cfg PortConfig) (portHandle, error) {
	mode, err := toSerialMode(cfg)
	if err != nil {
		return nil, err
	}
	var last error
	for i := 0; i < openAttempts; i++ {
		p, err := serial.Open(name, mode)
		if err == nil {
			return p, nil
		}
		last = err
		if i < openAttempts-1 {
			time.Sleep(openRetryDelay)
		}
	}
	return nil, fmt.Errorf("tras %d intentos: %w", openAttempts, last)
}

// readSlice es el tiempo de espera de cada Read individual. Corto a proposito:
// determina con que resolucion se marca la hora de llegada de los bytes, y
// permite cortar la escucha en cuanto vence la ventana.
const readSlice = 200 * time.Millisecond

// minReadTimeout es el piso del tiempo de espera de lectura. Un valor muy
// pequeno se redondea a cero en Windows, y cero significa "devuelve ya", que
// convertiria el bucle en una espera activa.
const minReadTimeout = 10 * time.Millisecond

// minLoopSleep evita que un Read que devuelva de inmediato (0, nil) haga girar
// el bucle a toda maquina. Seguro barato contra una implementacion que no
// respete el tiempo de espera.
const minLoopSleep = 5 * time.Millisecond

// listen escucha durante d y llama a onData por cada bloque de bytes recibido.
//
// Nunca devuelve error por vencimiento del tiempo de espera: eso es lo normal.
// Solo devuelve error si el puerto se rompe de verdad, y aun asi el llamador
// registra y sigue.
func listen(p portHandle, d time.Duration, onData func(at time.Time, b []byte)) error {
	buf := make([]byte, 4096)
	deadline := time.Now().Add(d)
	// cur evita reprogramar el tiempo de espera en cada vuelta: solo se cambia
	// cuando hace falta, que es al acercarse el final de la ventana.
	cur := time.Duration(-1)
	for {
		remaining := time.Until(deadline)
		if remaining <= 0 {
			return nil
		}
		// El ultimo Read de la ventana se acorta para no pasarse del plazo.
		// Sin esto cada llamada podia sobrepasarlo hasta readSlice, y con 17
		// ventanas por configuracion y 6 configuraciones la deriva acumulada
		// llegaba a ~20s: una mordida seria al presupuesto de 4 minutos.
		want := readSlice
		if remaining < want {
			want = remaining
		}
		if want < minReadTimeout {
			want = minReadTimeout
		}
		if want != cur {
			if err := p.SetReadTimeout(want); err != nil {
				return fmt.Errorf("no se pudo fijar el tiempo de espera de lectura: %w", err)
			}
			cur = want
		}
		loopStart := time.Now()
		n, err := p.Read(buf)
		if n > 0 {
			// Copia: buf se reutiliza en la siguiente vuelta.
			chunk := make([]byte, n)
			copy(chunk, buf[:n])
			onData(time.Now(), chunk)
		}
		if err != nil {
			return err
		}
		if n == 0 && time.Since(loopStart) < minLoopSleep {
			time.Sleep(minLoopSleep)
		}
	}
}

func equalFold(a, b string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := 0; i < len(a); i++ {
		if lower(a[i]) != lower(b[i]) {
			return false
		}
	}
	return true
}

func containsFold(hay, needle string) bool {
	if len(needle) == 0 {
		return true
	}
	for i := 0; i+len(needle) <= len(hay); i++ {
		ok := true
		for j := 0; j < len(needle); j++ {
			if lower(hay[i+j]) != lower(needle[j]) {
				ok = false
				break
			}
		}
		if ok {
			return true
		}
	}
	return false
}

func lower(c byte) byte {
	if c >= 'A' && c <= 'Z' {
		return c + 32
	}
	return c
}
