package main

import (
	"fmt"
	"sort"
	"time"
)

// Este archivo es logica pura: no abre puertos, no escribe archivos, no imprime.
// Todo lo que hay aqui se puede probar sin bascula, y por eso vive separado.

// Parity se declara aqui, con letras, en vez de reutilizar las constantes de
// go.bug.st/serial. Asi la tabla de configuraciones y el planificador no
// dependen de la libreria serie y las pruebas corren en cualquier plataforma.
type Parity byte

const (
	ParityNone Parity = 'N'
	ParityEven Parity = 'E'
	ParityOdd  Parity = 'O'
)

// PortConfig es una combinacion de parametros del puerto para probar.
type PortConfig struct {
	Baud     int
	DataBits int
	Parity   Parity
	StopBits int
	// Why explica en el reporte por que esta configuracion esta en la lista.
	// El analista que reciba el archivo no tiene por que saberlo de memoria.
	Why string
}

// ID devuelve la forma corta y estandar: "9600 8-N-1".
func (c PortConfig) ID() string {
	return fmt.Sprintf("%d %d-%c-%d", c.Baud, c.DataBits, c.Parity, c.StopBits)
}

// DefaultConfigs es la tabla de barrido, EN ORDEN DE PRIORIDAD.
//
// El orden importa: si el presupuesto de tiempo no alcanza para todas, se
// recortan las de abajo. La primera es la unica documentada por el fabricante
// para este equipo exacto, asi que nunca se recorta.
func DefaultConfigs() []PortConfig {
	return []PortConfig{
		{9600, 8, ParityNone, 1, "El del manual ROCHI RC-SERIE. Único parámetro confirmado para este equipo exacto."},
		{9600, 7, ParityEven, 1, "Formato F de la tabla POS-II (emulación CAS PD-II)."},
		{9600, 7, ParityOdd, 1, "Variante impar del anterior; algunos firmwares la usan por error de tabla."},
		{4800, 8, ParityNone, 1, "Velocidad alterna común en básculas de la misma gama."},
		{2400, 7, ParityNone, 1, "Herencia de básculas viejas; barata de descartar."},
		{19200, 8, ParityNone, 1, "Velocidad alta ocasional en firmwares recientes."},
	}
}

// Probe es un estimulo activo: unos bytes que se mandan al puerto para ver si
// la bascula contesta. Enviar bytes NO reprograma la bascula (§5.10 del diseno
// tecnico): reprogramarla exige el teclado fisico del equipo.
type Probe struct {
	ID    string
	Bytes []byte
	Why   string
}

// DefaultProbes son los disparadores conocidos de la familia, en orden de
// probabilidad segun el manual hermano ACS-268 / POS-2.
func DefaultProbes() []Probe {
	return []Probe{
		{"W", []byte("W"), "Formato 9 (Moresco por comando): el PC manda W, la báscula responde el peso."},
		{"W+CRLF", []byte("W\r\n"), "El mismo comando, terminado en línea. Hay firmwares que exigen el terminador."},
		{"$", []byte("$"), "Protocolo Dólar: el PC manda $, la báscula responde."},
		{"ENQ", []byte{0x05}, "ENQ (0x05), petición de datos del estándar serie clásico."},
		{"CR", []byte{0x0D}, "CR (0x0D) suelto; algunos equipos lo toman como 'mande el peso'."},
		{"P", []byte("P"), "Print: usado por básculas que emulan una impresora de tiquete."},
		{"S", []byte("S"), "Send: variante corta de petición de peso."},
		{"SI+CRLF", []byte("SI\r\n"), "MT-SICS (Mettler-Toledo). Barato de descartar y muy extendido."},
	}
}

// Timings agrupa todas las esperas del barrido. Son parametros, no constantes
// regadas por el codigo, justamente para poder calcular si el barrido cabe en
// los cinco minutos de acceso a la bascula.
type Timings struct {
	// PassiveListen es cuanto se escucha sin enviar nada, por configuracion.
	PassiveListen time.Duration
	// ProbeWait es cuanto se espera la respuesta de cada sondeo activo.
	ProbeWait time.Duration
	// ResidualRead es la lectura corta que se hace ANTES de cada sondeo para
	// recoger lo que haya llegado por su cuenta, sin vaciar el buffer.
	// Se cuenta en el presupuesto: son ProbeCount lecturas por configuracion y
	// olvidarlas costaba casi 6s de deriva sobre el barrido completo.
	ResidualRead time.Duration
	// PortOverhead cubre abrir, configurar y cerrar el puerto, mas reintentos.
	PortOverhead time.Duration
	// GuidedWindow es la ventana de captura de cada peso que pone el operario.
	GuidedWindow time.Duration
	// WeightSteps es cuantos pesos distintos se le piden al operario.
	// Con UNO SOLO no se puede saber que parte de la trama es el numero, por
	// eso el minimo util es dos.
	WeightSteps int
	// Budget es el tope duro. La bascula esta prestada cinco minutos; esto se
	// dimensiona por debajo, con margen para que el operario lea la pantalla.
	Budget time.Duration
	// ProbeCount es cuantos sondeos activos se envian por configuracion.
	ProbeCount int
}

