// scale-probe descubre el protocolo serie de la báscula ROCHI RC-A01E.
//
// Contexto y criterio de diseño: solo se tendrán CINCO MINUTOS de acceso físico
// a la báscula, prestada, una sola vez. No se puede desarrollar durante esos
// cinco minutos: hay que capturarlo todo y desarrollar después con la captura.
// Si la captura sale incompleta no hay segunda oportunidad.
//
// De ahí las reglas que gobiernan todo el programa:
//
//   - Nunca abortar. Un puerto que no abre o una configuración que falla se
//     registran y se sigue con la siguiente. El peor resultado posible es
//     terminar sin archivo.
//   - Escribir a disco incrementalmente, no al final.
//   - No descartar nunca un byte por parecer basura.
//   - Todo el barrido cabe en menos de cuatro minutos, con presupuesto medido.
//
// Ver docs/Tecnico/venta-por-peso-y-hardware-de-caja.md, §5.8 a §5.10c.
package main

import (
	"bytes"
	"flag"
	"fmt"
	"io"
	"os"
	"sort"
	"strings"
	"sync/atomic"
	"time"
)

const toolVersion = "1.0"

// Options es la configuración de una ejecución.
type Options struct {
	Port     string
	Simulate bool
	// SimQuiet hace que la báscula simulada no transmita sola, para ensayar el
	// caso en que solo responde a comandos.
	SimQuiet  bool
	ListOnly  bool
	NoPause   bool
	MaxGuided int
	Timings   Timings
}

// parseArgs interpreta los argumentos. Es una función pura —no toca estado
// global, escribe la ayuda en el io.Writer que se le pase— justamente para
// poder probarla sin báscula.
func parseArgs(args []string, out io.Writer) (Options, error) {
	def := DefaultTimings()
	o := Options{Timings: def, MaxGuided: 2}

	fs := flag.NewFlagSet("scale-probe", flag.ContinueOnError)
	fs.SetOutput(out)
	fs.StringVar(&o.Port, "puerto", "", "puerto COM a usar (ej. COM3). Por omisión se detecta solo.")
	fs.BoolVar(&o.Simulate, "simular", false, "ensayar sin báscula, contra un puerto simulado.")
	fs.BoolVar(&o.SimQuiet, "simular-mudo", false, "como -simular, pero la báscula solo responde a comandos.")
	fs.BoolVar(&o.ListOnly, "listar", false, "solo listar los puertos detectados y salir.")
	fs.BoolVar(&o.NoPause, "sin-pausa", false, "no esperar ENTER al terminar (para ejecución automática).")
	fs.IntVar(&o.MaxGuided, "config-guiadas", o.MaxGuided, "cuántas configuraciones se usan en la captura guiada.")
	fs.DurationVar(&o.Timings.PassiveListen, "escucha", def.PassiveListen, "escucha pasiva por configuración.")
	fs.DurationVar(&o.Timings.ProbeWait, "sondeo", def.ProbeWait, "espera de respuesta por cada sondeo activo.")
	fs.DurationVar(&o.Timings.ResidualRead, "residual", def.ResidualRead, "lectura corta previa a cada sondeo, para no perder bytes espontáneos.")
	fs.DurationVar(&o.Timings.GuidedWindow, "ventana", def.GuidedWindow, "ventana de captura por cada peso.")
	fs.IntVar(&o.Timings.WeightSteps, "pesos", def.WeightSteps, "cuántos pesos distintos se le piden al operario.")
	fs.DurationVar(&o.Timings.Budget, "presupuesto", def.Budget, "tope de tiempo del barrido completo.")
	rapido := fs.Bool("rapido", false, "ensayo veloz: reduce todas las esperas a la mitad.")

	fs.Usage = func() {
		fmt.Fprintf(out, "scale-probe %s — descubre el protocolo serie de la báscula.\n\n", toolVersion)
		fmt.Fprintf(out, "Uso normal: hacer doble clic. No hace falta ningún argumento.\n\n")
		fmt.Fprintf(out, "Opciones:\n")
		fs.PrintDefaults()
	}

	if err := fs.Parse(args); err != nil {
		return o, err
	}
	if rest := fs.Args(); len(rest) > 0 {
		return o, fmt.Errorf("argumento no reconocido: %q", rest[0])
	}
	if *rapido {
		o.Timings.PassiveListen /= 2
		o.Timings.ProbeWait /= 2
		o.Timings.ResidualRead /= 2
		o.Timings.GuidedWindow /= 2
	}
	if o.SimQuiet {
		o.Simulate = true
	}
	if o.MaxGuided < 1 {
		o.MaxGuided = 1
	}
	if o.Timings.WeightSteps < 1 {
		return o, fmt.Errorf("-pesos debe ser al menos 1 (y con 1 solo peso no se puede saber qué parte de la trama es el número)")
	}
	if o.Timings.PassiveListen <= 0 || o.Timings.ProbeWait <= 0 || o.Timings.GuidedWindow <= 0 || o.Timings.ResidualRead <= 0 {
		return o, fmt.Errorf("las esperas deben ser mayores que cero")
	}
	if o.Timings.Budget <= 0 {
		return o, fmt.Errorf("-presupuesto debe ser mayor que cero")
	}
	o.Timings.ProbeCount = len(DefaultProbes())
	return o, nil
}

