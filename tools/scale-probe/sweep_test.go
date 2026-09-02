package main

import (
	"bytes"
	"testing"
	"time"
)

func TestPortConfigID(t *testing.T) {
	cases := []struct {
		c    PortConfig
		want string
	}{
		{PortConfig{9600, 8, ParityNone, 1, ""}, "9600 8-N-1"},
		{PortConfig{9600, 7, ParityEven, 1, ""}, "9600 7-E-1"},
		{PortConfig{2400, 7, ParityOdd, 2, ""}, "2400 7-O-2"},
	}
	for _, c := range cases {
		if got := c.c.ID(); got != c.want {
			t.Errorf("got %q, want %q", got, c.want)
		}
	}
}

// TestDefaultConfigsStartsWithTheManualOne fija la única garantía que el
// fabricante nos da: 9600 8-N-1 es el parámetro documentado para este equipo,
// así que tiene que ir primero y no puede recortarse nunca.
func TestDefaultConfigsStartsWithTheManualOne(t *testing.T) {
	got := DefaultConfigs()
	if len(got) < 6 {
		t.Fatalf("se esperaban al menos 6 configuraciones, hay %d", len(got))
	}
	if got[0].ID() != "9600 8-N-1" {
		t.Errorf("la primera debe ser la del manual, es %q", got[0].ID())
	}
	// Las seis exigidas por el requerimiento, en orden.
	want := []string{"9600 8-N-1", "9600 7-E-1", "9600 7-O-1", "4800 8-N-1", "2400 7-N-1", "19200 8-N-1"}
	for i, w := range want {
		if got[i].ID() != w {
			t.Errorf("posición %d: got %q, want %q", i, got[i].ID(), w)
		}
	}
	for i, c := range got {
		if c.Why == "" {
			t.Errorf("la configuración %d no explica por qué está en la lista", i)
		}
	}
}

// TestDefaultProbesCoversRequiredStimuli comprueba que están los seis
// disparadores exigidos. Si alguien recorta la lista para ganar tiempo, esto
// lo detiene: no hay segunda oportunidad de sondear la báscula.
func TestDefaultProbesCoversRequiredStimuli(t *testing.T) {
	want := map[string][]byte{
		"W":   []byte("W"),
		"$":   []byte("$"),
		"ENQ": {0x05},
		"CR":  {0x0D},
		"P":   []byte("P"),
		"S":   []byte("S"),
	}
	got := map[string][]byte{}
	for _, p := range DefaultProbes() {
		got[p.ID] = p.Bytes
		if p.Why == "" {
			t.Errorf("el sondeo %q no explica para qué sirve", p.ID)
		}
	}
	for id, bytes := range want {
		g, ok := got[id]
		if !ok {
			t.Errorf("falta el sondeo obligatorio %q", id)
			continue
		}
		if string(g) != string(bytes) {
			t.Errorf("sondeo %q envía %x, se esperaba %x", id, g, bytes)
		}
	}
}

// TestDefaultTimingsFitTheBudget es la prueba que respalda la afirmación de que
// el barrido cabe en menos de cuatro minutos. Si alguien sube una espera, aquí
// se entera.
func TestDefaultTimingsFitTheBudget(t *testing.T) {
	tm := DefaultTimings()
	plan := PlanSweep(DefaultConfigs(), tm)

	if plan.OverBudget {
		t.Errorf("el plan por omisión no cabe en el presupuesto: %s > %s",
			short(plan.EstTotal), short(tm.Budget))
	}
	if plan.EstTotal > 4*time.Minute {
		t.Errorf("el barrido completo dura %s, debe ser menos de 4 minutos", short(plan.EstTotal))
	}
	if n := len(plan.IncludedConfigs()); n != len(DefaultConfigs()) {
		t.Errorf("con los tiempos por omisión deben caber las %d configuraciones, cupieron %d",
			len(DefaultConfigs()), n)
	}
	for _, s := range plan.Steps {
		if !s.Probes {
			t.Errorf("con los tiempos por omisión %s debería llevar sondeos", s.Config.ID())
		}
	}
	if plan.WeightSteps < 2 {
		t.Errorf("hay que pedir al menos dos pesos: con uno solo no se puede saber "+
			"qué parte de la trama es el número (WeightSteps=%d)", plan.WeightSteps)
	}
	// Margen real para el operario dentro de los cinco minutos de acceso.
	if margin := 5*time.Minute - plan.EstTotal; margin < time.Minute {
		t.Errorf("solo quedan %s de margen sobre los 5 minutos de acceso", short(margin))
	}
	t.Logf("barrido %s + guiada %s = %s (tope %s)",
		short(plan.EstSweep), short(plan.EstGuided), short(plan.EstTotal), short(tm.Budget))
}

