package main

import (
	"io"
	"strings"
	"testing"
	"time"
)

func TestParseArgsDefaults(t *testing.T) {
	o, err := parseArgs(nil, io.Discard)
	if err != nil {
		t.Fatalf("los argumentos vacíos deben valer: %v", err)
	}
	def := DefaultTimings()
	if o.Timings.Budget != def.Budget || o.Timings.PassiveListen != def.PassiveListen {
		t.Errorf("no tomó los tiempos por omisión: %+v", o.Timings)
	}
	if o.Timings.WeightSteps != 2 {
		t.Errorf("por omisión hay que pedir 2 pesos, pidió %d", o.Timings.WeightSteps)
	}
	if o.Timings.ProbeCount != len(DefaultProbes()) {
		t.Errorf("ProbeCount = %d, want %d", o.Timings.ProbeCount, len(DefaultProbes()))
	}
	if o.Simulate || o.ListOnly || o.NoPause {
		t.Errorf("los modificadores deben venir apagados: %+v", o)
	}
	if o.Port != "" {
		t.Errorf("por omisión el puerto se detecta solo, quedó %q", o.Port)
	}
}

func TestParseArgsFlags(t *testing.T) {
	o, err := parseArgs([]string{
		"-puerto", "COM7", "-simular", "-sin-pausa", "-listar",
		"-escucha", "2s", "-sondeo", "800ms", "-ventana", "45s",
		"-pesos", "3", "-presupuesto", "3m", "-config-guiadas", "1",
	}, io.Discard)
	if err != nil {
		t.Fatalf("err = %v", err)
	}
	if o.Port != "COM7" || !o.Simulate || !o.NoPause || !o.ListOnly {
		t.Errorf("banderas mal leídas: %+v", o)
	}
	if o.Timings.PassiveListen != 2*time.Second ||
		o.Timings.ProbeWait != 800*time.Millisecond ||
		o.Timings.GuidedWindow != 45*time.Second ||
		o.Timings.Budget != 3*time.Minute ||
		o.Timings.WeightSteps != 3 {
		t.Errorf("tiempos mal leídos: %+v", o.Timings)
	}
	if o.MaxGuided != 1 {
		t.Errorf("MaxGuided = %d", o.MaxGuided)
	}
}

func TestParseArgsRapidoHalvesWaits(t *testing.T) {
	def := DefaultTimings()
	o, err := parseArgs([]string{"-rapido"}, io.Discard)
	if err != nil {
		t.Fatalf("err = %v", err)
	}
	if o.Timings.PassiveListen != def.PassiveListen/2 {
		t.Errorf("escucha = %v, want %v", o.Timings.PassiveListen, def.PassiveListen/2)
	}
	if o.Timings.ProbeWait != def.ProbeWait/2 {
		t.Errorf("sondeo = %v", o.Timings.ProbeWait)
	}
	if o.Timings.GuidedWindow != def.GuidedWindow/2 {
		t.Errorf("ventana = %v", o.Timings.GuidedWindow)
	}
	// El presupuesto NO se toca: es el tope, no una espera.
	if o.Timings.Budget != def.Budget {
		t.Errorf("-rapido no debe cambiar el presupuesto, quedó en %v", o.Timings.Budget)
	}
}

func TestParseArgsSimQuietImpliesSimulate(t *testing.T) {
	o, err := parseArgs([]string{"-simular-mudo"}, io.Discard)
	if err != nil {
		t.Fatalf("err = %v", err)
	}
	if !o.Simulate || !o.SimQuiet {
		t.Errorf("-simular-mudo debe implicar -simular: %+v", o)
	}
}

func TestParseArgsRejectsBadInput(t *testing.T) {
	cases := [][]string{
		{"-pesos", "0"},                 // con menos de un peso no hay nada que comparar
		{"-escucha", "0s"},              // una espera de cero no captura nada
		{"-sondeo", "-1s"},              //
		{"-ventana", "0"},               //
		{"-presupuesto", "0"},           //
		{"sobra"},                       // argumento suelto
		{"-inventado"},                  // bandera desconocida
		{"-escucha", "no-es-un-tiempo"}, //
	}
	for _, args := range cases {
		if _, err := parseArgs(args, io.Discard); err == nil {
			t.Errorf("parseArgs(%v) debía fallar", args)
		}
	}
}

func TestParseArgsNormalizesMaxGuided(t *testing.T) {
	o, err := parseArgs([]string{"-config-guiadas", "0"}, io.Discard)
	if err != nil {
		t.Fatalf("err = %v", err)
	}
	if o.MaxGuided != 1 {
		t.Errorf("MaxGuided = %d, se esperaba que se forzara a 1", o.MaxGuided)
	}
}

func TestParseArgsHelpGoesToTheWriter(t *testing.T) {
	var sb strings.Builder
	if _, err := parseArgs([]string{"-h"}, &sb); err == nil {
		t.Error("-h debe devolver error para que main no siga")
	}
	if !strings.Contains(sb.String(), "doble clic") {
		t.Errorf("la ayuda debe decirle al operario que basta el doble clic:\n%s", sb.String())
	}
}

// --- resumen ---

