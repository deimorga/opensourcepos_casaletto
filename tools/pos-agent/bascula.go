package main

import (
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"

	"go.bug.st/serial"
)

// Lector de bascula.
//
// Dos hechos de go.bug.st/serial condicionan este archivo, y estan verificados
// contra la fuente en tools/scale-probe/serialio.go:
//
//  1. En Windows, Read devuelve (0, nil) al vencer el tiempo de espera, NO un
//     error. Tratar n==0 como fin de datos cortaria la escucha en el primer
//     silencio, que es lo normal en una bascula que transmite cada varios
//     cientos de milisegundos.
//  2. La libreria antepone sola el prefijo \\.\ al nombre, asi que COM10 y
//     superiores funcionan sin tratamiento especial.
//
// El agente NO interpreta la trama. Devuelve el texto crudo y el sistema lo
// interpreta con Token_lib::parse_scale() y el patron configurado (§5.4 del
// diseno tecnico). Asi el agente queda tonto y estable, y lo que cambia entre
// basculas vive en configuracion del servidor, donde se corrige sin volver a
// pisar la caja.

// ErrSinBascula lo devuelve cualquier operacion cuando no hay puerto
// configurado. Es una condicion normal --es el estado de fabrica-- y por eso se
// distingue de un fallo de hardware: la pagina puede decir "esta caja no tiene
// bascula" en vez de "error".
var ErrSinBascula = errors.New("no hay puerto de báscula configurado")

// ErrSinLectura es la respuesta honesta cuando la bascula no dijo nada dentro
// del tiempo de espera. Nunca se inventa un peso.
var ErrSinLectura = errors.New("la báscula no envió ninguna lectura")

// puertoSerie es lo minimo que este programa necesita de un puerto. Existe como
// interfaz para poder probar todo el flujo sin bascula ni driver.
type puertoSerie interface {
	Read(p []byte) (int, error)
	Write(p []byte) (int, error)
	SetReadTimeout(t time.Duration) error
	Close() error
}

type abridor func(cfg ConfigBascula) (puertoSerie, error)

// Bascula mantiene el puerto abierto y guarda la ultima trama recibida.
//
// Se mantiene abierto en vez de abrir en cada lectura por dos razones: una
// bascula que transmite sola deja siempre una trama reciente en memoria y el
// peso aparece instantaneo, y abrir un puerto que ya esta transmitiendo es
// justo el caso que Windows a veces reporta como ocupado (§5.10c).
type Bascula struct {
	cfg    ConfigBascula
	abrir  abridor
	logf   func(string, ...any)
	mu     sync.Mutex
	puerto puertoSerie
	// ultima es la trama mas reciente; ultimaHora, cuando llego.
	ultima     string
	ultimaHora time.Time
	// pendiente son los bytes leidos que todavia no completan una trama, y
	// vioDelimitador recuerda si este puerto delimita. Ver guardar(): el sistema
	// operativo entrega trozos arbitrarios del flujo, no tramas.
	pendiente      string
	vioDelimitador bool
	// nuevas avisa a los lectores que espera() que acaba de llegar algo.
	nuevas chan struct{}
	cerrar chan struct{}
	unaVez sync.Once

	// fallosSeguidos y ultimoAviso silencian el bucle de reconexion. Ver
	// avisarFallo.
	fallosSeguidos int
	ultimoAviso    time.Time
}

func NuevaBascula(cfg ConfigBascula, abrir abridor, logf func(string, ...any)) *Bascula {
	if abrir == nil {
		abrir = abrirReal
	}
	if logf == nil {
		logf = func(string, ...any) {}
	}
	return &Bascula{
		cfg:    cfg,
		abrir:  abrir,
		logf:   logf,
		nuevas: make(chan struct{}, 1),
		cerrar: make(chan struct{}),
	}
}

// Configurada dice si hay algo que abrir siquiera.
func (b *Bascula) Configurada() bool { return strings.TrimSpace(b.cfg.Puerto) != "" }

// Arrancar lanza el bucle de lectura en segundo plano. Es idempotente.
func (b *Bascula) Arrancar() {
	if !b.Configurada() {
		return
	}
	b.unaVez.Do(func() { go b.bucle() })
}

func (b *Bascula) Cerrar() {
	select {
	case <-b.cerrar:
	default:
		close(b.cerrar)
	}
	b.mu.Lock()
	if b.puerto != nil {
		_ = b.puerto.Close()
		b.puerto = nil
	}
	b.mu.Unlock()
}

// esperaReconexion es lo que se aguarda antes de reintentar tras un fallo del
// puerto. Suficiente para no llenar el registro cuando alguien desconecta la
// bascula, corto para que volver a conectarla se sienta inmediato.
const esperaReconexion = 3 * time.Second