func TestPlanSweepDropsLowPriorityConfigsWhenTight(t *testing.T) {
	tm := DefaultTimings()
	tm.Budget = 100 * time.Second
	plan := PlanSweep(DefaultConfigs(), tm)

	// La primera nunca se toca.
	if !plan.Steps[0].Included || !plan.Steps[0].Probes {
		t.Fatalf("la configuración del manual se recortó: %+v", plan.Steps[0])
	}
	// La ventana guiada se reserva antes que el resto de configuraciones.
	if plan.GuidedWindow != tm.GuidedWindow {
		t.Errorf("no debía recortarse la ventana guiada, quedó en %s", short(plan.GuidedWindow))
	}
	if plan.EstTotal > tm.Budget {
		t.Errorf("el plan se pasó: %s > %s", short(plan.EstTotal), short(tm.Budget))
	}
	var full, trimmed, dropped int
	for _, s := range plan.Steps {
		switch {
		case s.Included && s.Probes:
			full++
		case s.Included:
			trimmed++
		default:
			dropped++
		}
	}
	if dropped == 0 {
		t.Errorf("con 100s debía descartarse alguna configuración; full=%d trimmed=%d dropped=%d",
			full, trimmed, dropped)
	}
	if trimmed == 0 {
		t.Errorf("con 100s debía recortarse alguna a solo escucha pasiva")
	}
	// Todo paso descartado tiene que explicar por qué, para que quede en el archivo.
	for _, s := range plan.Steps {
		if s.Reason == "" {
			t.Errorf("el paso %s no explica la decisión", s.Config.ID())
		}
	}
}

func TestPlanSweepShrinksGuidedWindowBeforeGivingUp(t *testing.T) {
	tm := DefaultTimings()
	tm.Budget = 50 * time.Second
	plan := PlanSweep(DefaultConfigs(), tm)

	if plan.GuidedWindow >= tm.GuidedWindow {
		t.Errorf("la ventana guiada debía recortarse, quedó en %s", short(plan.GuidedWindow))
	}
	if plan.GuidedWindow < minGuidedWindow {
		t.Errorf("la ventana guiada quedó por debajo del mínimo útil: %s", short(plan.GuidedWindow))
	}
	if plan.EstTotal > tm.Budget {
		t.Errorf("el plan se pasó: %s > %s", short(plan.EstTotal), short(tm.Budget))
	}
	if len(plan.Notes) == 0 {
		t.Error("un recorte tiene que quedar anotado en el reporte")
	}
}

// TestPlanSweepKeepsGuidedPhaseEvenIfItOverruns fija una decisión deliberada:
// si el presupuesto es absurdamente pequeño, se prefiere pasarse de tiempo
// antes que salir sin la fase donde el operario pone pesos reales. Sin esa
// fase el archivo no sirve para nada.
func TestPlanSweepKeepsGuidedPhaseEvenIfItOverruns(t *testing.T) {
	tm := DefaultTimings()
	tm.Budget = 15 * time.Second
	plan := PlanSweep(DefaultConfigs(), tm)

	if !plan.OverBudget {
		t.Error("debía avisar que no cabe")
	}
	if plan.GuidedWindow != minGuidedWindow {
		t.Errorf("la ventana guiada debía quedar en el mínimo %s, quedó en %s",
			short(minGuidedWindow), short(plan.GuidedWindow))
	}
	if !plan.Steps[0].Included {
		t.Error("la configuración del manual tiene que ejecutarse siempre")
	}
	if len(plan.IncludedConfigs()) != 1 {
		t.Errorf("con 15s solo debía caber la primera, cupieron %d", len(plan.IncludedConfigs()))
	}
}

func TestPlanSweepNeverReturnsEmptyPlan(t *testing.T) {
	tm := DefaultTimings()
	tm.Budget = time.Millisecond
	plan := PlanSweep(DefaultConfigs(), tm)
	if len(plan.IncludedConfigs()) == 0 {
		t.Fatal("aun con presupuesto ridículo hay que barrer al menos una configuración")
	}
	if plan.IncludedConfigs()[0].ID() != "9600 8-N-1" {
		t.Errorf("la que sobrevive debe ser la del manual, fue %q", plan.IncludedConfigs()[0].ID())
	}
}

func TestPlanSweepWithNoConfigs(t *testing.T) {
	plan := PlanSweep(nil, DefaultTimings())
	if len(plan.Steps) != 0 {
		t.Errorf("got %d pasos", len(plan.Steps))
	}
	if len(plan.Notes) == 0 {
		t.Error("debía dejar constancia de que no había nada que barrer")
	}
}

func TestPlanSweepForcesAtLeastOneWeight(t *testing.T) {
	tm := DefaultTimings()
	tm.WeightSteps = 0
	plan := PlanSweep(DefaultConfigs(), tm)
	if plan.WeightSteps != 1 {
		t.Errorf("WeightSteps = %d, se esperaba 1", plan.WeightSteps)
	}
	if len(plan.Notes) == 0 {
		t.Error("debía advertir que con un solo peso no se distingue el número")
	}
}

