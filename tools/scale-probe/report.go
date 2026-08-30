package main

import (
	"bytes"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// El archivo de resultados ES el entregable. Alguien lo va a mandar por
// WhatsApp o correo y con eso se desarrolla el resto. De ahi las dos reglas
// que gobiernan este archivo:
//
//  1. Se escribe INCREMENTALMENTE y se sincroniza a disco tras cada evento. Si
//     alguien desconecta la bascula a los tres minutos, lo capturado hasta ahi
//     ya esta en disco.
//  2. El resumen legible va ARRIBA, pero solo se puede escribir al final. Se
//     resuelve reescribiendo el archivo al cerrar, de forma atomica, con todo
//     el volcado guardado tambien en memoria.

// Reporter escribe el archivo de resultados.
type Reporter struct {
	mu      sync.Mutex
	f       *os.File
	path    string
	body    bytes.Buffer // copia en memoria para poder anteponer el resumen
	started time.Time
	// degraded indica que no se pudo abrir archivo. El programa sigue: la
	// consola queda como ultimo recurso, pero se avisa muy fuerte.
	degraded bool
}

// reportDir elige donde escribir, en orden de preferencia, y devuelve el
// primero donde realmente se pueda escribir.
//
// "Junto al ejecutable" es lo pedido y lo que el operario espera encontrar,
// pero un .exe puede correr desde una memoria USB protegida contra escritura o
// desde una carpeta sin permisos. Quedarse sin archivo es el peor resultado
// posible, asi que hay tres respaldos.
func reportDir() (string, []string) {
	var tried []string
	var cands []string

	if exe, err := os.Executable(); err == nil {
		if resolved, err2 := filepath.EvalSymlinks(exe); err2 == nil {
			exe = resolved
		}
		cands = append(cands, filepath.Dir(exe))
	}
	if wd, err := os.Getwd(); err == nil {
		cands = append(cands, wd)
	}
	if home, err := os.UserHomeDir(); err == nil {
		cands = append(cands, filepath.Join(home, "Desktop"), home)
	}
	cands = append(cands, os.TempDir())

	seen := map[string]bool{}
	for _, d := range cands {
		if d == "" || seen[d] {
			continue
		}
		seen[d] = true
		if writable(d) {
			return d, tried
		}
		tried = append(tried, d)
	}
	return "", tried
}

func writable(dir string) bool {
	f, err := os.CreateTemp(dir, ".scale-probe-*")
	if err != nil {
		return false
	}
	name := f.Name()
	f.Close()
	os.Remove(name)
	return true
}

// ReportFilename construye el nombre fechado del archivo.
func ReportFilename(t time.Time) string {
	return fmt.Sprintf("captura-bascula-%s.txt", t.Format("20060102-150405"))
}

// NewReporter abre el archivo y escribe la cabecera provisional.
func NewReporter(now time.Time) (*Reporter, error) {
	r := &Reporter{started: now}
	dir, tried := reportDir()
	if dir == "" {
		r.degraded = true
		return r, fmt.Errorf("ningun directorio resulto escribible (probados: %s)",
			strings.Join(tried, ", "))
	}
	r.path = filepath.Join(dir, ReportFilename(now))
	f, err := os.OpenFile(r.path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		r.degraded = true
		return r, err
	}
	r.f = f
	// BOM UTF-8: hace que el Bloc de notas de Windows muestre bien los acentos
	// sin que el operario tenga que elegir codificacion.
	r.f.Write([]byte{0xEF, 0xBB, 0xBF})
	r.preamble(bannerProvisional(now))
	return r, nil
}

// preamble escribe solo en el archivo, NO en la copia en memoria.
//
// La distincion es la que mantiene simple a Finish: la cabecera provisional
// avisa de que la captura esta incompleta y solo tiene sentido mientras lo
// este. Al dejarla fuera del buffer, la reescritura final se limita a componer
// la cabecera definitiva con el cuerpo, sin tener que recortar texto despues.
func (r *Reporter) preamble(s string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.f == nil {
		return
	}
	r.f.WriteString(s)
	r.f.Sync()
}

// bannerFinal es la cabecera del archivo terminado: la misma identificacion,
// sin el aviso de captura interrumpida.
func bannerFinal(now time.Time) string {
	return "" +
		"================================================================================\n" +
		"  CAPTURA DE PROTOCOLO DE BÁSCULA — ROCHI RC-A01E\n" +
		"  Herramienta: scale-probe " + toolVersion + "\n" +
		"  Inicio: " + now.Format("2006-01-02 15:04:05 -07:00") + "\n" +
		"================================================================================\n" +
		"\n" +
		"  *** ESTE ARCHIVO ES EL RESULTADO. Envíelo COMPLETO, sin recortar nada. ***\n"
}

func bannerProvisional(now time.Time) string {
	return "" +
		"================================================================================\n" +
		"  CAPTURA DE PROTOCOLO DE BÁSCULA — ROCHI RC-A01E\n" +
		"  Herramienta: scale-probe " + toolVersion + "\n" +
		"  Inicio: " + now.Format("2006-01-02 15:04:05 -07:00") + "\n" +
		"================================================================================\n" +
		"\n" +
		"  *** ESTE ARCHIVO ES EL RESULTADO. Envíelo COMPLETO, sin recortar nada. ***\n" +
		"\n" +
		"  Si esta línea sigue apareciendo, la captura se interrumpió antes de\n" +
		"  terminar (se desconectó la báscula, se cerró la ventana o se apagó el\n" +
		"  equipo). El archivo SIGUE SIRVIENDO: todo lo capturado hasta ese momento\n" +
		"  está más abajo. Envíelo igual.\n"
}

// Path devuelve la ruta del archivo, o "" si no se pudo crear.
func (r *Reporter) Path() string { return r.path }

// Degraded indica que se esta corriendo sin archivo.
func (r *Reporter) Degraded() bool { return r.degraded }

// Line escribe en el archivo y lo sincroniza a disco de inmediato.
//
// El Sync por evento es a proposito. Cuesta unos milisegundos y compra que un
// corte a mitad de captura no se lleve lo ya capturado.
func (r *Reporter) Line(s string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if !strings.HasSuffix(s, "\n") {
		s += "\n"
	}
	r.body.WriteString(s)
	if r.f == nil {
		return
	}
	if _, err := r.f.WriteString(s); err != nil {
		return
	}
	r.f.Sync()
}

func (r *Reporter) Linef(format string, a ...any) { r.Line(fmt.Sprintf(format, a...)) }

// Section escribe un separador con titulo.
func (r *Reporter) Section(title string) {
	r.Line("\n" + strings.Repeat("-", 80))
	r.Line(title)
	r.Line(strings.Repeat("-", 80))
}

// RX registra bytes recibidos, con marca de tiempo, estimulo y volcado doble.
func (r *Reporter) RX(at time.Time, cfg string, stimulus string, b []byte) {
	r.Linef("[%s] RX %d bytes | cfg=%s | estímulo=%s",
		at.Format("15:04:05.000"), len(b), cfg, stimulus)
	r.Line("      ASCII: " + Escaped(b))
	r.Line(FormatHexDump(b, "      "))
}

// Event registra cualquier otra cosa con marca de tiempo.
func (r *Reporter) Event(at time.Time, format string, a ...any) {
	r.Linef("[%s] %s", at.Format("15:04:05.000"), fmt.Sprintf(format, a...))
}

// Finish cierra el archivo y lo reescribe con el resumen arriba.
//
// Se hace en dos pasos deliberadamente. Primero se anexa el resumen AL FINAL
// del archivo ya escrito: si la reescritura falla, el resumen existe igual.
// Despues se reescribe entero, de forma atomica (temporal + rename), para que
// quede arriba como pide el entregable. Un fallo en el segundo paso no destruye
// nada.
func (r *Reporter) Finish(summary string) (string, error) {
	r.Line("\n" + strings.Repeat("=", 80))
	r.Line("  RESUMEN (repetido al final por si la reescritura de arriba falla)")
	r.Line(strings.Repeat("=", 80))
	r.Line(summary)

	r.mu.Lock()
	defer r.mu.Unlock()
	if r.f == nil {
		return r.path, fmt.Errorf("no había archivo abierto")
	}
	if err := r.f.Close(); err != nil {
		return r.path, err
	}
	r.f = nil

	// El cuerpo en memoria NUNCA tuvo la cabecera provisional (ver preamble),
	// asi que aqui solo hay que anteponer la definitiva y el resumen.
	body := r.body.String()

	var out bytes.Buffer
	out.Write([]byte{0xEF, 0xBB, 0xBF})
	out.WriteString(bannerFinal(r.started))
	out.WriteString("\n" + strings.Repeat("=", 80) + "\n")
	out.WriteString("  RESUMEN\n")
	out.WriteString(strings.Repeat("=", 80) + "\n")
	out.WriteString(summary)
	out.WriteString("\n\n" + strings.Repeat("=", 80) + "\n")
	out.WriteString("  VOLCADO COMPLETO\n")
	out.WriteString(strings.Repeat("=", 80) + "\n")
	out.WriteString(body)

	tmp, err := os.CreateTemp(filepath.Dir(r.path), ".scale-probe-final-*")
	if err != nil {
		return r.path, err
	}
	tmpName := tmp.Name()
	if _, err := tmp.Write(out.Bytes()); err != nil {
		tmp.Close()
		os.Remove(tmpName)
		return r.path, err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		os.Remove(tmpName)
		return r.path, err
	}
	if err := tmp.Close(); err != nil {
		os.Remove(tmpName)
		return r.path, err
	}
	if err := os.Rename(tmpName, r.path); err != nil {
		os.Remove(tmpName)
		// El archivo original sigue completo y con el resumen al final.
		return r.path, err
	}
	return r.path, nil
}