func main() {
	utf8OK := enableUTF8Console()
	in := newInputGate(os.Stdin)

	var argErr bytes.Buffer
	opts, err := parseArgs(os.Args[1:], &argErr)
	ui := NewUI(os.Stdout, utf8OK, isTTY(os.Stdout), in)
	if err != nil {
		if argErr.Len() > 0 {
			fmt.Fprint(os.Stdout, argErr.String())
		}
		ui.Say("Error en los argumentos: %v", err)
		if !opts.NoPause {
			ui.Pause()
		}
		os.Exit(2)
	}

	code := run(ui, opts)
	if !opts.NoPause {
		ui.Pause()
	}
	os.Exit(code)
}

// GuidedResult es lo capturado en un paso de la fase guiada.
type GuidedResult struct {
	Step   int
	Label  string
	Config PortConfig
	Bytes  int
	Raw    []byte
}

// session lleva el estado de una ejecución.
type session struct {
	ui   *UI
	rep  *Reporter
	open opener
	opts Options

	started time.Time
	port    PortInfo
	plan    SweepPlan
	results []ConfigResult
	guided  []GuidedResult
	warn    []string
	// simWeight solo se usa en modo simulación, para variar el peso entre
	// pasos. En una ejecución real es nil.
	simWeight *float64

	// live cuenta bytes en vivo para la cuenta regresiva en pantalla.
	live atomic.Int64
}

func (s *session) warnf(format string, a ...any) {
	m := fmt.Sprintf(format, a...)
	s.warn = append(s.warn, m)
	if s.rep != nil {
		s.rep.Event(time.Now(), "AVISO: %s", m)
	}
}

