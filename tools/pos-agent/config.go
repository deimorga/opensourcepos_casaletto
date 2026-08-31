package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// La configuracion vive en un archivo JSON al lado del ejecutable, con nombres
// de campo en espanol a proposito: el dia del montaje alguien puede tener que
// abrirlo con el Bloc de notas frente al cliente, y "bits_parada" se entiende
// sin manual.
//
// Todo campo ausente toma un valor por omision razonable, y un archivo que no
// existe NO es un error: el agente arranca igual, sin bascula y sin impresora,
// y lo dice en el estado. Un agente que se niega a arrancar por falta de
// configuracion es un agente que el cajero ve muerto sin saber por que.

const nombreArchivoConfig = "config.json"

// puertoHTTPPorOmision es el puerto de escucha en 127.0.0.1.
//
// Fijo y no negociable en la practica: la pagina tiene que saber a donde
// conectarse sin preguntarle a nadie. Se puede cambiar en el archivo, pero
// entonces hay que cambiarlo tambien del lado del sistema.
const puertoHTTPPorOmision = 7878

type Config struct {
	PuertoHTTP         int           `json:"puerto_http"`
	OrigenesPermitidos []string      `json:"origenes_permitidos"`
	Bascula            ConfigBascula `json:"bascula"`
	Impresora          ConfigImpr    `json:"impresora"`
	Actualizacion      ConfigActual  `json:"actualizacion"`

	// ruta recuerda de donde se leyo, para poder reescribirlo sin adivinar.
	ruta string
}

type ConfigBascula struct {
	// Puerto vacio significa "no hay bascula configurada". Es el estado de
	// fabrica y el que va a tener hasta el dia de los cinco minutos.
	Puerto     string `json:"puerto"`
	Baudios    int    `json:"baudios"`
	BitsDatos  int    `json:"bits_datos"`
	Paridad    string `json:"paridad"`
	BitsParada int    `json:"bits_parada"`

	// Comando es lo que se le escribe a la bascula para pedirle el peso, si es
	// de las que solo hablan cuando se les pregunta (el formato por comando
	// 'W' del §5.10b del diseno tecnico). Vacio = la bascula transmite sola y
	// aqui solo se escucha.
	Comando string `json:"comando"`

	// FrescuraMs es la edad maxima de una trama para darla por buena sin
	// volver a preguntar. Una bascula que transmite cada 200 ms deja siempre
	// una trama reciente en memoria, y asi el peso aparece instantaneo.
	FrescuraMs int `json:"frescura_ms"`

	// EsperaMs es cuanto se aguarda una trama nueva cuando no hay ninguna
	// fresca. Si vence, se responde "sin lectura", nunca un peso inventado.
	EsperaMs int `json:"espera_ms"`
}

type ConfigImpr struct {
	// Nombre es el nombre exacto de la impresora en Windows. Vacio = no hay
	// impresora configurada, y las operaciones de impresion responden un error
	// explicito en vez de fallar en silencio.
	Nombre string `json:"nombre"`

	// AbrirCajon son los bytes que abren el cajon monetero, en decimal. El
	// cajon cuelga de la impresora por RJ11, asi que abrirlo es imprimir una
	// secuencia de control. 27,112,0,25,250 es ESC p 0 con los tiempos
	// habituales; se deja configurable porque cada impresora tiene su gusto.
	AbrirCajon []int `json:"abrir_cajon"`
}

type ConfigActual struct {
	// Manifiesto es la URL de un JSON con la version disponible y su hash.
	// Vacio = actualizacion apagada, que es como sale de fabrica: mientras no
	// exista un lugar donde publicarlo, apuntar a la nada seria peor.
	Manifiesto string `json:"manifiesto"`
	CadaHoras  int    `json:"cada_horas"`
}

func configPorOmision() Config {
	return Config{
		PuertoHTTP:         puertoHTTPPorOmision,
		OrigenesPermitidos: []string{},
		Bascula: ConfigBascula{
			Baudios:    9600,
			BitsDatos:  8,
			Paridad:    "N",
			BitsParada: 1,
			FrescuraMs: 3000,
			EsperaMs:   2500,
		},
		Impresora: ConfigImpr{
			AbrirCajon: []int{27, 112, 0, 25, 250},
		},
		Actualizacion: ConfigActual{CadaHoras: 24},
	}
}

