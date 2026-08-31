package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestConfigArchivoAusenteNoEsError(t *testing.T) {
	// El agente tiene que arrancar sin configuracion. Uno que se niega a
	// arrancar es invisible para el cajero, que solo ve que no hay peso.
	c, err := CargarConfig(filepath.Join(t.TempDir(), "no-existe.json"))
	if err != nil {
		t.Fatalf("un archivo ausente no debe ser error: %v", err)
	}
	if c.PuertoHTTP != puertoHTTPPorOmision {
		t.Errorf("puerto = %d, se esperaba %d", c.PuertoHTTP, puertoHTTPPorOmision)
	}
}

func TestConfigParcialConservaElResto(t *testing.T) {
	// Un archivo escrito a mano el dia del montaje va a traer el puerto de la
	// bascula y nada mas. Todo lo demas tiene que sobrevivir.
	ruta := filepath.Join(t.TempDir(), "config.json")
	os.WriteFile(ruta, []byte(`{"bascula":{"puerto":"COM3"}}`), 0o644)

	c, err := CargarConfig(ruta)
	if err != nil {
		t.Fatal(err)
	}
	if c.Bascula.Puerto != "COM3" {
		t.Errorf("puerto = %q", c.Bascula.Puerto)
	}
	if c.Bascula.Baudios != 9600 {
		t.Errorf("los baudios se perdieron: %d", c.Bascula.Baudios)
	}
	if c.Bascula.BitsDatos != 8 || c.Bascula.Paridad != "N" || c.Bascula.BitsParada != 1 {
		t.Errorf("la configuración del puerto se perdió: %+v", c.Bascula)
	}
	if len(c.Impresora.AbrirCajon) == 0 {
		t.Error("la secuencia del cajón se perdió")
	}
}

func TestConfigNormalizaValoresImposibles(t *testing.T) {
	ruta := filepath.Join(t.TempDir(), "config.json")
	os.WriteFile(ruta, []byte(`{
		"puerto_http": 999999,
		"bascula": {"baudios": -1, "bits_datos": 5, "paridad": "X", "bits_parada": 7, "espera_ms": 900000},
		"origenes_permitidos": ["https://ejemplo.test/", "  ", ""]
	}`), 0o644)

	c, err := CargarConfig(ruta)
	if err != nil {
		t.Fatal(err)
	}
	if c.PuertoHTTP != puertoHTTPPorOmision {
		t.Errorf("puerto = %d", c.PuertoHTTP)
	}
	if c.Bascula.Baudios != 9600 || c.Bascula.BitsDatos != 8 || c.Bascula.Paridad != "N" || c.Bascula.BitsParada != 1 {
		t.Errorf("no se normalizó el puerto serie: %+v", c.Bascula)
	}
	// Un tiempo de espera enorme dejaria la caja colgada frente al cliente.
	if c.Bascula.EsperaMs != 10000 {
		t.Errorf("espera = %d, se esperaba el tope de 10000", c.Bascula.EsperaMs)
	}
	if len(c.OrigenesPermitidos) != 1 || c.OrigenesPermitidos[0] != "https://ejemplo.test" {
		t.Errorf("orígenes = %#v; la barra final debe caer y los vacíos desaparecer", c.OrigenesPermitidos)
	}
}

func TestConfigJSONRotoNoImpideArrancar(t *testing.T) {
	ruta := filepath.Join(t.TempDir(), "config.json")
	os.WriteFile(ruta, []byte(`{esto no es json`), 0o644)

	c, err := CargarConfig(ruta)
	if err == nil {
		t.Error("un JSON roto debe reportarse")
	}
	if c.PuertoHTTP != puertoHTTPPorOmision {
		t.Errorf("aun con el archivo roto hay que quedar con valores usables, y quedó %d", c.PuertoHTTP)
	}
}
