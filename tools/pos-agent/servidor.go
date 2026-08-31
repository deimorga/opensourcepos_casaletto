package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/coder/websocket"
)

// Servidor: HTTP en 127.0.0.1 y nada mas.
//
// Atarse a loopback no es solo higiene: es lo que evita que el Firewall de
// Windows pregunte nada al arrancar --solo pregunta por programas que escuchan
// hacia afuera-- y lo que impide que otro equipo de la tienda, o de la red WiFi
// del negocio, le pida a esta caja que abra el cajon.
//
// El contrato con la pagina esta en el §5.4 del diseno tecnico y los nombres de
// operacion son los de ahi.

// Mensaje es lo que va y viene por el WebSocket.
type Mensaje struct {
	Op string `json:"op"`
	// ID lo pone la pagina y el agente lo devuelve tal cual, para que una
	// respuesta se pueda casar con su peticion cuando hay varias en vuelo.
	ID string `json:"id,omitempty"`

	// scale.weight
	Raw string `json:"raw,omitempty"`
	At  string `json:"at,omitempty"`

	// printer.raw: los bytes del recibo en base64, porque JSON no transporta
	// bytes arbitrarios y ESC/POS no es texto.
	Data string `json:"data,omitempty"`

	// hello
	Version string `json:"version,omitempty"`
	Scale   *bool  `json:"scale,omitempty"`
	Printer *bool  `json:"printer,omitempty"`

	// error
	Code    string `json:"code,omitempty"`
	Message string `json:"message,omitempty"`
}

// limiteMensaje acota lo que se acepta leer. Un recibo largo en base64 no pasa
// de unas decenas de kilobytes; un megabyte es holgado y a la vez impide que
// algo mal intencionado, o mal programado, agote la memoria de la caja.
const limiteMensaje = 1 << 20

type Servidor struct {
	cfg  Config
	bas  *Bascula
	impr *Impresora
	logf func(string, ...any)
}

func NuevoServidor(cfg Config, bas *Bascula, impr *Impresora, logf func(string, ...any)) *Servidor {
	if logf == nil {
		logf = func(string, ...any) {}
	}
	return &Servidor{cfg: cfg, bas: bas, impr: impr, logf: logf}
}

func (s *Servidor) Rutas() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("/estado", s.estado)
	mux.HandleFunc("/ws", s.ws)
	return mux
}

// Escuchar arranca el servidor. Devuelve el listener para que quien llame
// sepa el puerto real y pueda cerrarlo.
func (s *Servidor) Escuchar() (net.Listener, *http.Server, error) {
	dir := fmt.Sprintf("127.0.0.1:%d", s.cfg.PuertoHTTP)
	ln, err := net.Listen("tcp", dir)
	if err != nil {
		return nil, nil, fmt.Errorf("no se pudo escuchar en %s: %w", dir, err)
	}
	srv := &http.Server{
		Handler:           s.Rutas(),
		ReadHeaderTimeout: 5 * time.Second,
	}
	return ln, srv, nil
}

// origenPermitido decide si una pagina puede hablar con este agente.
//
// Es EL control de seguridad del programa. Sin el, cualquier pagina que el
// cajero abra --un anuncio, un enlace de WhatsApp-- podria pedirle a la caja
// que abra el cajon del dinero, porque el navegador deja que cualquier origen
// hable con 127.0.0.1.
//
// Se compara el origen COMPLETO, con esquema, y no solo el nombre del equipo:
// una pagina servida por http en la misma direccion no es la nuestra.
//
// Sin origenes configurados no se permite ninguno. Es deliberado: un agente que
// acepta a todo el mundo por venir sin configurar es una puerta abierta que
// nadie recuerda haber dejado.
func (s *Servidor) origenPermitido(origen string) bool {
	origen = strings.TrimSuffix(strings.TrimSpace(origen), "/")
	if origen == "" {
		return false
	}
	for _, o := range s.cfg.OrigenesPermitidos {
		if strings.EqualFold(o, origen) {
			return true
		}
	}
	return false
}