// rodajaLectura es el tiempo de espera de cada Read individual.
const rodajaLectura = 200 * time.Millisecond

// bucle abre el puerto y lee para siempre, reconectando ante cualquier fallo.
//
// Nunca termina por si solo: si la bascula esta desconectada al arrancar el
// equipo --que es lo normal si el cajero enciende el PC antes que la bascula--
// tiene que engancharse sola cuando aparezca, sin que nadie reinicie nada.
func (b *Bascula) bucle() {
	buf := make([]byte, 4096)
	for {
		select {
		case <-b.cerrar:
			return
		default:
		}

		cfg, err := b.resuelta()
		if err != nil {
			b.avisarFallo(err)
			if !b.dormir(esperaReconexion) {
				return
			}
			continue
		}

		p, err := b.abrir(cfg)
		if err != nil {
			b.avisarFallo(err)
			if !b.dormir(esperaReconexion) {
				return
			}
			continue
		}
		b.fallosSeguidos = 0

		b.mu.Lock()
		b.puerto = p
		b.mu.Unlock()
		b.logf("bascula: puerto %s abierto a %d %d-%s-%d", cfg.Puerto, cfg.Baudios, cfg.BitsDatos, cfg.Paridad, cfg.BitsParada)

		_ = p.SetReadTimeout(rodajaLectura)
		for {
			n, err := p.Read(buf)
			if n > 0 {
				b.guardar(string(buf[:n]))
			}
			if err != nil {
				b.logf("bascula: lectura interrumpida: %v", err)
				break
			}
			select {
			case <-b.cerrar:
				_ = p.Close()
				return
			default:
			}
		}

		b.mu.Lock()
		if b.puerto == p {
			b.puerto = nil
		}
		b.mu.Unlock()
		_ = p.Close()

		if !b.dormir(esperaReconexion) {
			return
		}
	}
}

// intervaloAviso es cada cuanto se repite el aviso de un fallo que persiste.
//
// El bucle reintenta cada pocos segundos --y debe seguir haciendolo, para que
// volver a enchufar la bascula se sienta inmediato-- pero registrar cada
// intento llena la bitacora con la misma linea: una bascula desconectada de
// noche escribe miles de lineas y, como el archivo se trunca al pasar de 2 MB,
// SE LLEVA POR DELANTE el historial que sirve para diagnosticar. El bucle de
// reconexion no puede destruir la unica herramienta de diagnostico que hay.
const intervaloAviso = 5 * time.Minute

// avisarFallo registra el primer fallo y despues solo de vez en cuando.
func (b *Bascula) avisarFallo(err error) {
	b.fallosSeguidos++
	ahora := time.Now()

	if b.fallosSeguidos == 1 {
		// Se lista lo que SI hay conectado. Es la diferencia entre "no se pudo
		// abrir COM7", que no dice nada, y una linea que se diagnostica sola.
		// En automatico el propio error ya trae esa lista, y repetirla sobra.
		contexto := ""
		if !b.enAutomatico() {
			contexto = " -- " + ResumenPuertos()
		}
		b.logf("bascula: no se pudo abrir %s: %v%s (se reintenta en silencio cada %s)",
			b.comoSeLlamaElPuerto(), err, contexto, esperaReconexion)
		b.ultimoAviso = ahora
		return
	}
	if ahora.Sub(b.ultimoAviso) >= intervaloAviso {
		b.logf("bascula: %s sigue sin responder tras %d intentos: %v", b.comoSeLlamaElPuerto(), b.fallosSeguidos, err)
		b.ultimoAviso = ahora
	}
}

func (b *Bascula) dormir(d time.Duration) bool {
	select {
	case <-time.After(d):
		return true
	case <-b.cerrar:
		return false
	}
}

// maxPendiente acota el acumulador, para que una bascula que no delimita nunca
// no haga crecer la memoria sin freno.
const maxPendiente = 4096

// delimitadores son los finales de trama que se reconocen.
//
// CR y LF, y nada mas. Reconocer un delimitador NO es interpretar la trama --que
// significan los caracteres se sigue decidiendo en el servidor-- pero si es
// saber donde empieza y donde termina, y eso no lo puede hacer nadie mas: para
// cuando los bytes llegan al servidor, el corte ya se perdio.
const delimitadores = "\r\n"

