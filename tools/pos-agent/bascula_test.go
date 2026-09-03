package main

import (
	"errors"
	"io"
	"sync"
	"testing"
	"time"
)

// puertoFalso permite guionizar exactamente lo que la bascula dice y cuando.
type puertoFalso struct {
	mu       sync.Mutex
	porLeer  [][]byte
	escrito  []byte
	traeTras func() []byte
	cerrado  bool
}

func (p *puertoFalso) Read(b []byte) (int, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.cerrado {
		return 0, io.EOF
	}
	if len(p.porLeer) == 0 {
		// (0, nil) es lo que devuelve un puerto real de Windows al vencer el
		// tiempo de espera. Imitarlo es el punto de esta prueba.
		return 0, nil
	}
	n := copy(b, p.porLeer[0])
	p.porLeer = p.porLeer[1:]
	return n, nil
}

func (p *puertoFalso) Write(b []byte) (int, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.escrito = append(p.escrito, b...)
	if p.traeTras != nil {
		p.porLeer = append(p.porLeer, p.traeTras())
	}
	return len(b), nil
}

func (p *puertoFalso) SetReadTimeout(time.Duration) error { return nil }
func (p *puertoFalso) Close() error {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.cerrado = true
	return nil
}

func cfgBascula() ConfigBascula {
	c := configPorOmision().Bascula
	c.Puerto = "COM-PRUEBA"
	c.EsperaMs = 400
	return c
}

func TestSinPuertoConfiguradoEsCondicionNormal(t *testing.T) {
	// Es el estado de fabrica y el que la caja va a tener hasta el dia de la
	// bascula. La pagina necesita distinguirlo de una averia.
	b := NuevaBascula(configPorOmision().Bascula, nil, nil)
	_, _, err := b.Leer()
	if !errors.Is(err, ErrSinBascula) {
		t.Fatalf("err = %v, se esperaba ErrSinBascula", err)
	}
}

func TestDevuelveLaTramaCrudaSinInterpretarla(t *testing.T) {
	// El agente NO interpreta. Si algun dia alguien le agrega "inteligencia",
	// esta prueba tiene que romperse: el patron vive en el servidor, donde se
	// corrige sin volver a pisar la caja.
	cruda := "ST,GS,+  0.735kg\r\n"
	p := &puertoFalso{porLeer: [][]byte{[]byte(cruda)}}
	b := NuevaBascula(cfgBascula(), func(ConfigBascula) (puertoSerie, error) { return p, nil }, nil)
	defer b.Cerrar()

	got, _, err := b.Leer()
	if err != nil {
		t.Fatal(err)
	}
	if got != cruda {
		t.Errorf("trama = %q, se esperaba %q tal cual", got, cruda)
	}
}

func TestSinLecturaNuncaInventaUnPeso(t *testing.T) {
	// Un peso inventado es dinero mal cobrado que nadie nota. Callar es la
	// unica respuesta aceptable.
	p := &puertoFalso{}
	b := NuevaBascula(cfgBascula(), func(ConfigBascula) (puertoSerie, error) { return p, nil }, nil)
	defer b.Cerrar()

	inicio := time.Now()
	_, _, err := b.Leer()
	if !errors.Is(err, ErrSinLectura) {
		t.Fatalf("err = %v, se esperaba ErrSinLectura", err)
	}
	if time.Since(inicio) > 3*time.Second {
		t.Error("esperó demasiado; la caja no puede quedarse colgada frente al cliente")
	}
}

func TestBasculaPorComandoRecibeSuComando(t *testing.T) {
	// El formato por comando del §5.10b: la bascula calla hasta que se le
	// pregunta. Sin esto, esas basculas parecen averiadas.
	cfg := cfgBascula()
	cfg.Comando = "W<CR>"
	cfg.FrescuraMs = 0

	p := &puertoFalso{traeTras: func() []byte { return []byte("  1.250 kg\r\n") }}
	b := NuevaBascula(cfg, func(ConfigBascula) (puertoSerie, error) { return p, nil }, nil)
	defer b.Cerrar()

	got, _, err := b.Leer()
	if err != nil {
		t.Fatalf("no leyó tras enviar el comando: %v", err)
	}
	if got != "  1.250 kg\r\n" {
		t.Errorf("trama = %q", got)
	}

	p.mu.Lock()
	escrito := string(p.escrito)
	p.mu.Unlock()
	if escrito != "W\r" {
		t.Errorf("se envió %q; <CR> tiene que llegar al puerto como retorno de carro real", escrito)
	}
}

func TestUnaTramaViejaNoSeDaPorBuena(t *testing.T) {
	// Pesar una bolsa y que aparezca el peso de la anterior es el peor error
	// posible: parece que funciona.
	cfg := cfgBascula()
	cfg.FrescuraMs = 50
	cfg.EsperaMs = 200

	p := &puertoFalso{porLeer: [][]byte{[]byte("0.100")}}
	b := NuevaBascula(cfg, func(ConfigBascula) (puertoSerie, error) { return p, nil }, nil)
	defer b.Cerrar()

	if _, _, err := b.Leer(); err != nil {
		t.Fatal(err)
	}
	time.Sleep(150 * time.Millisecond)

	if _, _, err := b.Leer(); !errors.Is(err, ErrSinLectura) {
		t.Fatalf("err = %v; una trama pasada de frescura no se puede reutilizar", err)
	}
}

