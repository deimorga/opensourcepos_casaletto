package main

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"strings"
	"time"
)

// Capa de pantalla. Todo lo que ve el operario pasa por aqui.
//
// Dos decisiones gobiernan este archivo:
//
//   - El operario NO es tecnico. Frases cortas, en español, una instruccion por
//     linea, y siempre visible cuanto falta.
//   - El programa se arranca con DOBLE CLIC. Eso significa que la ventana se
//     cierra sola al terminar y el operario no alcanza a leer nada. Por eso
//     todo final —incluido el de error— pasa por Pause().

// UI escribe en consola con o sin acentos segun lo que aguante la terminal.
type UI struct {
	w io.Writer
	// ascii true cuando no se pudo poner la consola en UTF-8.
	ascii bool
	// tty true cuando la salida es una consola de verdad y se puede reescribir
	// la linea con \r. Si esta redirigida a un archivo, \r solo ensucia.
	tty bool
	in  *inputGate
}

func NewUI(w io.Writer, utf8OK, tty bool, in *inputGate) *UI {
	return &UI{w: w, ascii: !utf8OK, tty: tty, in: in}
}

func (u *UI) render(s string) string {
	if u.ascii {
		return Transliterate(s)
	}
	return s
}

func (u *UI) Say(format string, a ...any) {
	fmt.Fprintln(u.w, u.render(fmt.Sprintf(format, a...)))
}

func (u *UI) Blank() { fmt.Fprintln(u.w) }

// Banner dibuja un titulo de fase bien visible.
func (u *UI) Banner(title string) {
	line := strings.Repeat("=", 64)
	fmt.Fprintln(u.w, line)
	fmt.Fprintln(u.w, u.render("  "+title))
	fmt.Fprintln(u.w, line)
}

// Step muestra una instruccion destacada para el operario.
func (u *UI) Step(lines ...string) {
	fmt.Fprintln(u.w)
	fmt.Fprintln(u.w, strings.Repeat("*", 64))
	for _, l := range lines {
		fmt.Fprintln(u.w, u.render("  "+l))
	}
	fmt.Fprintln(u.w, strings.Repeat("*", 64))
}

// Countdown muestra la cuenta regresiva de una ventana de captura.
//
// Se ejecuta en su propia gorrutina mientras el puerto se escucha; stop la
// corta. El contador de tramas se lee en vivo con la funcion que se le pasa,
// para que el operario vea que SI esta llegando algo: es la unica
// realimentacion que tiene de que la bascula esta hablando.
func (u *UI) Countdown(d time.Duration, frames func() int, stop <-chan struct{}) {
	deadline := time.Now().Add(d)
	tick := time.NewTicker(500 * time.Millisecond)
	defer tick.Stop()
	last := -1
	for {
		select {
		case <-stop:
			if u.tty {
				fmt.Fprintf(u.w, "\r%s\r", strings.Repeat(" ", 62))
			}
			return
		case <-tick.C:
			rem := int(time.Until(deadline).Seconds() + 0.5)
			if rem < 0 {
				rem = 0
			}
			n := frames()
			if u.tty {
				msg := fmt.Sprintf("   Faltan %2d s   |   datos recibidos: %d", rem, n)
				fmt.Fprintf(u.w, "\r%-62s", u.render(msg))
			} else if rem != last && rem%5 == 0 {
				fmt.Fprintln(u.w, u.render(fmt.Sprintf("   Faltan %d s | datos recibidos: %d", rem, n)))
			}
			last = rem
		}
	}
}

// inputGate lee lineas de la entrada estandar sin bloquear al programa.
//
// La gorrutina de lectura no se puede cancelar (un Read sobre stdin bloquea
// hasta que llega algo), asi que vive lo que viva el programa y se abandona sin
// mas. Es aceptable: no tiene recursos que liberar.
type inputGate struct {
	ch chan string
}

func newInputGate(r io.Reader) *inputGate {
	g := &inputGate{ch: make(chan string, 8)}
	go func() {
		sc := bufio.NewScanner(r)
		for sc.Scan() {
			select {
			case g.ch <- strings.TrimSpace(sc.Text()):
			default:
			}
		}
		// Fin de entrada (ejecucion no interactiva): se cierra para que
		// cualquier espera posterior siga de largo en vez de colgarse.
		close(g.ch)
	}()
	return g
}

// Wait espera a que el operario presione ENTER, pero NUNCA para siempre.
//
// El limite de tiempo es la parte importante: la bascula esta prestada cinco
// minutos y si el operario se distrae, el programa tiene que seguir solo. Un
// programa colgado esperando ENTER es un programa que termina sin archivo, que
// es el peor resultado posible.
func (u *UI) Wait(prompt string, max time.Duration) {
	fmt.Fprintln(u.w, u.render(prompt))
	if u.in == nil {
		time.Sleep(max)
		return
	}
	select {
	case <-u.in.ch:
	case <-time.After(max):
		fmt.Fprintln(u.w, u.render("   (se continúa automáticamente)"))
	}
}

// Ask pide un dato al operario, con valor por omision y limite de tiempo.
//
// Mismo criterio que Wait: si nadie contesta, se sigue con el valor por
// omision. Nunca se queda esperando.
func (u *UI) Ask(prompt, def string, max time.Duration) string {
	fmt.Fprint(u.w, u.render(prompt))
	if u.in == nil {
		fmt.Fprintln(u.w)
		return def
	}
	select {
	case s, ok := <-u.in.ch:
		if !ok || s == "" {
			return def
		}
		return s
	case <-time.After(max):
		fmt.Fprintln(u.w, u.render(fmt.Sprintf("   (sin respuesta; se usa %q)", def)))
		return def
	}
}

// Pause detiene el programa al final para que la ventana no se cierre sola
// cuando se arranco con doble clic.
func (u *UI) Pause() {
	fmt.Fprintln(u.w)
	fmt.Fprintln(u.w, u.render("Presione ENTER para cerrar esta ventana."))
	if u.in == nil {
		return
	}
	<-u.in.ch
}

// isTTY dice si f es una consola y no un archivo o una tuberia.
func isTTY(f *os.File) bool {
	st, err := f.Stat()
	if err != nil {
		return false
	}
	return st.Mode()&os.ModeCharDevice != 0
}