func run(ui *UI, opts Options) int {
	now := time.Now()
	s := &session{ui: ui, opts: opts, started: now, open: openReal}

	if opts.Simulate {
		weight := 1.245
		s.simWeight = &weight
		s.open = newFakeOpener(!opts.SimQuiet, &weight)
	}

	ui.Banner("CAPTURA DE PROTOCOLO DE BÁSCULA")
	ui.Say("  Herramienta scale-probe %s", toolVersion)
	if opts.Simulate {
		ui.Say("  *** MODO SIMULACIÓN: no se usa báscula real. Es un ensayo. ***")
	}
	ui.Blank()

	// El archivo se abre ANTES de tocar el puerto: si algo sale mal más
	// adelante, el archivo ya existe y recoge el fallo.
	rep, err := NewReporter(now)
	s.rep = rep
	if err != nil {
		ui.Say("AVISO: no se pudo crear el archivo de resultados (%v).", err)
		ui.Say("       La captura continúa, pero SOLO se verá en esta ventana.")
		ui.Say("       Si esto pasa, tome una foto de la pantalla al terminar.")
		ui.Blank()
		s.warn = append(s.warn, fmt.Sprintf("no se pudo crear el archivo de resultados: %v", err))
	} else {
		ui.Say("  Archivo de resultados: %s", rep.Path())
		ui.Blank()
	}

	// 1. Detectar puertos.
	if !s.selectPort() {
		s.finish()
		return 1
	}
	if opts.ListOnly {
		s.finish()
		return 0
	}

	// 2. Planificar el barrido dentro del presupuesto de tiempo.
	s.plan = PlanSweep(DefaultConfigs(), opts.Timings)
	s.reportPlan()

	// 3. Instrucción inicial y barrido automático.
	ui.Step(
		"PASO 1 de 3 — Prepare la báscula.",
		"",
		"  1. Encienda la báscula y espere a que muestre ceros.",
		"  2. Ponga un objeto encima (medio kilo o más) y déjelo ahí.",
		"  3. NO toque la báscula durante el barrido.",
		"",
		fmt.Sprintf("El barrido dura unos %s.", short(s.plan.EstSweep)),
	)
	ui.Wait("Cuando esté listo presione ENTER.", 40*time.Second)
	ui.Blank()

	ui.Banner("PASO 2 de 3 — BARRIDO AUTOMÁTICO")
	for _, st := range s.plan.Steps {
		if !st.Included {
			s.rep.Linef("\n[omitida] %s — %s", st.Config.ID(), st.Reason)
			ui.Say("  %-14s omitida (no cabía en el tiempo)", st.Config.ID())
			continue
		}
		s.results = append(s.results, s.sweepConfig(st))
	}

	// 4. Captura guiada con pesos reales.
	s.guidedPhase()

	// 5. Cerrar el archivo con el resumen arriba.
	s.finish()
	return 0
}

// selectPort detecta y elige el puerto. Devuelve false si no hay ninguno.
func (s *session) selectPort() bool {
	ui := s.ui
	var ports []PortInfo
	var err error
	if s.opts.Simulate {
		ports = fakePorts()
	} else {
		ports, err = ListPorts()
	}
	if err != nil {
		s.warnf("falló la detección de puertos: %v", err)
		ui.Say("AVISO: falló la detección de puertos (%v).", err)
	}

	s.rep.Section("PUERTOS DETECTADOS")
	if len(ports) == 0 {
		s.rep.Line("Ninguno.")
	}
	for _, p := range ports {
		s.rep.Linef("  %-8s %s  (USB=%v VID=%s PID=%s)", p.Name, p.Description, p.IsUSB, p.VID, p.PID)
	}

	// Si se pidió un puerto a mano, se respeta aunque no aparezca en la lista:
	// la enumeración puede fallar y el puerto existir igual.
	if s.opts.Port != "" {
		for _, p := range ports {
			if equalFold(p.Name, s.opts.Port) {
				s.port = p
				ui.Say("Puerto elegido por parámetro: %s — %s", p.Name, p.Description)
				return true
			}
		}
		s.port = PortInfo{Name: s.opts.Port, Description: "(indicado a mano, no apareció en la lista)"}
		ui.Say("Puerto indicado a mano: %s (no apareció en la lista; se intenta igual)", s.opts.Port)
		return true
	}

	switch len(ports) {
	case 0:
		ui.Blank()
		ui.Banner("NO SE ENCONTRÓ NINGÚN PUERTO COM")
		ui.Blank()
		ui.Say("El computador no ve la báscula. Casi siempre es una de estas tres cosas:")
		ui.Blank()
		ui.Say("  1. FALTA EL DRIVER. La báscula usa un chip CH340 y necesita el")
		ui.Say("     driver CH341SER. Sin él, Windows no crea el puerto COM.")
		ui.Say("     Instálelo (SETUP.EXE, botón derecho, 'Ejecutar como administrador'),")
		ui.Say("     REINICIE el computador y vuelva a ejecutar este programa.")
		ui.Blank()
		ui.Say("  2. EL CABLE. Pruebe otro puerto USB y otro cable. Debe ser un cable")
		ui.Say("     de datos, no uno de solo carga.")
		ui.Blank()
		ui.Say("  3. LA BÁSCULA ESTÁ APAGADA. Enciéndala y espere a que muestre ceros.")
		ui.Blank()
		ui.Say("Para comprobarlo: abra el Administrador de dispositivos y busque")
		ui.Say("'Puertos (COM y LPT)'. Ahí debería aparecer 'USB-SERIAL CH340 (COMx)'.")
		ui.Blank()
		s.warnf("no se detectó ningún puerto COM; probable falta del driver CH341SER")
		return false

	case 1:
		s.port = ports[0]
		ui.Say("Se encontró un solo puerto: %s — %s", s.port.Name, s.port.Description)
		return true

	default:
		ui.Blank()
		ui.Say("Se encontraron varios puertos:")
		suggested := 0
		for i, p := range ports {
			mark := " "
			if p.LooksLikeCH340 {
				mark = ">"
				if suggested == 0 {
					suggested = i + 1
				}
			}
			ui.Say(" %s %d) %-8s %s", mark, i+1, p.Name, p.Description)
		}
		ui.Blank()
		if suggested == 0 {
			// Ninguno parece la báscula: no se puede anunciar una marca que no
			// está, o el operario la busca y no la encuentra.
			suggested = 1
			ui.Say("Ninguno parece la báscula. Si sabe cuál es, escriba su número;")
			ui.Say("si no, pruebe con el %d y, si no funciona, repita con otro.", suggested)
		} else {
			ui.Say("El marcado con > es el que parece la báscula (chip CH340).")
		}
		ans := ui.Ask(fmt.Sprintf("Escriba el número y presione ENTER [%d]: ", suggested), "", 45*time.Second)
		idx := suggested
		if ans != "" {
			var n int
			if _, err := fmt.Sscanf(ans, "%d", &n); err == nil && n >= 1 && n <= len(ports) {
				idx = n
			} else {
				ui.Say("No se entendió %q; se usa la opción %d.", ans, suggested)
			}
		}
		s.port = ports[idx-1]
		ui.Say("Puerto elegido: %s — %s", s.port.Name, s.port.Description)
		return true
	}
}