// TestEnsamblaLaTramaCortadaPorElSistemaOperativo reproduce, byte por byte, lo
// que hace la bascula real de Paraiso de la Canasta.
//
// Emite `000.560<CR>` y Windows entregaba `0` en una lectura y `00.560<CR>` en la
// siguiente. El agente publicaba `00.560`: seis caracteres, con el primer digito
// perdido en el trozo anterior. La caja, configurada con `{W:7}`, no leia nada.
func TestEnsamblaLaTramaCortadaPorElSistemaOperativo(t *testing.T) {
	p := &puertoFalso{porLeer: [][]byte{
		[]byte("0"),
		[]byte("00.560\r"),
		[]byte("0"),
		[]byte("00.555\r"),
	}}
	b := NuevaBascula(cfgBascula(), func(ConfigBascula) (puertoSerie, error) { return p, nil }, nil)
	defer b.Cerrar()

	// Se espera a que el bucle consuma las cuatro lecturas.
	var got string
	for i := 0; i < 50; i++ {
		time.Sleep(20 * time.Millisecond)
		if trama, _, err := b.Leer(); err == nil && trama == "000.555\r" {
			got = trama
			break
		}
	}

	if got != "000.555\r" {
		trama, _, _ := b.Leer()
		t.Errorf("trama = %q, se esperaba %q: los trozos tienen que ensamblarse en tramas", trama, "000.555\r")
	}
}

// TestUnaBasculaSinDelimitadorSigueFuncionando: hay equipos que emiten ancho fijo
// sin terminador, y para esos no hay forma de saber donde corta una trama.
// Romperlos para arreglar el ensamblado seria cambiar un defecto por otro.
func TestUnaBasculaSinDelimitadorSigueFuncionando(t *testing.T) {
	p := &puertoFalso{porLeer: [][]byte{[]byte("0.100")}}
	b := NuevaBascula(cfgBascula(), func(ConfigBascula) (puertoSerie, error) { return p, nil }, nil)
	defer b.Cerrar()

	got, _, err := b.Leer()
	if err != nil {
		t.Fatal(err)
	}
	if got != "0.100" {
		t.Errorf("trama = %q, se esperaba %q", got, "0.100")
	}
}

func TestUnaBasculaDesconectadaNoInundaLaBitacora(t *testing.T) {
	// El archivo se trunca al pasar de 2 MB. Un error por reintento, cada
	// pocos segundos, se lleva por delante el historial en una noche -- y con
	// el, lo unico que sirve para diagnosticar por telefono.
	cfg := cfgBascula()
	var lineas int
	b := NuevaBascula(cfg,
		func(ConfigBascula) (puertoSerie, error) { return nil, errors.New("Serial port not found") },
		func(string, ...any) { lineas++ })

	for i := 0; i < 200; i++ {
		b.avisarFallo(errors.New("Serial port not found"))
	}

	if lineas != 1 {
		t.Errorf("se escribieron %d líneas para 200 intentos seguidos; se esperaba 1 hasta que venza el intervalo", lineas)
	}

	// Cuando vence el intervalo vuelve a avisar: un fallo que persiste no puede
	// desaparecer del registro para siempre.
	b.ultimoAviso = time.Now().Add(-2 * intervaloAviso)
	b.avisarFallo(errors.New("Serial port not found"))
	if lineas != 2 {
		t.Errorf("tras vencer el intervalo se esperaban 2 líneas y hay %d", lineas)
	}
}

func TestElPuertoFijoSeRespetaTalCual(t *testing.T) {
	// Quien escribe COM7 a mano manda: la busqueda automatica no puede
	// llevarse por delante una decision explicita del operario.
	cfg := cfgBascula()
	cfg.Puerto = "COM7"
	b := NuevaBascula(cfg, nil, nil)

	got, err := b.resuelta()
	if err != nil {
		t.Fatal(err)
	}
	if got.Puerto != "COM7" {
		t.Errorf("puerto = %q, se esperaba el configurado", got.Puerto)
	}
}

func TestConAutoSeBuscaLaBasculaEnCadaVuelta(t *testing.T) {
	// Con "auto" la resolucion ocurre dentro del bucle, no una vez al
	// arrancar: la bascula puede estar apagada al encender la caja, o alguien
	// puede moverla de enchufe a media mañana.
	cfg := cfgBascula()
	cfg.Puerto = "auto"
	b := NuevaBascula(cfg, nil, nil)

	got, err := b.resuelta()
	// En este equipo de desarrollo no hay enumeración, así que lo que se
	// comprueba es que "auto" NO se toma como si fuera el nombre de un puerto.
	if err == nil {
		t.Skip("hay enumeración de puertos en esta plataforma")
	}
	if got.Puerto == puertoAutomatico {
		t.Error("\"auto\" no puede llegar al sistema como si fuera un nombre de puerto")
	}
}

func TestLosMensajesDeBitacoraNoLlevanTildes(t *testing.T) {
	// La bitácora se lee en la consola de Windows, que decodifica con la página
	// de códigos del sistema: una tilde sale como un símbolo raro justo en el
	// archivo que alguien abre cuando algo no funciona. Ya se coló una vez.
	cfg := cfgBascula()
	cfg.Puerto = puertoAutomatico
	b := NuevaBascula(cfg, nil, nil)

	for _, texto := range []string{b.comoSeLlamaElPuerto()} {
		for i, r := range texto {
			if r > 127 {
				t.Errorf("carácter no ASCII %q en la posición %d de %q", r, i, texto)
			}
		}
	}
}