// DefaultTimings son los valores calculados para caber holgadamente en el tope.
//
// Cuentas con estos valores, 6 configuraciones y 8 sondeos:
//
//	por configuracion = 3.5s pasiva + 8 x (1.2s sondeo + 0.12s residual) + 0.5s puerto = 14.56s
//	barrido           = 6 x 14.56s                                                     = 87.4s
//	captura guiada    = 2 pesos x 30s                                                  = 60.0s
//	TOTAL instrumental                                                                 = 147.4s (2m27s)
//
// Medido de punta a punta contra el puerto simulado en el peor caso (bascula
// muda, todas las ventanas agotadas): 147.4s reales. La estimacion y el
// cronometro coinciden, que es la unica forma de confiar en el presupuesto.
//
// Quedan ~93s de los 240s de tope para que el operario lea las pantallas y
// reaccione, y ~153s de los 300s reales de acceso a la bascula.
func DefaultTimings() Timings {
	return Timings{
		PassiveListen: 3500 * time.Millisecond,
		ProbeWait:     1200 * time.Millisecond,
		ResidualRead:  120 * time.Millisecond,
		PortOverhead:  500 * time.Millisecond,
		GuidedWindow:  30 * time.Second,
		WeightSteps:   2,
		Budget:        4 * time.Minute,
		ProbeCount:    len(DefaultProbes()),
	}
}

// minGuidedWindow es lo minimo que tiene sentido darle a un operario para
// poner un peso y presionar transmitir. Por debajo de esto la ventana no
// captura nada y es mejor gastar el tiempo en el barrido.
const minGuidedWindow = 8 * time.Second

// ConfigPlan es la decision tomada sobre una configuracion del barrido.
type ConfigPlan struct {
	Config PortConfig
	// Included false significa que no cabia en el presupuesto.
	Included bool
	// Probes false significa que solo se hace escucha pasiva: cupo recortado.
	Probes bool
	Est    time.Duration
	Reason string
}

// SweepPlan es el barrido completo ya ajustado al presupuesto.
type SweepPlan struct {
	Steps        []ConfigPlan
	GuidedWindow time.Duration
	WeightSteps  int
	EstSweep     time.Duration
	EstGuided    time.Duration
	EstTotal     time.Duration
	Budget       time.Duration
	// OverBudget avisa que ni siquiera el minimo indispensable cabe. NO es
	// motivo para abortar: se corre igual y se deja constancia en el reporte.
	OverBudget bool
	Notes      []string
}

func costFull(t Timings) time.Duration {
	return t.PassiveListen +
		time.Duration(t.ProbeCount)*(t.ProbeWait+t.ResidualRead) +
		t.PortOverhead
}

func costPassive(t Timings) time.Duration {
	return t.PassiveListen + t.PortOverhead
}