func (s *session) reportPlan() {
	p := s.plan
	s.rep.Section("PLAN DEL BARRIDO")
	s.rep.Linef("Puerto: %s — %s", s.port.Name, s.port.Description)
	s.rep.Linef("Presupuesto de tiempo: %s", short(p.Budget))
	s.rep.Linef("Estimado: barrido %s + captura guiada %s = %s",
		short(p.EstSweep), short(p.EstGuided), short(p.EstTotal))
	if p.OverBudget {
		s.rep.Line("AVISO: el plan no cabe en el presupuesto; se ejecuta igual porque")
		s.rep.Line("       quedarse sin datos es peor que pasarse de tiempo.")
	}
	for _, n := range p.Notes {
		s.rep.Linef("Nota: %s", n)
	}
	s.rep.Line("")
	for i, st := range p.Steps {
		state := "OMITIDA"
		if st.Included && st.Probes {
			state = "completa"
		} else if st.Included {
			state = "solo escucha"
		}
		s.rep.Linef("  %d. %-14s %-13s %-7s  %s", i+1, st.Config.ID(), state, short(st.Est), st.Config.Why)
	}
	s.ui.Say("Plan: %d configuraciones, estimado %s (tope %s).",
		len(p.IncludedConfigs()), short(p.EstTotal), short(p.Budget))
}

