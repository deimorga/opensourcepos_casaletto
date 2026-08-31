package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/coder/websocket"
)

const origenBueno = "https://paraisodelacanasta.ospos-saas.micronuba.net"

func servidorDePrueba(t *testing.T, ajustar func(*Config)) (*httptest.Server, *Bascula) {
	t.Helper()
	cfg := configPorOmision()
	cfg.OrigenesPermitidos = []string{origenBueno}
	cfg.Bascula.Puerto = "COM-PRUEBA"
	cfg.Bascula.EsperaMs = 500
	if ajustar != nil {
		ajustar(&cfg)
	}

	p := &puertoFalso{porLeer: [][]byte{[]byte("ST,GS,+  0.735kg\r\n")}}
	bas := NuevaBascula(cfg.Bascula, func(ConfigBascula) (puertoSerie, error) { return p, nil }, nil)
	impr := NuevaImpresora(cfg.Impresora, func(string, []byte) error { return nil })

	s := httptest.NewServer(NuevoServidor(cfg, bas, impr, nil).Rutas())
	t.Cleanup(func() { s.Close(); bas.Cerrar() })
	return s, bas
}

func conectar(t *testing.T, s *httptest.Server, origen string) (*websocket.Conn, error) {
	t.Helper()
	cab := http.Header{}
	if origen != "" {
		cab.Set("Origin", origen)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	t.Cleanup(cancel)
	c, _, err := websocket.Dial(ctx, s.URL+"/ws", &websocket.DialOptions{HTTPHeader: cab})
	return c, err
}

func pedir(t *testing.T, c *websocket.Conn, m Mensaje) Mensaje {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	datos, _ := json.Marshal(m)
	if err := c.Write(ctx, websocket.MessageText, datos); err != nil {
		t.Fatalf("no se pudo escribir: %v", err)
	}
	var r Mensaje
	if err := leerJSON(ctx, c, &r); err != nil {
		t.Fatalf("no se pudo leer la respuesta: %v", err)
	}
	return r
}

// Esta es LA prueba de seguridad del programa.
//
// El navegador deja que cualquier pagina hable con 127.0.0.1. Sin esta puerta,
// un anuncio o un enlace que el cajero abra podria pedirle a la caja que abra
// el cajon del dinero.
func TestUnOrigenAjenoNoPuedeConectarse(t *testing.T) {
	s, _ := servidorDePrueba(t, nil)

	for _, origen := range []string{
		"https://sitio-cualquiera.example",
		// Mismo nombre, otro esquema: no es nuestra pagina.
		"http://paraisodelacanasta.ospos-saas.micronuba.net",
		// Un nombre que CONTIENE el nuestro, que es como se cuela una
		// comparacion por sufijo hecha a la ligera.
		"https://paraisodelacanasta.ospos-saas.micronuba.net.malo.example",
		"",
	} {
		if c, err := conectar(t, s, origen); err == nil {
			c.CloseNow()
			t.Errorf("el origen %q logró conectarse y no debía", origen)
		}
	}
}

func TestSinOrigenesConfiguradosNadieEntra(t *testing.T) {
	// Un agente recien instalado y sin configurar no puede ser una puerta
	// abierta que nadie recuerda haber dejado.
	s, _ := servidorDePrueba(t, func(c *Config) { c.OrigenesPermitidos = nil })
	if c, err := conectar(t, s, origenBueno); err == nil {
		c.CloseNow()
		t.Error("sin orígenes configurados no debería entrar nadie")
	}
}

func TestElOrigenConfiguradoRecibeElSaludo(t *testing.T) {
	s, _ := servidorDePrueba(t, nil)
	c, err := conectar(t, s, origenBueno)
	if err != nil {
		t.Fatalf("el origen configurado no pudo conectarse: %v", err)
	}
	defer c.CloseNow()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var saludo Mensaje
	if err := leerJSON(ctx, c, &saludo); err != nil {
		t.Fatal(err)
	}
	if saludo.Op != "hello" {
		t.Fatalf("op = %q, se esperaba hello", saludo.Op)
	}
	if saludo.Scale == nil || !*saludo.Scale {
		t.Error("el saludo debe decir que esta caja tiene báscula")
	}
	if saludo.Printer == nil || *saludo.Printer {
		t.Error("el saludo debe decir que esta caja NO tiene impresora configurada")
	}
}

func TestLecturaDePesoDevuelveLaTramaCruda(t *testing.T) {
	s, _ := servidorDePrueba(t, nil)
	c, err := conectar(t, s, origenBueno)
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseNow()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var saludo Mensaje
	leerJSON(ctx, c, &saludo)

	r := pedir(t, c, Mensaje{Op: "scale.read", ID: "abc"})
	if r.Op != "scale.weight" {
		t.Fatalf("op = %q (%s)", r.Op, r.Message)
	}
	if r.ID != "abc" {
		t.Errorf("id = %q; hay que devolver el de la petición para poder casarlas", r.ID)
	}
	if !strings.Contains(r.Raw, "0.735") {
		t.Errorf("raw = %q; se esperaba la trama tal cual", r.Raw)
	}
}

func TestElCajonSinImpresoraSeDistingueDeUnaAveria(t *testing.T) {
	// La pagina tiene que poder decir "esta caja no imprime" en vez de
	// "error": son dos cosas distintas para quien esta en el mostrador.
	s, _ := servidorDePrueba(t, nil)
	c, err := conectar(t, s, origenBueno)
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseNow()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var saludo Mensaje
	leerJSON(ctx, c, &saludo)

	r := pedir(t, c, Mensaje{Op: "drawer.open"})
	if r.Op != "error" || r.Code != "sin_impresora" {
		t.Fatalf("op = %q, code = %q; se esperaba error/sin_impresora", r.Op, r.Code)
	}
}

func TestOperacionesMalFormadasSeRechazanConClaridad(t *testing.T) {
	s, _ := servidorDePrueba(t, func(c *Config) { c.Impresora.Nombre = "IMPRESORA-PRUEBA" })
	c, err := conectar(t, s, origenBueno)
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseNow()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var saludo Mensaje
	leerJSON(ctx, c, &saludo)

	if r := pedir(t, c, Mensaje{Op: "printer.raw", Data: "esto no es base64!!"}); r.Code != "datos_invalidos" {
		t.Errorf("code = %q, se esperaba datos_invalidos", r.Code)
	}
	if r := pedir(t, c, Mensaje{Op: "vender.todo"}); r.Code != "operacion_desconocida" {
		t.Errorf("code = %q, se esperaba operacion_desconocida", r.Code)
	}
	// Y el camino bueno sigue funcionando despues de dos rechazos: una peticion
	// mala no puede dejar la conexion inservible.
	ok := pedir(t, c, Mensaje{Op: "printer.raw", Data: base64.StdEncoding.EncodeToString([]byte("hola"))})
	if ok.Op != "printer.ok" {
		t.Errorf("op = %q (%s)", ok.Op, ok.Message)
	}
}

func TestElEstadoSeNiegaAOrigenesAjenos(t *testing.T) {
	s, _ := servidorDePrueba(t, nil)

	req, _ := http.NewRequest("GET", s.URL+"/estado", nil)
	req.Header.Set("Origin", "https://sitio-cualquiera.example")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusForbidden {
		t.Errorf("estado = %d, se esperaba 403", resp.StatusCode)
	}

	// Sin cabecera Origin --curl desde la propia maquina-- si responde: es la
	// forma de diagnosticar el dia del montaje.
	resp2, err := http.Get(s.URL + "/estado")
	if err != nil {
		t.Fatal(err)
	}
	defer resp2.Body.Close()
	if resp2.StatusCode != http.StatusOK {
		t.Errorf("estado sin Origin = %d, se esperaba 200", resp2.StatusCode)
	}
	var cuerpo map[string]any
	json.NewDecoder(resp2.Body).Decode(&cuerpo)
	if cuerpo["agente"] != "pos-agent" {
		t.Errorf("cuerpo = %#v", cuerpo)
	}
}