// guardar ensambla los trozos que entrega el sistema operativo y publica la
// ULTIMA TRAMA COMPLETA, con su terminador tal cual vino.
//
// ANTES PUBLICABA EL TROZO TAL CUAL, Y UN TROZO NO ES UNA TRAMA.
//
// `p.Read()` devuelve lo que haya en el bufer del puerto en ese instante y el
// corte cae donde cae. Contra la bascula real de Paraiso, que emite
// `000.560<CR>`, el sistema entregaba `0` en una lectura y `00.560<CR>` en la
// siguiente: el agente publicaba `00.560`, con el primer digito perdido en el
// trozo anterior.
//
// Costo real: la caja quedo configurada con `{W:7}` --siete caracteres, medidos
// leyendo el puerto directamente-- y no leia nada, porque lo que le llegaba
// tenia seis. Un `{W:6}` habria sido peor: funcionaria por casualidad hasta el
// dia en que el corte cayera un byte mas alla.
//
// MIENTRAS NO SE HAYA VISTO NUNCA UN DELIMITADOR se publica el trozo, que es el
// comportamiento anterior. Hay basculas que emiten ancho fijo sin terminador y
// para esas no hay forma de saber donde corta una trama; romperlas para arreglar
// esto seria cambiar un defecto por otro. En cuanto aparece el primer
// delimitador, el flujo es de lineas y los pedazos se acumulan.
func (b *Bascula) guardar(trozo string) {
	b.mu.Lock()

	acumulado := b.pendiente + trozo
	corte := strings.LastIndexAny(acumulado, delimitadores)

	if corte < 0 {
		b.pendiente = acumulado
		if len(b.pendiente) > maxPendiente {
			b.pendiente = ""
		}

		if b.vioDelimitador {
			// Trama a medias de una bascula que si delimita: se espera al resto.
			b.mu.Unlock()

			return
		}

		b.ultima = trozo
		b.ultimaHora = time.Now()
		b.mu.Unlock()
		b.avisar()

		return
	}

	b.vioDelimitador = true

	completas := acumulado[:corte+1]
	b.pendiente = acumulado[corte+1:]

	// La ultima trama completa, no la primera: lo que interesa es el peso de
	// ahora. `inicio` retrocede desde el final saltando primero los terminadores
	// y despues el contenido, para devolver la trama CON su terminador y no una
	// version recortada -- el agente no interpreta, y quitar bytes es
	// interpretar.
	fin := len(completas)
	for fin > 0 && strings.ContainsRune(delimitadores, rune(completas[fin-1])) {
		fin--
	}

	if fin == 0 {
		// Solo terminadores: no hay trama nueva que publicar.
		b.mu.Unlock()

		return
	}

	inicio := fin
	for inicio > 0 && !strings.ContainsRune(delimitadores, rune(completas[inicio-1])) {
		inicio--
	}

	b.ultima = completas[inicio:]
	b.ultimaHora = time.Now()
	b.mu.Unlock()

	b.avisar()
}

// avisar despierta a quien este esperando una trama nueva.
func (b *Bascula) avisar() {

	// Aviso no bloqueante: si nadie espera, no pasa nada.
	select {
	case b.nuevas <- struct{}{}:
	default:
	}
}

// Leer devuelve la trama cruda mas reciente.
//
// Si hay una lo bastante fresca, responde de inmediato. Si no, envia el comando
// configurado --para las basculas que solo hablan cuando se les pregunta-- y
// espera. Si nada llega, devuelve ErrSinLectura: jamas un peso inventado.
func (b *Bascula) Leer() (string, time.Time, error) {
	if !b.Configurada() {
		return "", time.Time{}, ErrSinBascula
	}
	b.Arrancar()

	if trama, hora, ok := b.fresca(); ok {
		return trama, hora, nil
	}

	// Se vacia el aviso pendiente ANTES de pedir, para no confundir una trama
	// vieja no consumida con la respuesta a este comando.
	select {
	case <-b.nuevas:
	default:
	}

	limite := time.After(b.cfg.espera())

	if cmd := b.cfg.Comando; cmd != "" {
		// El bucle de lectura abre el puerto en segundo plano, asi que la
		// primera lectura del dia puede llegar antes de que este abierto. Se
		// espera --dentro del mismo presupuesto de tiempo-- en vez de fallar:
		// si no, el primer pesaje tras encender la caja daria un error que
		// desaparece solo al segundo intento, que es de los sintomas mas
		// dificiles de diagnosticar por telefono.
		p := b.esperarPuerto(b.cfg.espera())
		if p == nil {
			return "", time.Time{}, fmt.Errorf("el puerto %s no está abierto: %w", b.cfg.Puerto, ErrSinLectura)
		}
		if _, err := p.Write([]byte(descodificarComando(cmd))); err != nil {
			return "", time.Time{}, fmt.Errorf("no se pudo pedir el peso: %w", err)
		}
	}

	for {
		select {
		case <-b.nuevas:
			b.mu.Lock()
			trama, hora := b.ultima, b.ultimaHora
			b.mu.Unlock()
			if trama != "" {
				return trama, hora, nil
			}
		case <-limite:
			return "", time.Time{}, ErrSinLectura
		case <-b.cerrar:
			return "", time.Time{}, ErrSinLectura
		}
	}
}

