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