// sweepConfig ejecuta una configuración completa. NUNCA propaga un error: lo
// registra y devuelve el resultado parcial.
func (s *session) sweepConfig(st ConfigPlan) ConfigResult {
	cfg := st.Config
	id := cfg.ID()
	res := ConfigResult{Config: cfg, RespondedTo: map[string]int{}}

	s.rep.Section("CONFIGURACIÓN " + id)
	s.rep.Linef("Motivo: %s", cfg.Why)
	s.ui.Say("  %-14s ...", id)

	p, err := s.open(s.port.Name, cfg)
	if err != nil {
		res.OpenError = err.Error()
		s.rep.Event(time.Now(), "ERROR al abrir %s en %s: %v", s.port.Name, id, err)
		s.ui.Say("  %-14s no se pudo abrir el puerto (se continúa)", id)
		return res
	}
	defer p.Close()

	// probeKey vacío significa que los bytes llegaron sin estímulo: son
	// espontáneos. Si trae el identificador del sondeo, se le atribuyen a ese
	// sondeo, y ESA atribución es el dato más valioso de toda la captura: dice
	// con qué comando se le saca el peso a la báscula.
	record := func(stimulus, probeKey string) func(time.Time, []byte) {
		return func(at time.Time, b []byte) {
			res.Bytes += len(b)
			res.Reads++
			res.Raw = append(res.Raw, b...)
			if probeKey == "" {
				res.Passive += len(b)
			} else {
				res.RespondedTo[probeKey] += len(b)
			}
			s.live.Add(int64(len(b)))
			s.rep.RX(at, id, stimulus, b)
		}
	}

	// Escucha pasiva: sin enviar nada. Los bytes que lleguen aquí prueban que
	// la báscula transmite sola, que es el mejor caso posible.
	s.rep.Event(time.Now(), "ESCUCHA PASIVA %s (no se envía nada)", short(s.opts.Timings.PassiveListen))
	if err := listen(p, s.opts.Timings.PassiveListen, record("escucha pasiva", "")); err != nil {
		s.rep.Event(time.Now(), "ERROR de lectura en escucha pasiva: %v", err)
	}

	if st.Probes {
		for _, pr := range DefaultProbes() {
			// Antes de cada sondeo se hace una lectura corta para recoger lo
			// que haya llegado por su cuenta. NO se vacía el buffer: descartar
			// bytes es exactamente lo que no se puede hacer aquí. Esos bytes
			// cuentan como espontáneos, no como respuesta al sondeo siguiente.
			_ = listen(p, s.opts.Timings.ResidualRead, record("residual previo a "+pr.ID, ""))

			s.rep.Event(time.Now(), "SONDEO %s -> envía %s   (%s)", pr.ID, Escaped(pr.Bytes), pr.Why)
			if _, err := p.Write(pr.Bytes); err != nil {
				s.rep.Event(time.Now(), "ERROR al enviar el sondeo %s: %v", pr.ID, err)
				continue
			}
			if err := listen(p, s.opts.Timings.ProbeWait, record("sondeo "+pr.ID, pr.ID)); err != nil {
				s.rep.Event(time.Now(), "ERROR de lectura tras el sondeo %s: %v", pr.ID, err)
			}
		}
	} else {
		s.rep.Line("(sondeos activos omitidos: no cabían en el presupuesto de tiempo)")
	}

	if res.Bytes > 0 {
		s.ui.Say("  %-14s RESPONDIÓ — %d bytes", id, res.Bytes)
	} else {
		s.ui.Say("  %-14s sin respuesta", id)
	}
	s.rep.Linef("Total en %s: %d bytes (%d pasivos)", id, res.Bytes, res.Passive)
	return res
}

