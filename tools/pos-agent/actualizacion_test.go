package main

import (
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestNoSeInstalaNadaQueNoCoincidaConSuHash(t *testing.T) {
	// Un hash que no cuadra no es un fallo de red: o el manifiesto miente o
	// alguien cambió el archivo por el camino. En los dos casos, no se instala.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("ejecutable falso"))
	}))
	defer srv.Close()

	exe := filepath.Join(t.TempDir(), "pos-agent.exe")
	os.WriteFile(exe, []byte("el de siempre"), 0o755)

	err := aplicar(manifiesto{
		Version: "9.9.9",
		URL:     strings.Replace(srv.URL, "http://", "https://", 1),
		SHA256:  "0000000000000000000000000000000000000000000000000000000000000000",
	}, srv.Client(), exe, func(string, ...any) {})

	if err == nil {
		t.Fatal("se aceptó un archivo con hash equivocado")
	}
	quedo, _ := os.ReadFile(exe)
	if string(quedo) != "el de siempre" {
		t.Errorf("el ejecutable quedó en %q; tenía que quedar intacto", string(quedo))
	}
}

func TestLaDescargaTieneQueSerPorHTTPS(t *testing.T) {
	// Sin HTTPS, cualquiera en la red del negocio puede servir su propio
	// ejecutable, y el hash no salva nada si quien lo publica es el atacante.
	exe := filepath.Join(t.TempDir(), "pos-agent.exe")
	os.WriteFile(exe, []byte("intacto"), 0o755)

	if err := aplicar(manifiesto{Version: "9.9.9", URL: "http://ejemplo.test/a.exe", SHA256: "x"},
		&http.Client{Timeout: time.Second}, exe, func(string, ...any) {}); err == nil {
		t.Fatal("se aceptó una descarga sin cifrar")
	}
	if err := comprobarUnaVez("http://ejemplo.test/manifiesto.json", func(string, ...any) {}); err == nil {
		t.Fatal("se aceptó un manifiesto sin cifrar")
	}
}

func TestLaVersionNuevaEntraSinInterrumpirLaVenta(t *testing.T) {
	// El binario en marcha se aparta a .viejo y el nuevo ocupa su lugar. Nadie
	// reinicia nada: la versión nueva entra en el siguiente arranque de la caja.
	nuevo := []byte("ejecutable nuevo")
	suma := sha256.Sum256(nuevo)

	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write(nuevo)
	}))
	defer srv.Close()

	exe := filepath.Join(t.TempDir(), "pos-agent.exe")
	os.WriteFile(exe, []byte("ejecutable viejo"), 0o755)

	if err := aplicar(manifiesto{
		Version: "9.9.9",
		URL:     srv.URL + "/pos-agent.exe",
		SHA256:  hex.EncodeToString(suma[:]),
	}, srv.Client(), exe, func(string, ...any) {}); err != nil {
		t.Fatal(err)
	}

	quedo, _ := os.ReadFile(exe)
	if string(quedo) != "ejecutable nuevo" {
		t.Errorf("el ejecutable quedó en %q", string(quedo))
	}
	apartado, err := os.ReadFile(exe + ".viejo")
	if err != nil || string(apartado) != "ejecutable viejo" {
		t.Errorf("el anterior tenía que quedar apartado como .viejo, y quedó %q (%v)", string(apartado), err)
	}
	if _, err := os.Stat(exe + ".descargando"); !os.IsNotExist(err) {
		t.Error("quedó basura de la descarga")
	}
}
