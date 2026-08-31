package main

import (
	"context"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
)

// version la fija el compilador con -ldflags "-X main.version=...". El valor de
// aqui es el que queda si alguien compila a mano, y decirlo asi evita creer que
// una caja corre una version publicada cuando corre un compilado suelto.
var version = "dev"

// pos-agent: el programa de la caja.
//
// Existe por un muro concreto: el navegador no puede abrir un puerto serie ni
// mandar bytes crudos a una impresora. Todo lo demas del sistema es web; esto
// es lo unico que tiene que vivir en el equipo del cliente.
//
// Tres funciones, una sola instalacion (§5.2 del diseno tecnico):
//   - leer la bascula
//   - imprimir el recibo en ESC/POS crudo
//   - abrir el cajon monetero
//
// Diseno completo: docs/Tecnico/venta-por-peso-y-hardware-de-caja.md, §5.

func main() {
	var (
		rutaConfig  = flag.String("config", "", "ruta del archivo de configuración (por omisión, config.json junto al ejecutable)")
		crearConfig = flag.Bool("crear-config", false, "escribe un config.json de ejemplo y termina")
		verVersion  = flag.Bool("version", false, "muestra la versión y termina")
		simular     = flag.Bool("simular", false, "usa una báscula simulada, sin hardware ni driver")
	)
	flag.Parse()

	if *verVersion {
		fmt.Printf("pos-agent %s\n", version)
		return
	}

	ruta := *rutaConfig
	if ruta == "" {
		ruta = rutaJuntoAlEjecutable(nombreArchivoConfig)
	}

	if *crearConfig {
		if err := configPorOmision().Guardar(ruta); err != nil {
			fmt.Fprintf(os.Stderr, "no se pudo escribir %s: %v\n", ruta, err)
			os.Exit(1)
		}
		fmt.Printf("escrito %s\n", ruta)
		return
	}

	registro, cerrarRegistro := abrirRegistro()
	defer cerrarRegistro()
	logf := func(f string, a ...any) { registro.Printf(f, a...) }

	logf("pos-agent %s arrancando", version)

	cfg, err := CargarConfig(ruta)
	if err != nil {
		// Un archivo ilegible NO detiene el arranque: se sigue con los valores
		// por omision y se deja constancia. Un agente muerto es invisible para
		// el cajero; uno vivo y sin bascula al menos aparece en el estado.
		logf("configuracion: %v - se continua con los valores por omision", err)
	}
	logf("configuracion leida de %s", ruta)
	if len(cfg.OrigenesPermitidos) == 0 {
		logf("AVISO: no hay origenes permitidos configurados, asi que ninguna pagina podra usar este agente")
	}

	var abrir abridor
	if *simular {
		logf("bascula SIMULADA: no se abrira ningun puerto real")
		abrir = abrirSimulado
		if cfg.Bascula.Puerto == "" {
			cfg.Bascula.Puerto = "SIMULADO"
		}
	}

	bas := NuevaBascula(cfg.Bascula, abrir, logf)
	bas.Arrancar()
	defer bas.Cerrar()

	impr := NuevaImpresora(cfg.Impresora, nil)

	srvLogico := NuevoServidor(cfg, bas, impr, logf)
	ln, srv, err := srvLogico.Escuchar()
	if err != nil {
		logf("FATAL: %v", err)
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	logf("escuchando en http://%s (solo loopback)", ln.Addr())

	go func() {
		if err := srv.Serve(ln); err != nil && err != http.ErrServerClosed {
			logf("el servidor termino: %v", err)
		}
	}()

	go vigilarActualizaciones(cfg.Actualizacion, logf)

	// Espera a que el sistema pida cerrar. En Windows el agente lo mata el
	// cierre de sesion; aqui solo se busca cerrar el puerto con orden.
	paren := make(chan os.Signal, 1)
	signal.Notify(paren, os.Interrupt, syscall.SIGTERM)
	<-paren

	logf("cerrando")
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	_ = srv.Shutdown(ctx)
}

// tamanoMaxRegistro: el registro se trunca al pasar de aqui.
//
// Una caja lleva anos encendida y nadie va a rotar un archivo a mano. Dos
// megabytes son miles de lineas, mas que suficiente para diagnosticar lo de
// ayer, y evitan que el disco de un equipo modesto se llene con el historial de
// una bascula desconectada.
const tamanoMaxRegistro = 2 << 20

// Los mensajes de la bitacora van SIN TILDES a proposito.
//
// El archivo se escribe en UTF-8, pero la consola de Windows y `type` leen en
// la pagina de codigos del sistema, asi que "configuracion leida" con tildes
// sale como "configuraciA3n leA-da". Es justo el archivo que alguien abre
// cuando algo no funciona, y una bitacora ilegible no se lee.
//
// abrirRegistro deja el registro en un archivo junto al ejecutable Y en la
// salida de error.
//
// El archivo es lo que importa: el agente arranca sin ventana, asi que sin el
// no hay forma de saber por que no funciona, y el dia que falle nadie va a
// estar mirando una consola.
func abrirRegistro() (*log.Logger, func()) {
	ruta := rutaJuntoAlEjecutable("pos-agent.log")

	if info, err := os.Stat(ruta); err == nil && info.Size() > tamanoMaxRegistro {
		_ = os.Remove(ruta)
	}

	f, err := os.OpenFile(ruta, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return log.New(os.Stderr, "", log.LstdFlags), func() {}
	}
	salida := io.MultiWriter(f, os.Stderr)
	return log.New(salida, "", log.LstdFlags), func() { _ = f.Close() }
}