// guidedPhase pide pesos reales al operario.
//
// Es la fase decisiva. En el barrido la báscula puede estar callada porque el
// formato configurado solo transmite al presionar una tecla; aquí es donde el
// operario la presiona. Y se piden DOS pesos distintos a propósito: con uno
// solo no hay forma de saber qué parte de la trama es el número.
func (s *session) guidedPhase() {
	targets := GuidedTargets(s.results, s.plan.IncludedConfigs(), s.opts.MaxGuided)
	if len(targets) == 0 {
		s.warnf("no había ninguna configuración disponible para la captura guiada")
		return
	}

	s.ui.Blank()
	s.ui.Banner("PASO 3 de 3 — CAPTURA CON PESOS REALES")
	anyLive := false
	for _, r := range s.results {
		if r.Responded() {
			anyLive = true
		}
	}
	if anyLive {
		s.ui.Say("La báscula respondió en el barrido. Ahora se confirma con pesos reales.")
	} else {
		s.ui.Say("La báscula no respondió sola. Ahora hay que ayudarla:")
		s.ui.Say("es muy probable que solo transmita al presionar una tecla.")
	}

	// La instruccion es larga porque el operario no es técnico; la etiqueta es
	// corta porque va en una tabla del resumen.
	steps := []struct{ instruction, short string }{
		{"un objeto PESADO (medio kilo o más)", "peso A"},
		{"un objeto DISTINTO, que pese claramente otra cosa", "peso B"},
		{"un tercer objeto, distinto de los dos anteriores", "peso C"},
	}
	perTarget := s.plan.GuidedWindow / time.Duration(len(targets))

	for step := 1; step <= s.plan.WeightSteps; step++ {
		sp := steps[len(steps)-1]
		if step-1 < len(steps) {
			sp = steps[step-1]
		}
		// En simulación se cambia el peso entre pasos para que el ensayo
		// recorra el mismo camino que el día real, incluida la comprobación
		// de que la trama cambia al cambiar el peso.
		if s.simWeight != nil {
			*s.simWeight += 0.615
		}
		s.ui.Step(
			fmt.Sprintf("PESO %d de %d", step, s.plan.WeightSteps),
			"",
			"  1. Ponga sobre la báscula "+sp.instruction+".",
			"  2. Espere a que el número se quede quieto.",
			"  3. Presione la tecla de TRANSMITIR (o IMPRIMIR / PRINT)",
			"     VARIAS VECES, una cada 3 segundos, hasta que se acabe el tiempo.",
			"",
			fmt.Sprintf("Tiene %s. Empieza al presionar ENTER.", short(s.plan.GuidedWindow)),
		)
		s.ui.Wait("Presione ENTER para empezar.", 30*time.Second)

		for _, cfg := range targets {
			s.guidedCapture(step, sp.short, cfg, perTarget)
		}
	}
}

// guidedCapture escucha una ventana con el operario interviniendo.
//
// La ventana se parte en dos mitades a propósito:
//   - La primera es escucha PURA: no se envía nada, así que todo byte que
//     llegue es indiscutiblemente iniciado por la báscula.
//   - En la segunda se manda `W` cada 2 s, por si el equipo está en el formato
//     por comando y nunca va a transmitir solo.
//
// Así una sola ventana cubre los dos modos posibles sin ambigüedad sobre qué
// causó qué: cada bloque queda marcado con su estímulo en el volcado.
func (s *session) guidedCapture(step int, label string, cfg PortConfig, window time.Duration) {
	id := cfg.ID()
	s.rep.Section(fmt.Sprintf("CAPTURA GUIADA — peso %d (%s) — %s", step, label, id))

	res := GuidedResult{Step: step, Label: label, Config: cfg}
	p, err := s.open(s.port.Name, cfg)
	if err != nil {
		s.rep.Event(time.Now(), "ERROR al abrir %s en %s: %v", s.port.Name, id, err)
		s.ui.Say("  No se pudo abrir el puerto en %s (se continúa).", id)
		s.guided = append(s.guided, res)
		return
	}
	defer p.Close()

	record := func(stimulus string) func(time.Time, []byte) {
		return func(at time.Time, b []byte) {
			res.Bytes += len(b)
			res.Raw = append(res.Raw, b...)
			s.live.Add(int64(len(b)))
			s.rep.RX(at, id, stimulus, b)
		}
	}

	s.ui.Say("  Escuchando en %s durante %s ...", id, short(window))
	stop := make(chan struct{})
	base := s.live.Load()
	go s.ui.Countdown(window, func() int { return int(s.live.Load() - base) }, stop)

	half := window / 2

	// Primera mitad: escucha pura.
	s.rep.Event(time.Now(), "ESCUCHA PURA %s (no se envía nada)", short(half))
	if err := listen(p, half, record("guiada, escucha pura")); err != nil {
		s.rep.Event(time.Now(), "ERROR de lectura: %v", err)
	}

	// Segunda mitad: se insiste con `W`, el comando del formato 9.
	deadline := time.Now().Add(window - half)
	for time.Now().Before(deadline) {
		s.rep.Event(time.Now(), "SONDEO W (insistencia) -> envía W")
		if _, err := p.Write([]byte("W")); err != nil {
			s.rep.Event(time.Now(), "ERROR al enviar W: %v", err)
			break
		}
		slice := 2 * time.Second
		if r := time.Until(deadline); r < slice {
			slice = r
		}
		if slice <= 0 {
			break
		}
		if err := listen(p, slice, record("guiada, tras enviar W")); err != nil {
			s.rep.Event(time.Now(), "ERROR de lectura: %v", err)
			break
		}
	}

	close(stop)
	time.Sleep(60 * time.Millisecond) // deja que la cuenta regresiva borre su línea

	if res.Bytes > 0 {
		s.ui.Say("  %-14s %d bytes capturados.", id, res.Bytes)
	} else {
		s.ui.Say("  %-14s no llegó nada.", id)
	}
	s.rep.Linef("Total capturado: %d bytes", res.Bytes)
	s.guided = append(s.guided, res)
}

