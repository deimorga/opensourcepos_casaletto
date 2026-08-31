package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"time"
)

// Actualizacion a distancia.
//
// Esta desde el primer dia por una razon de aritmetica, no de elegancia: cada
// caja es una copia que envejece, y con tres clientes son doce cajas. Agregar
// esto despues significa rehacer el instalador y volver a pisar las doce, que
// es justo el problema que se queria evitar (§5.3.3).
//
// Como se aplica, y por que asi: Windows deja RENOMBRAR un ejecutable en uso.
// Entonces se renombra el actual a .viejo y se deja el nuevo en su lugar. El
// proceso en marcha sigue corriendo con el binario viejo --nadie interrumpe una
// venta a medias-- y la version nueva entra sola en el siguiente arranque. Una
// caja se apaga todas las noches; eso basta.
//
// Sale de fabrica APAGADA (manifiesto vacio). Mientras no exista un lugar donde
// publicar el manifiesto, apuntar a la nada seria peor que no apuntar.

type manifiesto struct {
	Version string `json:"version"`
	URL     string `json:"url"`
	SHA256  string `json:"sha256"`
}

// tamanoMaxDescarga acota lo que se acepta bajar. El agente pesa unos pocos
// megabytes; el tope evita que un servidor equivocado, o un error de ruta,
// llene el disco de la caja.
const tamanoMaxDescarga = 64 << 20

func vigilarActualizaciones(cfg ConfigActual, logf func(string, ...any)) {
	if strings.TrimSpace(cfg.Manifiesto) == "" {
		return
	}
	limpiarViejos(logf)

	intervalo := time.Duration(cfg.CadaHoras) * time.Hour
	for {
		if err := comprobarUnaVez(cfg.Manifiesto, logf); err != nil {
			// Nunca es fatal. Una caja sin internet, o un servidor caido, no
			// pueden impedir que la tienda venda.
			logf("actualizacion: %v", err)
		}
		time.Sleep(intervalo)
	}
}

func comprobarUnaVez(url string, logf func(string, ...any)) error {
	if !strings.HasPrefix(strings.ToLower(url), "https://") {
		// Sin HTTPS, cualquiera en la red del negocio podria servir su propio
		// ejecutable. El hash no salva nada si quien lo publica es el atacante.
		return fmt.Errorf("el manifiesto tiene que ser https, y es %q", url)
	}

	cliente := &http.Client{Timeout: 30 * time.Second}
	resp, err := cliente.Get(url)
	if err != nil {
		return fmt.Errorf("no se pudo consultar el manifiesto: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("el manifiesto respondió %s", resp.Status)
	}

	var m manifiesto
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&m); err != nil {
		return fmt.Errorf("el manifiesto no es un JSON válido: %w", err)
	}
	if m.Version == "" || m.URL == "" || m.SHA256 == "" {
		return fmt.Errorf("el manifiesto está incompleto")
	}
	if m.Version == version {
		return nil
	}

	logf("actualizacion: hay %s y esta caja corre %s", m.Version, version)

	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("no se supo cuál es el ejecutable en marcha: %w", err)
	}
	return aplicar(m, cliente, exe, logf)
}

// aplicar recibe la ruta del ejecutable a reemplazar en vez de averiguarla.
// Es lo que permite probarlo: una prueba que llamara a os.Executable() estaria
// renombrando el binario de la propia suite.
func aplicar(m manifiesto, cliente *http.Client, exe string, logf func(string, ...any)) error {
	if !strings.HasPrefix(strings.ToLower(m.URL), "https://") {
		return fmt.Errorf("la descarga tiene que ser https, y es %q", m.URL)
	}

	resp, err := cliente.Get(m.URL)
	if err != nil {
		return fmt.Errorf("no se pudo descargar: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("la descarga respondió %s", resp.Status)
	}

	tmp := exe + ".descargando"
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o755)
	if err != nil {
		return fmt.Errorf("no se pudo crear %s: %w", tmp, err)
	}

	h := sha256.New()
	_, err = io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, tamanoMaxDescarga))
	cerrar := f.Close()
	if err != nil {
		os.Remove(tmp)
		return fmt.Errorf("falló la descarga: %w", err)
	}
	if cerrar != nil {
		os.Remove(tmp)
		return fmt.Errorf("no se pudo cerrar la descarga: %w", cerrar)
	}

	suma := hex.EncodeToString(h.Sum(nil))
	if !strings.EqualFold(suma, m.SHA256) {
		os.Remove(tmp)
		// Esto no es un fallo de red: o el manifiesto miente o alguien cambio
		// el archivo. En cualquiera de los dos casos, no se instala.
		return fmt.Errorf("el archivo descargado no coincide con el hash anunciado (esperado %s, obtenido %s)", m.SHA256, suma)
	}

	viejo := exe + ".viejo"
	os.Remove(viejo)
	if err := os.Rename(exe, viejo); err != nil {
		os.Remove(tmp)
		return fmt.Errorf("no se pudo apartar el ejecutable actual: %w", err)
	}
	if err := os.Rename(tmp, exe); err != nil {
		// Se deshace: dejar la caja sin ejecutable seria el peor final posible.
		_ = os.Rename(viejo, exe)
		os.Remove(tmp)
		return fmt.Errorf("no se pudo poner el ejecutable nuevo, se dejó el anterior: %w", err)
	}

	logf("actualizacion: instalada la version %s; entra en el proximo arranque de la caja", m.Version)
	return nil
}

// limpiarViejos borra el ejecutable apartado por una actualizacion anterior.
// Se hace al arrancar, cuando ya nadie lo tiene abierto.
func limpiarViejos(logf func(string, ...any)) {
	exe, err := os.Executable()
	if err != nil {
		return
	}
	if err := os.Remove(exe + ".viejo"); err == nil {
		logf("actualizacion: limpiado el ejecutable de la version anterior")
	}
}