// puertoAutomatico es el valor de configuracion que pide buscar la bascula por
// su identidad en vez de por un numero fijo.
const puertoAutomatico = "auto"

// resuelta devuelve la configuracion con el puerto ya decidido.
//
// Con "auto" se busca el CH340 en cada vuelta del bucle, no una sola vez al
// arrancar: la bascula puede estar apagada cuando enciende la caja, o alguien
// puede moverla de enchufe a media manana, y en los dos casos tiene que
// engancharse sola sin que nadie reinicie nada.
func (b *Bascula) resuelta() (ConfigBascula, error) {
	cfg := b.cfg
	if !strings.EqualFold(strings.TrimSpace(cfg.Puerto), puertoAutomatico) {
		return cfg, nil
	}
	nombre, err := BuscarBascula()
	if err != nil {
		// Se devuelve la configuracion SIN puerto, no con "auto" dentro: una
		// palabra clave que se cuela hasta el sistema operativo como si fuera
		// el nombre de un dispositivo es un error que aparece lejos de aqui.
		cfg.Puerto = ""
		return cfg, err
	}
	cfg.Puerto = nombre
	return cfg, nil
}

// enAutomatico dice si el puerto se busca en vez de venir fijado.
func (b *Bascula) enAutomatico() bool {
	return strings.EqualFold(strings.TrimSpace(b.cfg.Puerto), puertoAutomatico)
}

// comoSeLlamaElPuerto describe el puerto para la bitacora.
func (b *Bascula) comoSeLlamaElPuerto() string {
	if b.enAutomatico() {
		// Sin tildes: la consola de Windows lee en la pagina de codigos del
		// sistema y "busqueda automatica" salia "bA-squeda automA!tica".
		return "la bascula (busqueda automatica)"
	}
	return b.cfg.Puerto
}

// esperarPuerto devuelve el puerto abierto, aguardando hasta d a que el bucle
// de fondo lo abra. Devuelve nil si vence el plazo.
func (b *Bascula) esperarPuerto(d time.Duration) puertoSerie {
	const sondeo = 25 * time.Millisecond
	limite := time.Now().Add(d)
	for {
		b.mu.Lock()
		p := b.puerto
		b.mu.Unlock()
		if p != nil {
			return p
		}
		if time.Now().After(limite) {
			return nil
		}
		select {
		case <-time.After(sondeo):
		case <-b.cerrar:
			return nil
		}
	}
}

func (b *Bascula) fresca() (string, time.Time, bool) {
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.ultima == "" || b.cfg.frescura() <= 0 {
		return "", time.Time{}, false
	}
	if time.Since(b.ultimaHora) > b.cfg.frescura() {
		return "", time.Time{}, false
	}
	return b.ultima, b.ultimaHora, true
}

// Abierto informa si el puerto esta abierto ahora mismo, para el estado.
func (b *Bascula) Abierto() bool {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.puerto != nil
}

// descodificarComando traduce los escapes que se pueden escribir en un JSON a
// mano. Un comando de bascula suele llevar CR o LF, y "\r" dentro del archivo
// de configuracion ya llega como retorno de carro real gracias al propio JSON;
// pero \x05 (ENQ), que es uno de los sondeos del scale-probe, no tiene forma
// literal comoda, asi que se acepta la notacion <ENQ> y compania.
func descodificarComando(s string) string {
	r := strings.NewReplacer(
		"<CR>", "\r",
		"<LF>", "\n",
		"<ENQ>", "\x05",
		"<STX>", "\x02",
		"<ETX>", "\x03",
	)
	return r.Replace(s)
}

// abrirReal abre un puerto COM de verdad, con reintentos.
//
// Los reintentos implementan la advertencia del §5.10c: si la bascula ya esta
// transmitiendo cuando se abre el puerto, Windows puede reportarlo ocupado.
func abrirReal(cfg ConfigBascula) (puertoSerie, error) {
	modo := &serial.Mode{BaudRate: cfg.Baudios, DataBits: cfg.BitsDatos}
	switch cfg.Paridad {
	case "E":
		modo.Parity = serial.EvenParity
	case "O":
		modo.Parity = serial.OddParity
	default:
		modo.Parity = serial.NoParity
	}
	if cfg.BitsParada == 2 {
		modo.StopBits = serial.TwoStopBits
	} else {
		modo.StopBits = serial.OneStopBit
	}

	const intentos = 3
	var ultimo error
	for i := 0; i < intentos; i++ {
		p, err := serial.Open(cfg.Puerto, modo)
		if err == nil {
			return p, nil
		}
		ultimo = err
		if i < intentos-1 {
			time.Sleep(700 * time.Millisecond)
		}
	}
	return nil, fmt.Errorf("tras %d intentos: %w", intentos, ultimo)
}
