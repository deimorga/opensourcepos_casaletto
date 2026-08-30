package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestReportFilenameIsDated(t *testing.T) {
	got := ReportFilename(time.Date(2026, 8, 30, 14, 32, 7, 0, time.UTC))
	if got != "captura-bascula-20260830-143207.txt" {
		t.Errorf("got %q", got)
	}
	// Sin caracteres que Windows prohíba en un nombre de archivo.
	for _, c := range `\/:*?"<>|` {
		if strings.ContainsRune(got, c) {
			t.Errorf("el nombre %q lleva el carácter prohibido %q", got, c)
		}
	}
}

// newTestReporter arma un Reporter sobre un directorio temporal, sin pasar por
// la detección de directorio escribible.
func newTestReporter(t *testing.T) *Reporter {
	t.Helper()
	path := filepath.Join(t.TempDir(), "captura.txt")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		t.Fatal(err)
	}
	r := &Reporter{f: f, path: path, started: time.Now()}
	r.f.Write([]byte{0xEF, 0xBB, 0xBF})
	r.preamble(bannerProvisional(r.started))
	return r
}

// TestReporterWritesIncrementally es la garantía de que un corte a mitad de
// captura no se lleva lo capturado: cada evento tiene que estar en disco antes
// del siguiente, no en un buffer esperando el cierre.
func TestReporterWritesIncrementally(t *testing.T) {
	r := newTestReporter(t)
	r.Line("primer evento")

	// Se lee el archivo SIN cerrarlo, como haría alguien que abre el .txt con
	// la captura todavía corriendo.
	b, err := os.ReadFile(r.Path())
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(b), "primer evento") {
		t.Errorf("el evento no llegó a disco antes de cerrar:\n%s", b)
	}
	// Y el aviso de captura interrumpida tiene que estar, porque en ese
	// momento la captura efectivamente está incompleta.
	if !strings.Contains(string(b), "SIGUE SIRVIENDO") {
		t.Errorf("falta el aviso de captura interrumpida:\n%s", b)
	}
}

func TestReporterRXRecordsHexAndAscii(t *testing.T) {
	r := newTestReporter(t)
	at := time.Date(2026, 8, 30, 14, 32, 11, 204_000_000, time.UTC)
	r.RX(at, "9600 8-N-1", "sondeo W", moriscoFrame)

	b, err := os.ReadFile(r.Path())
	if err != nil {
		t.Fatal(err)
	}
	s := string(b)
	for _, want := range []string{
		"14:32:11.204",                   // marca de tiempo
		"cfg=9600 8-N-1",                 // configuración que lo produjo
		"estímulo=sondeo W",              // estímulo que lo produjo
		"N12.395<SP><SP><LF><CR>",        // ASCII imprimible
		"4e 31 32 2e 33 39 35 20  20 0a", // hexadecimal
	} {
		if !strings.Contains(s, want) {
			t.Errorf("falta %q en el registro:\n%s", want, s)
		}
	}
}

// TestReporterFinishPutsSummaryOnTop comprueba el requisito del entregable: el
// archivo tiene que abrirse con un resumen legible y traer el volcado después.
func TestReporterFinishPutsSummaryOnTop(t *testing.T) {
	r := newTestReporter(t)
	r.RX(time.Now(), "9600 8-N-1", "escucha pasiva", moriscoFrame)
	r.Line("evento intermedio")

	path, err := r.Finish("VEREDICTO\n  La báscula habla en 9600 8-N-1.")
	if err != nil {
		t.Fatalf("Finish: %v", err)
	}
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	s := string(b)

	iRes := strings.Index(s, "RESUMEN")
	iVol := strings.Index(s, "VOLCADO COMPLETO")
	iEvt := strings.Index(s, "evento intermedio")
	if iRes < 0 || iVol < 0 || iEvt < 0 {
		t.Fatalf("faltan secciones (resumen=%d volcado=%d evento=%d):\n%s", iRes, iVol, iEvt, s)
	}
	if !(iRes < iVol && iVol < iEvt) {
		t.Errorf("orden incorrecto: resumen=%d volcado=%d evento=%d", iRes, iVol, iEvt)
	}
	if !strings.Contains(s, "La báscula habla en 9600 8-N-1.") {
		t.Error("el veredicto no quedó en el archivo")
	}
	// El volcado tiene que sobrevivir intacto a la reescritura.
	if !strings.Contains(s, "4e 31 32 2e 33 39 35") {
		t.Errorf("se perdió el volcado hexadecimal al reescribir:\n%s", s)
	}
	// Y el aviso de "captura interrumpida" ya no aplica: terminó bien.
	if strings.Contains(s, "SIGUE SIRVIENDO") {
		t.Errorf("quedó el aviso de captura interrumpida en un archivo completo:\n%s", s)
	}
	// BOM UTF-8, para que el Bloc de notas muestre los acentos.
	if len(b) < 3 || b[0] != 0xEF || b[1] != 0xBB || b[2] != 0xBF {
		t.Error("falta el BOM UTF-8")
	}
}

func TestReporterFinishIsAtomicallySingleFile(t *testing.T) {
	r := newTestReporter(t)
	r.Line("algo")
	path, err := r.Finish("resumen")
	if err != nil {
		t.Fatalf("Finish: %v", err)
	}
	entries, err := os.ReadDir(filepath.Dir(path))
	if err != nil {
		t.Fatal(err)
	}
	// No pueden quedar temporales tirados: el operario tiene que ver UN archivo.
	if len(entries) != 1 {
		var names []string
		for _, e := range entries {
			names = append(names, e.Name())
		}
		t.Errorf("quedaron %d archivos, se esperaba 1: %v", len(entries), names)
	}
}

// TestReporterDegradedDoesNotPanic cubre el caso de no poder escribir en disco.
// El programa tiene que seguir y volcar por pantalla, nunca caerse.
func TestReporterDegradedDoesNotPanic(t *testing.T) {
	r := &Reporter{degraded: true}
	r.Line("evento")
	r.Linef("con %s", "formato")
	r.Section("sección")
	r.Event(time.Now(), "algo")
	r.RX(time.Now(), "9600 8-N-1", "escucha", moriscoFrame)
	if !r.Degraded() {
		t.Error("Degraded debía ser true")
	}
	if _, err := r.Finish("resumen"); err == nil {
		t.Error("Finish sin archivo debía devolver error")
	}
	// Aun sin archivo, el cuerpo se conserva en memoria para poder mostrarlo.
	if !strings.Contains(r.body.String(), "N12.395") {
		t.Error("el cuerpo en memoria debe conservar lo capturado")
	}
}

func TestReportDirIsWritable(t *testing.T) {
	dir, _ := reportDir()
	if dir == "" {
		t.Fatal("reportDir no encontró ningún directorio escribible")
	}
	if !writable(dir) {
		t.Errorf("reportDir devolvió %q, que no es escribible", dir)
	}
}

func TestWritableRejectsMissingDir(t *testing.T) {
	if writable(filepath.Join(t.TempDir(), "no-existe")) {
		t.Error("un directorio inexistente no es escribible")
	}
}