func (s *session) finish() {
	sum := RunSummary{
		Started:  s.started,
		Ended:    time.Now(),
		Port:     s.port,
		Plan:     s.plan,
		Results:  s.results,
		Guided:   s.guided,
		Warnings: s.warn,
	}
	text := sum.Render()

	s.ui.Blank()
	s.ui.Banner("RESULTADO")
	s.ui.Blank()
	for _, l := range strings.Split(sum.Headline(), "\n") {
		s.ui.Say("%s", l)
	}

	if s.rep == nil || s.rep.Degraded() {
		s.ui.Blank()
		s.ui.Say("NO SE PUDO ESCRIBIR EL ARCHIVO. Copie o fotografíe esta pantalla:")
		s.ui.Blank()
		fmt.Fprintln(os.Stdout, text)
		return
	}

	path, err := s.rep.Finish(text)
	s.ui.Blank()
	if err != nil {
		s.ui.Say("AVISO: no se pudo reordenar el archivo (%v),", err)
		s.ui.Say("       pero el archivo EXISTE y tiene todos los datos.")
	}
	s.ui.Say("Archivo de resultados guardado en:")
	s.ui.Blank()
	s.ui.Say("    %s", path)
	s.ui.Blank()
	s.ui.Say("ENVÍE ESE ARCHIVO COMPLETO por WhatsApp o correo. No lo recorte.")
}

// RunSummary es todo lo necesario para redactar el resumen. Se separa del resto
// para poder probar la redacción sin ejecutar nada.
type RunSummary struct {
	Started  time.Time
	Ended    time.Time
	Port     PortInfo
	Plan     SweepPlan
	Results  []ConfigResult
	Guided   []GuidedResult
	Warnings []string
}

// AllRaw junta todo lo capturado, del barrido y de la fase guiada.
func (r RunSummary) AllRaw() []byte {
	var out []byte
	for _, c := range r.Results {
		out = append(out, c.Raw...)
	}
	for _, g := range r.Guided {
		out = append(out, g.Raw...)
	}
	return out
}

// TotalBytes es cuántos bytes se capturaron en total.
func (r RunSummary) TotalBytes() int { return len(r.AllRaw()) }

// TotalFrames cuenta las tramas candidatas capturadas.
func (r RunSummary) TotalFrames() int {
	n := 0
	for _, f := range GuessFrames(r.AllRaw()) {
		n += f.Count
	}
	return n
}

// Responsive lista las configuraciones que produjeron bytes, de más a menos.
func (r RunSummary) Responsive() []ConfigResult {
	var live []ConfigResult
	for _, c := range r.Results {
		if c.Responded() {
			live = append(live, c)
		}
	}
	sort.SliceStable(live, func(i, j int) bool { return masCreible(live[i], live[j]) })
	return live
}

