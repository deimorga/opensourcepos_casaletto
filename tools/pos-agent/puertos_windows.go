//go:build windows

package main

import (
	"fmt"
	"strings"

	"go.bug.st/serial/enumerator"
)

// Busqueda de la bascula por IDENTIDAD y no por numero de puerto.
//
// Windows numera los puertos por conector fisico, asi que el COM de la bascula
// cambia en cuanto alguien mueve el cable de sitio -- y en esta caja ya paso:
// COM6 -> COM7 solo por cambiarla de enchufe. El sintoma es identico al de un
// cable dañado, que es lo que lo vuelve caro: se cambia el cable, se prueba
// otra bascula, y el problema era un numero.
//
// El puente USB-serie de esta bascula es un CH340 de WCH/QinHeng, y ese si es
// estable: viaje a donde viaje el cable, el fabricante y el producto no cambian.

const (
	vidQinheng = "1A86" // WCH/QinHeng, el fabricante del CH340
	pidCH340   = "7523"
)

// PuertoDetectado es un puerto serie presente, con lo que Windows sepa decir.
type PuertoDetectado struct {
	Nombre      string
	Descripcion string
	VID, PID    string
	EsBascula   bool
}

func listarPuertos() []PuertoDetectado {
	detalles, err := enumerator.GetDetailedPortsList()
	if err != nil {
		return nil
	}
	out := make([]PuertoDetectado, 0, len(detalles))
	for _, d := range detalles {
		if d == nil {
			continue
		}
		p := PuertoDetectado{
			Nombre:      d.Name,
			Descripcion: d.Product,
			VID:         d.VID,
			PID:         d.PID,
		}
		if p.Descripcion == "" {
			p.Descripcion = "(sin descripción)"
		}
		p.EsBascula = pareceBascula(p)
		out = append(out, p)
	}
	return out
}

// pareceBascula reconoce el puente USB-serie de la bascula.
//
// Se mira primero el identificador de fabricante, que es lo fiable. El nombre
// se acepta como respaldo porque un driver distinto puede no exponer el VID, y
// quedarse sin bascula por eso seria peor que aceptar un puerto de mas: si hay
// varios candidatos gana el primero, y el operario siempre puede fijar el
// puerto a mano.
func pareceBascula(p PuertoDetectado) bool {
	if strings.EqualFold(p.VID, vidQinheng) {
		return true
	}
	d := strings.ToUpper(p.Descripcion)
	return strings.Contains(d, "CH340") || strings.Contains(d, "CH341") || strings.Contains(d, "USB-SERIAL")
}

// BuscarBascula devuelve el nombre del puerto donde esta el CH340.
func BuscarBascula() (string, error) {
	puertos := listarPuertos()
	if len(puertos) == 0 {
		return "", fmt.Errorf("no hay ningún puerto serie en este equipo")
	}
	for _, p := range puertos {
		if p.EsBascula {
			return p.Nombre, nil
		}
	}
	return "", fmt.Errorf("ninguno de los puertos presentes parece la báscula (%s)", ResumenPuertos())
}

// ResumenPuertos describe lo que hay conectado, para la bitacora.
//
// Es la diferencia entre "no se pudo abrir COM7" --que no dice nada-- y "no se
// pudo abrir COM7; hay COM1, COM2 y USB-SERIAL CH340 (COM9)", que se diagnostica
// solo.
func ResumenPuertos() string {
	puertos := listarPuertos()
	if len(puertos) == 0 {
		return "no hay puertos serie presentes"
	}
	partes := make([]string, 0, len(puertos))
	for _, p := range puertos {
		partes = append(partes, p.Nombre+" "+p.Descripcion)
	}
	return "presentes: " + strings.Join(partes, "; ")
}