func (s *Servidor) estado(w http.ResponseWriter, r *http.Request) {
	// Solo se responden cabeceras de CORS al origen configurado; a los demas
	// el navegador les niega la lectura. Sin Origin --curl, o el navegador
	// abriendo la direccion a mano-- se responde igual, porque ahi no hay
	// pagina ajena que pueda leerlo y es la forma de diagnosticar desde la
	// propia maquina.
	if origen := r.Header.Get("Origin"); origen != "" {
		if !s.origenPermitido(origen) {
			http.Error(w, "origen no autorizado", http.StatusForbidden)
			return
		}
		w.Header().Set("Access-Control-Allow-Origin", origen)
		w.Header().Set("Vary", "Origin")
	}

	basculaOK := s.bas.Configurada()
	imprOK := s.impr.Configurada()

	cuerpo := map[string]any{
		"agente":  "pos-agent",
		"version": version,
		"bascula": map[string]any{
			"configurada": basculaOK,
			"puerto":      s.cfg.Bascula.Puerto,
			"abierta":     s.bas.Abierto(),
		},
		"impresora": map[string]any{
			"configurada": imprOK,
			"nombre":      s.cfg.Impresora.Nombre,
		},
		"origenes_permitidos": s.cfg.OrigenesPermitidos,
	}
	if imprOK {
		if err := ComprobarImpresora(s.cfg.Impresora.Nombre); err != nil {
			cuerpo["impresora"].(map[string]any)["problema"] = err.Error()
		}
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	_ = json.NewEncoder(w).Encode(cuerpo)
}

func (s *Servidor) ws(w http.ResponseWriter, r *http.Request) {
	origen := r.Header.Get("Origin")
	if !s.origenPermitido(origen) {
		s.logf("ws: origen rechazado: %q", origen)
		http.Error(w, "origen no autorizado", http.StatusForbidden)
		return
	}

	// InsecureSkipVerify apaga la comprobacion de origen de la libreria porque
	// ya se hizo arriba, y la de arriba es mas estricta: la libreria compara
	// solo el nombre del equipo, esta compara el origen completo.
	c, err := websocket.Accept(w, r, &websocket.AcceptOptions{InsecureSkipVerify: true})
	if err != nil {
		s.logf("ws: no se pudo aceptar la conexion: %v", err)
		return
	}
	defer c.CloseNow()
	c.SetReadLimit(limiteMensaje)

	ctx := r.Context()
	s.enviar(ctx, c, s.saludo())

	for {
		var m Mensaje
		if err := leerJSON(ctx, c, &m); err != nil {
			return
		}
		s.atender(ctx, c, m)
	}
}

func (s *Servidor) saludo() Mensaje {
	bas := s.bas.Configurada()
	impr := s.impr.Configurada()
	return Mensaje{Op: "hello", Version: version, Scale: &bas, Printer: &impr}
}

func (s *Servidor) atender(ctx context.Context, c *websocket.Conn, m Mensaje) {
	switch m.Op {
	case "scale.read":
		crudo, hora, err := s.bas.Leer()
		if err != nil {
			s.enviar(ctx, c, errorDe(m.ID, err))
			return
		}
		s.enviar(ctx, c, Mensaje{Op: "scale.weight", ID: m.ID, Raw: crudo, At: hora.Format(time.RFC3339Nano)})

	case "printer.raw":
		datos, err := base64.StdEncoding.DecodeString(m.Data)
		if err != nil {
			s.enviar(ctx, c, Mensaje{Op: "error", ID: m.ID, Code: "datos_invalidos", Message: "los bytes del recibo no vienen en base64 válido"})
			return
		}
		if err := s.impr.Imprimir(datos); err != nil {
			s.enviar(ctx, c, errorDe(m.ID, err))
			return
		}
		s.enviar(ctx, c, Mensaje{Op: "printer.ok", ID: m.ID})

	case "drawer.open":
		if err := s.impr.AbrirCajon(); err != nil {
			s.enviar(ctx, c, errorDe(m.ID, err))
			return
		}
		s.enviar(ctx, c, Mensaje{Op: "drawer.ok", ID: m.ID})

	default:
		s.enviar(ctx, c, Mensaje{Op: "error", ID: m.ID, Code: "operacion_desconocida", Message: fmt.Sprintf("no sé hacer %q", m.Op)})
	}
}

// errorDe traduce un error interno a un codigo que la pagina pueda distinguir.
//
// Importa separar "esta caja no tiene bascula" --que es normal y la pagina debe
// resolver pidiendo el peso a mano-- de "la bascula esta y no contesta", que si
// es una falla que alguien tiene que atender.
func errorDe(id string, err error) Mensaje {
	code := "fallo"
	switch {
	case errors.Is(err, ErrSinBascula):
		code = "sin_bascula"
	case errors.Is(err, ErrSinLectura):
		code = "sin_lectura"
	case errors.Is(err, ErrSinImpresora):
		code = "sin_impresora"
	}
	return Mensaje{Op: "error", ID: id, Code: code, Message: err.Error()}
}

// tiempoEscritura acota cuanto se espera al escribir. Una pestana congelada no
// puede dejar al agente atascado sin atender a las demas.
const tiempoEscritura = 5 * time.Second

func (s *Servidor) enviar(ctx context.Context, c *websocket.Conn, m Mensaje) {
	ctx, cancel := context.WithTimeout(ctx, tiempoEscritura)
	defer cancel()
	datos, err := json.Marshal(m)
	if err != nil {
		s.logf("ws: no se pudo serializar %s: %v", m.Op, err)
		return
	}
	if err := c.Write(ctx, websocket.MessageText, datos); err != nil {
		s.logf("ws: no se pudo enviar %s: %v", m.Op, err)
	}
}

func leerJSON(ctx context.Context, c *websocket.Conn, m *Mensaje) error {
	tipo, datos, err := c.Read(ctx)
	if err != nil {
		return err
	}
	if tipo != websocket.MessageText {
		return errors.New("solo se aceptan mensajes de texto")
	}
	return json.Unmarshal(datos, m)
}