// Headline es el veredicto en dos o tres líneas, para la pantalla.
func (r RunSummary) Headline() string {
	total := r.TotalBytes()
	if total == 0 {
		return "NO SE RECIBIÓ NINGÚN BYTE de la báscula.\n" +
			"El archivo se generó igual y sirve: deja constancia de todo lo que se probó.\n" +
			"ENVÍELO de todas formas."
	}
	live := r.Responsive()
	var b strings.Builder
	fmt.Fprintf(&b, "SE CAPTURARON %d bytes y %d tramas.\n", total, r.TotalFrames())
	if len(live) > 0 {
		fmt.Fprintf(&b, "La báscula habla en: %s\n", live[0].Config.ID())
	}
	b.WriteString("La captura sirvió.")
	return b.String()
}

// Render redacta el resumen que va al principio del archivo.
func (r RunSummary) Render() string {
	var b strings.Builder
	f := func(format string, a ...any) { fmt.Fprintf(&b, format+"\n", a...) }

	f("")
	f("Herramienta   : scale-probe %s", toolVersion)
	f("Inicio        : %s", r.Started.Format("2006-01-02 15:04:05 -07:00"))
	f("Fin           : %s", r.Ended.Format("2006-01-02 15:04:05 -07:00"))
	f("Duración real : %s", short(r.Ended.Sub(r.Started)))
	f("Puerto        : %s — %s", r.Port.Name, r.Port.Description)
	if r.Port.VID != "" {
		f("Identificador : VID=%s PID=%s USB=%v", r.Port.VID, r.Port.PID, r.Port.IsUSB)
	}
	f("")
	f("VEREDICTO")
	for _, l := range strings.Split(r.Headline(), "\n") {
		f("  %s", l)
	}

	f("")
	f("CONFIGURACIONES BARRIDAS")
	if len(r.Results) == 0 {
		f("  (ninguna: no se llegó a barrer)")
	}
	f("  %-14s %8s %8s  %s", "config", "bytes", "pasivos", "detalle")
	for _, c := range r.Results {
		detail := "sin respuesta"
		if c.OpenError != "" {
			detail = "NO ABRIÓ: " + c.OpenError
		} else if c.Responded() {
			var hits []string
			for _, pr := range DefaultProbes() {
				if n := c.RespondedTo[pr.ID]; n > 0 {
					hits = append(hits, fmt.Sprintf("%s=%dB", pr.ID, n))
				}
			}
			if len(hits) > 0 {
				detail = "respondió a: " + strings.Join(hits, ", ")
			} else {
				detail = "transmite sola (sin necesidad de sondeo)"
			}
		}
		f("  %-14s %8d %8d  %s", c.Config.ID(), c.Bytes, c.Passive, detail)
	}

	f("")
	f("CAPTURA GUIADA (pesos reales puestos por el operario)")
	if len(r.Guided) == 0 {
		f("  (no se ejecutó)")
	}
	for _, g := range r.Guided {
		f("  peso %d (%s) en %-14s -> %d bytes", g.Step, g.Label, g.Config.ID(), g.Bytes)
	}

	f("")
	f("TRAMAS CANDIDATAS (agrupadas; el volcado completo manda sobre esto)")
	frames := GuessFrames(r.AllRaw())
	if len(frames) == 0 {
		f("  (ninguna)")
	}
	for i, fr := range frames {
		if i >= 20 {
			f("  ... y %d formas distintas más, todas en el volcado.", len(frames)-20)
			break
		}
		f("  x%-4d %s", fr.Count, fr.Text)
	}
	if len(frames) == 1 {
		f("")
		f("  ATENCIÓN: todas las tramas son iguales. Si se pusieron pesos distintos")
		f("  y aun así la trama no cambia, la báscula no está mandando el peso o")
		f("  no llegó a estabilizarse. Hay que repetir la captura.")
	}

	if len(r.Warnings) > 0 {
		f("")
		f("AVISOS")
		for _, w := range r.Warnings {
			f("  - %s", w)
		}
	}

	f("")
	f("QUÉ HACER CON ESTE ARCHIVO")
	f("  Enviarlo completo. Contiene, para cada configuración y cada estímulo,")
	f("  todos los bytes recibidos en hexadecimal y en ASCII, con la hora exacta.")
	f("  Con eso se determina el formato de trama sin volver a necesitar la báscula.")
	return b.String()
}