// PlanSweep decide que cabe en el presupuesto y que se recorta.
//
// La regla de prioridad es la del diseno: primero 9600 8-N-1 completa (escucha
// + sondeos), despues la captura guiada con el operario, y solo con lo que
// sobre, el resto de configuraciones. Nunca devuelve un plan vacio.
func PlanSweep(configs []PortConfig, t Timings) SweepPlan {
	plan := SweepPlan{
		GuidedWindow: t.GuidedWindow,
		WeightSteps:  t.WeightSteps,
		Budget:       t.Budget,
	}
	if len(configs) == 0 {
		plan.Notes = append(plan.Notes, "No hay configuraciones que barrer.")
		return plan
	}
	if plan.WeightSteps < 1 {
		plan.WeightSteps = 1
		plan.Notes = append(plan.Notes,
			"Se pidio menos de un peso; se fuerza a 1. Con un solo peso no se puede "+
				"saber que parte de la trama es el numero.")
	}

	full := costFull(t)
	passive := costPassive(t)

	// 1. La primera configuracion es intocable: es la unica documentada.
	plan.Steps = append(plan.Steps, ConfigPlan{
		Config:   configs[0],
		Included: true,
		Probes:   true,
		Est:      full,
		Reason:   "Prioridad maxima: parametro confirmado por el manual del fabricante.",
	})
	spent := full

	// 2. La captura guiada. Es la unica fase donde el operario interviene, asi
	//    que se reserva antes que las configuraciones restantes.
	guided := plan.GuidedWindow * time.Duration(plan.WeightSteps)
	if spent+guided > t.Budget {
		avail := t.Budget - spent
		if avail < minGuidedWindow*time.Duration(plan.WeightSteps) {
			// No cabe ni el minimo. Se deja el minimo igual y se avisa: es
			// preferible pasarse del presupuesto a quedarse sin la fase que
			// captura los pesos reales.
			plan.GuidedWindow = minGuidedWindow
			plan.OverBudget = true
			plan.Notes = append(plan.Notes, fmt.Sprintf(
				"El presupuesto de %s no alcanza para la captura guiada. Se deja en el "+
					"minimo de %s por peso y se acepta pasarse: sin esa fase el archivo "+
					"no sirve.", short(t.Budget), short(minGuidedWindow)))
		} else {
			plan.GuidedWindow = avail / time.Duration(plan.WeightSteps)
			plan.Notes = append(plan.Notes, fmt.Sprintf(
				"Ventana guiada recortada a %s por peso para caber en el presupuesto de %s.",
				short(plan.GuidedWindow), short(t.Budget)))
		}
		guided = plan.GuidedWindow * time.Duration(plan.WeightSteps)
	}
	spent += guided

	// 3. El resto, en orden de prioridad, con lo que quede.
	for _, c := range configs[1:] {
		switch {
		case spent+full <= t.Budget:
			plan.Steps = append(plan.Steps, ConfigPlan{
				Config: c, Included: true, Probes: true, Est: full,
				Reason: "Cabe completa: escucha pasiva mas sondeos activos.",
			})
			spent += full
		case spent+passive <= t.Budget:
			plan.Steps = append(plan.Steps, ConfigPlan{
				Config: c, Included: true, Probes: false, Est: passive,
				Reason: "Recortada a escucha pasiva: no habia tiempo para los sondeos.",
			})
			spent += passive
		default:
			plan.Steps = append(plan.Steps, ConfigPlan{
				Config: c, Included: false, Probes: false, Est: 0,
				Reason: "Descartada: no cabe en el presupuesto de tiempo.",
			})
		}
	}

	for _, s := range plan.Steps {
		plan.EstSweep += s.Est
	}
	plan.EstGuided = plan.GuidedWindow * time.Duration(plan.WeightSteps)
	plan.EstTotal = plan.EstSweep + plan.EstGuided
	if plan.EstTotal > t.Budget {
		plan.OverBudget = true
	}
	return plan
}

// IncludedConfigs devuelve solo las configuraciones que si se van a barrer.
func (p SweepPlan) IncludedConfigs() []PortConfig {
	var out []PortConfig
	for _, s := range p.Steps {
		if s.Included {
			out = append(out, s.Config)
		}
	}
	return out
}

// ConfigResult acumula lo que se recibio en una configuracion.
type ConfigResult struct {
	Config PortConfig
	// Bytes recibidos en total, por cualquier via.
	Bytes int
	// Reads es cuantas lecturas distintas trajeron bytes.
	Reads int
	// Passive es cuantos bytes llegaron sin haber enviado nada. Son los que
	// mas valen: prueban que la bascula transmite sola.
	Passive int
	// RespondedTo lleva, por sondeo, cuantos bytes contesto.
	RespondedTo map[string]int
	// OpenError guarda el fallo de apertura, si lo hubo. Una configuracion que
	// no abre se registra y se sigue: nunca aborta el programa.
	OpenError string
	// Raw es todo lo capturado en esta configuracion, para adivinar tramas.
	Raw []byte
}

// Responded indica si la configuracion produjo algun byte.
func (r ConfigResult) Responded() bool { return r.Bytes > 0 }

// GuidedTargets elige en que configuraciones se hace la captura guiada.
//
// Si el barrido encontro configuraciones que responden, se usan esas: son las
// que hablan. Si no encontro ninguna, se cae a las primeras por prioridad,
// porque la captura guiada es la unica fase donde el operario presiona la
// tecla de transmitir y puede ser lo unico que despierte a la bascula.
func GuidedTargets(results []ConfigResult, fallback []PortConfig, max int) []PortConfig {
	if max < 1 {
		max = 1
	}
	var live []ConfigResult
	for _, r := range results {
		if r.Responded() {
			live = append(live, r)
		}
	}
	if len(live) > 0 {
		// Estable: mas bytes primero; a igualdad de bytes, el orden de barrido,
		// que ya es el orden de prioridad.
		sort.SliceStable(live, func(i, j int) bool { return live[i].Bytes > live[j].Bytes })
		var out []PortConfig
		for i := 0; i < len(live) && i < max; i++ {
			out = append(out, live[i].Config)
		}
		return out
	}
	var out []PortConfig
	for i := 0; i < len(fallback) && i < max; i++ {
		out = append(out, fallback[i])
	}
	return out
}

// short formatea una duracion para leerla de un vistazo: "13.6s", "2m22s".
func short(d time.Duration) string {
	if d < time.Minute {
		return fmt.Sprintf("%.1fs", d.Seconds())
	}
	m := int(d / time.Minute)
	s := d - time.Duration(m)*time.Minute
	return fmt.Sprintf("%dm%02ds", m, int(s.Seconds()))
}