func sampleSummary() RunSummary {
	cfgs := DefaultConfigs()
	start := time.Date(2026, 8, 30, 9, 0, 0, 0, time.UTC)
	return RunSummary{
		Started: start,
		Ended:   start.Add(140 * time.Second),
		Port:    PortInfo{Name: "COM3", Description: "USB-SERIAL CH340 (COM3)", IsUSB: true, VID: "1A86", PID: "7523"},
		Plan:    PlanSweep(cfgs, DefaultTimings()),
		Results: []ConfigResult{
			{
				Config:      cfgs[0],
				Bytes:       22,
				Passive:     11,
				RespondedTo: map[string]int{"W": 11},
				Raw:         []byte("N12.395  \x0a\x0dN12.395  \x0a\x0d"),
			},
			{Config: cfgs[1], RespondedTo: map[string]int{}},
			{Config: cfgs[2], RespondedTo: map[string]int{}, OpenError: "acceso denegado"},
		},
		Guided: []GuidedResult{
			{Step: 1, Label: "peso A", Config: cfgs[0], Bytes: 11, Raw: []byte("N12.395  \x0a\x0d")},
			{Step: 2, Label: "peso B", Config: cfgs[0], Bytes: 11, Raw: []byte("N01.250  \x0a\x0d")},
		},
	}
}

func TestRunSummaryTotals(t *testing.T) {
	s := sampleSummary()
	if got := s.TotalBytes(); got != 44 {
		t.Errorf("TotalBytes = %d, want 44", got)
	}
	if got := s.TotalFrames(); got != 4 {
		t.Errorf("TotalFrames = %d, want 4", got)
	}
	live := s.Responsive()
	if len(live) != 1 || live[0].Config.ID() != "9600 8-N-1" {
		t.Errorf("Responsive = %+v", live)
	}
}

// TestRunSummaryReportsWhichProbeWorked cubre el dato más valioso de toda la
// captura: con qué estímulo contestó la báscula. Se rompió una vez porque el
// resumen buscaba la clave por el identificador del sondeo mientras el
// registro la guardaba con el prefijo "sondeo ".
func TestRunSummaryReportsWhichProbeWorked(t *testing.T) {
	out := sampleSummary().Render()
	if !strings.Contains(out, "respondió a: W=11B") {
		t.Errorf("el resumen no dice a qué sondeo respondió la báscula:\n%s", out)
	}
}

func TestRunSummaryRenderCoversEverything(t *testing.T) {
	out := sampleSummary().Render()
	for _, want := range []string{
		"COM3",
		"USB-SERIAL CH340",
		"VID=1A86",
		"9600 8-N-1",
		"NO ABRIÓ: acceso denegado", // una configuración que falla queda registrada
		"peso A",
		"peso B",
		"N12.395<SP><SP>",
		"N01.250<SP><SP>",
		"SE CAPTURARON 44 bytes",
		"QUÉ HACER CON ESTE ARCHIVO",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("falta %q en el resumen:\n%s", want, out)
		}
	}
}

func TestRunSummaryHeadlineWhenNothingArrived(t *testing.T) {
	s := RunSummary{Results: []ConfigResult{{Config: DefaultConfigs()[0], RespondedTo: map[string]int{}}}}
	h := s.Headline()
	if !strings.Contains(h, "NO SE RECIBIÓ NINGÚN BYTE") {
		t.Errorf("headline = %q", h)
	}
	// Incluso sin datos hay que insistir en que el archivo se envíe: deja
	// constancia de todo lo que se probó, y eso también es información.
	if !strings.Contains(h, "ENVÍELO") {
		t.Errorf("debe pedir que se envíe el archivo igual: %q", h)
	}
	if out := s.Render(); !strings.Contains(out, "NO SE RECIBIÓ") {
		t.Errorf("el resumen completo debe reflejarlo:\n%s", out)
	}
}

// TestRunSummaryWarnsWhenAllFramesAreIdentical protege contra el fallo
// silencioso más peligroso: capturar mucho pero siempre lo mismo. Si el
// operario puso dos pesos distintos y la trama no cambió, la captura no sirve
// y hay que repetirla ANTES de devolver la báscula.
func TestRunSummaryWarnsWhenAllFramesAreIdentical(t *testing.T) {
	cfg := DefaultConfigs()[0]
	same := []byte("N12.395  \x0a\x0d")
	s := RunSummary{
		Results: []ConfigResult{{Config: cfg, Bytes: len(same), Raw: same, RespondedTo: map[string]int{}}},
		Guided: []GuidedResult{
			{Step: 1, Label: "peso A", Config: cfg, Bytes: len(same), Raw: same},
			{Step: 2, Label: "peso B", Config: cfg, Bytes: len(same), Raw: same},
		},
	}
	if out := s.Render(); !strings.Contains(out, "ATENCIÓN") {
		t.Errorf("debía advertir que todas las tramas son iguales:\n%s", out)
	}
	// Con tramas distintas no debe advertir nada.
	s.Guided[1].Raw = []byte("N01.250  \x0a\x0d")
	if out := s.Render(); strings.Contains(out, "ATENCIÓN") {
		t.Errorf("no debía advertir con tramas distintas:\n%s", out)
	}
}

func TestRunSummaryRendersWarnings(t *testing.T) {
	s := sampleSummary()
	s.Warnings = []string{"no se detectó ningún puerto COM"}
	if out := s.Render(); !strings.Contains(out, "no se detectó ningún puerto COM") {
		t.Errorf("los avisos deben salir en el resumen:\n%s", out)
	}
}

func TestRunSummaryEmptyDoesNotPanic(t *testing.T) {
	var s RunSummary
	if out := s.Render(); out == "" {
		t.Error("aun sin datos el resumen tiene que producir texto")
	}
}