func TestGuidedTargetsPrefersConfigsThatAnswered(t *testing.T) {
	cfgs := DefaultConfigs()
	results := []ConfigResult{
		{Config: cfgs[0], Bytes: 0},
		{Config: cfgs[1], Bytes: 12},
		{Config: cfgs[2], Bytes: 300},
	}
	got := GuidedTargets(results, cfgs, 2)
	if len(got) != 2 {
		t.Fatalf("got %d", len(got))
	}
	// Más bytes primero.
	if got[0].ID() != cfgs[2].ID() || got[1].ID() != cfgs[1].ID() {
		t.Errorf("orden incorrecto: %q, %q", got[0].ID(), got[1].ID())
	}
}

// TestGuidedTargetsFallsBackWhenNothingAnswered cubre el caso más probable y
// más importante: la báscula está en un formato que solo transmite al presionar
// una tecla, así que el barrido pasivo no oyó nada. La fase guiada es entonces
// la única oportunidad, y tiene que apuntar a las configuraciones prioritarias.
func TestGuidedTargetsFallsBackWhenNothingAnswered(t *testing.T) {
	cfgs := DefaultConfigs()
	results := []ConfigResult{{Config: cfgs[0]}, {Config: cfgs[1]}}
	got := GuidedTargets(results, cfgs, 2)
	if len(got) != 2 {
		t.Fatalf("got %d, se esperaban 2", len(got))
	}
	if got[0].ID() != "9600 8-N-1" {
		t.Errorf("debía caer en la del manual primero, fue %q", got[0].ID())
	}
}

func TestGuidedTargetsRespectsMax(t *testing.T) {
	cfgs := DefaultConfigs()
	if got := GuidedTargets(nil, cfgs, 1); len(got) != 1 {
		t.Errorf("got %d, want 1", len(got))
	}
	// Un máximo inválido no puede dejar la fase guiada sin objetivo.
	if got := GuidedTargets(nil, cfgs, 0); len(got) != 1 {
		t.Errorf("con max=0 debía quedar 1 objetivo, quedaron %d", len(got))
	}
	if got := GuidedTargets(nil, nil, 2); len(got) != 0 {
		t.Errorf("sin configuraciones no puede haber objetivos, hubo %d", len(got))
	}
}

func TestShort(t *testing.T) {
	cases := []struct {
		d    time.Duration
		want string
	}{
		{13600 * time.Millisecond, "13.6s"},
		{500 * time.Millisecond, "0.5s"},
		{141600 * time.Millisecond, "2m21s"},
		{4 * time.Minute, "4m00s"},
	}
	for _, c := range cases {
		if got := short(c.d); got != c.want {
			t.Errorf("short(%v) = %q, want %q", c.d, got, c.want)
		}
	}
}

// TestElVeredictoEligePorLegiblesYNoPorCantidad reproduce, con los numeros reales
// del 2026-09-01, el fallo que hizo perder la captura guiada contra la bascula de
// Paraiso de la Canasta: escuchar a 19200 una bascula que habla a 4800 produce
// CINCO VECES mas bytes, y todos son basura.
func TestElVeredictoEligePorLegiblesYNoPorCantidad(t *testing.T) {
	limpia := ConfigResult{
		Config: PortConfig{Baud: 4800, DataBits: 8, Parity: ParityNone, StopBits: 1},
		Bytes:  200,
		Raw:    bytes.Repeat([]byte("000.410\r"), 25),
	}
	ruidosa := ConfigResult{
		Config: PortConfig{Baud: 19200, DataBits: 8, Parity: ParityNone, StopBits: 1},
		Bytes:  1016,
		Raw:    bytes.Repeat([]byte{0x00, 0x78, 0x78, 0x78, 0x86, 0x18, 0x60, 0x1e}, 127),
	}

	if !masCreible(limpia, ruidosa) {
		t.Errorf("la configuracion legible (%.0f%%, %d bytes) tiene que ganarle a la ruidosa (%.0f%%, %d bytes)",
			limpia.PrintableRatio()*100, limpia.Bytes, ruidosa.PrintableRatio()*100, ruidosa.Bytes)
	}

	got := GuidedTargets([]ConfigResult{ruidosa, limpia}, nil, 1)
	if len(got) != 1 || got[0].Baud != 4800 {
		t.Errorf("la fase guiada tiene que correr en la configuracion buena, salio %+v", got)
	}
}

// TestConPocosBytesNoSeJuzgaLaLimpieza: tres bytes que por casualidad caen en el
// rango imprimible dan 100% y no prueban nada. Ahi manda la cantidad, como antes.
func TestConPocosBytesNoSeJuzgaLaLimpieza(t *testing.T) {
	casual := ConfigResult{Config: PortConfig{Baud: 2400}, Bytes: 3, Raw: []byte("abc")}
	seria := ConfigResult{Config: PortConfig{Baud: 4800}, Bytes: 400, Raw: bytes.Repeat([]byte("000.410\rx\x00"), 40)}

	if masCreible(casual, seria) {
		t.Error("tres bytes imprimibles por casualidad no pueden ganarle a una captura de verdad")
	}
}