// CargarConfig lee el archivo junto al ejecutable. Un archivo ausente devuelve
// los valores por omision y ningun error.
func CargarConfig(ruta string) (Config, error) {
	c := configPorOmision()
	c.ruta = ruta

	datos, err := os.ReadFile(ruta)
	if err != nil {
		if os.IsNotExist(err) {
			return c, nil
		}
		return c, fmt.Errorf("no se pudo leer %s: %w", ruta, err)
	}

	// Se decodifica SOBRE los valores por omision, no sobre una estructura
	// vacia: asi un archivo que solo trae el puerto de la bascula conserva el
	// resto de los ajustes en vez de dejarlos en cero.
	if err := json.Unmarshal(datos, &c); err != nil {
		return configPorOmision(), fmt.Errorf("%s no es un JSON valido: %w", ruta, err)
	}
	c.ruta = ruta

	return c.normalizada(), nil
}

// normalizada corrige valores imposibles en lugar de rechazar el archivo.
//
// El criterio es el mismo del scale-probe: es preferible un agente que arranca
// con un valor sensato a uno que no arranca. Lo unico que no se inventa es el
// puerto de la bascula ni el nombre de la impresora, porque ahi adivinar seria
// peor que no hacer nada.
func (c Config) normalizada() Config {
	if c.PuertoHTTP <= 0 || c.PuertoHTTP > 65535 {
		c.PuertoHTTP = puertoHTTPPorOmision
	}
	if c.Bascula.Baudios <= 0 {
		c.Bascula.Baudios = 9600
	}
	if c.Bascula.BitsDatos != 7 && c.Bascula.BitsDatos != 8 {
		c.Bascula.BitsDatos = 8
	}
	switch strings.ToUpper(c.Bascula.Paridad) {
	case "N", "E", "O":
		c.Bascula.Paridad = strings.ToUpper(c.Bascula.Paridad)
	default:
		c.Bascula.Paridad = "N"
	}
	if c.Bascula.BitsParada != 1 && c.Bascula.BitsParada != 2 {
		c.Bascula.BitsParada = 1
	}
	if c.Bascula.FrescuraMs < 0 {
		c.Bascula.FrescuraMs = 0
	}
	if c.Bascula.EsperaMs <= 0 {
		c.Bascula.EsperaMs = 2500
	}
	// Un tiempo de espera enorme dejaria la caja colgada esperando una bascula
	// que quiza no esta. Diez segundos ya es una eternidad frente a un cliente.
	if c.Bascula.EsperaMs > 10000 {
		c.Bascula.EsperaMs = 10000
	}
	if len(c.Impresora.AbrirCajon) == 0 {
		c.Impresora.AbrirCajon = []int{27, 112, 0, 25, 250}
	}
	if c.Actualizacion.CadaHoras <= 0 {
		c.Actualizacion.CadaHoras = 24
	}

	limpios := make([]string, 0, len(c.OrigenesPermitidos))
	for _, o := range c.OrigenesPermitidos {
		o = strings.TrimSpace(strings.TrimSuffix(strings.TrimSpace(o), "/"))
		if o != "" {
			limpios = append(limpios, o)
		}
	}
	c.OrigenesPermitidos = limpios

	return c
}

func (c ConfigBascula) frescura() time.Duration {
	return time.Duration(c.FrescuraMs) * time.Millisecond
}
func (c ConfigBascula) espera() time.Duration { return time.Duration(c.EsperaMs) * time.Millisecond }

// Guardar escribe la configuracion formateada, para poder crear el archivo de
// ejemplo en el montaje sin escribirlo a mano.
func (c Config) Guardar(ruta string) error {
	datos, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(ruta, append(datos, '\n'), 0o644)
}

// rutaJuntoAlEjecutable resuelve un nombre relativo a la carpeta del binario y
// no al directorio de trabajo, porque el agente lo arranca el programador de
// tareas de Windows, cuyo directorio de trabajo no es el que uno esperaria.
func rutaJuntoAlEjecutable(nombre string) string {
	exe, err := os.Executable()
	if err != nil {
		return nombre
	}
	return filepath.Join(filepath.Dir(exe), nombre)
}
