package main

import (
	"encoding/hex"
	"math/rand"
	"strings"
	"testing"
)

// moriscoFrame es la trama documentada en §5.10b del diseño técnico para el
// formato continuo de la familia POS-II: bandera N, dos dígitos de kilos,
// punto, tres de gramos, dos espacios, LF, CR. Ejemplo del manual: 12,395 kg.
var moriscoFrame = []byte("N12.395  \x0a\x0d")

func TestFormatHexDumpMoriscoFrame(t *testing.T) {
	got := FormatHexDump(moriscoFrame, "")
	// 11 bytes ocupan 34 de las 49 columnas del campo hexadecimal; quedan 15
	// de relleno, más el espacio que precede a la columna ASCII.
	want := "0000  4e 31 32 2e 33 39 35 20  20 0a 0d" + strings.Repeat(" ", 17) + "|N12.395  ..|\n"
	if got != want {
		t.Errorf("volcado incorrecto\n got: %q\nwant: %q", got, want)
	}
}

func TestFormatHexDumpEmpty(t *testing.T) {
	if got := FormatHexDump(nil, "  "); got != "  (sin datos)\n" {
		t.Errorf("got %q", got)
	}
}

func TestFormatHexDumpIndent(t *testing.T) {
	got := FormatHexDump([]byte{0x41}, "      ")
	if !strings.HasPrefix(got, "      0000  41 ") {
		t.Errorf("no respetó la sangría: %q", got)
	}
}

func TestFormatHexDumpWrapsAtSixteen(t *testing.T) {
	b := make([]byte, 17)
	for i := range b {
		b[i] = byte(i)
	}
	lines := strings.Split(strings.TrimRight(FormatHexDump(b, ""), "\n"), "\n")
	if len(lines) != 2 {
		t.Fatalf("esperaba 2 líneas para 17 bytes, hubo %d: %v", len(lines), lines)
	}
	if !strings.HasPrefix(lines[1], "0010  10 ") {
		t.Errorf("la segunda línea debe arrancar en el desplazamiento 0010: %q", lines[1])
	}
}

// TestFormatHexDumpNeverLosesBytes es la prueba que de verdad importa.
//
// El criterio de diseño de toda la herramienta es que no se puede perder un
// solo byte: la báscula se tiene cinco minutos y lo que parezca basura hoy
// puede ser el delimitador de la trama. Esta prueba reconstruye la entrada a
// partir de la columna hexadecimal y exige igualdad exacta, para cualquier
// contenido, incluidos los bytes no imprimibles.
func TestFormatHexDumpNeverLosesBytes(t *testing.T) {
	rng := rand.New(rand.NewSource(1))
	for _, n := range []int{1, 5, 15, 16, 17, 31, 64, 255} {
		in := make([]byte, n)
		rng.Read(in)
		var recovered []byte
		for _, line := range strings.Split(strings.TrimRight(FormatHexDump(in, ""), "\n"), "\n") {
			// Recortar el desplazamiento y la columna ASCII.
			body := line[len("0000  "):]
			if i := strings.LastIndex(body, "|"); i >= 0 {
				body = body[:strings.Index(body, "|")]
			}
			for _, tok := range strings.Fields(body) {
				b, err := hex.DecodeString(tok)
				if err != nil {
					t.Fatalf("token hexadecimal inválido %q: %v", tok, err)
				}
				recovered = append(recovered, b...)
			}
		}
		if string(recovered) != string(in) {
			t.Errorf("n=%d: se perdieron bytes\n got %x\nwant %x", n, recovered, in)
		}
	}
}

func TestASCIIPrintable(t *testing.T) {
	got := ASCIIPrintable([]byte{0x00, 0x41, 0x1f, 0x7e, 0x7f, 0x20, 0xff})
	if got != ".A.~. ." {
		t.Errorf("got %q, want %q", got, ".A.~. .")
	}
}

func TestEscaped(t *testing.T) {
	cases := []struct{ in, want string }{
		{"N12.395  \x0a\x0d", "N12.395<SP><SP><LF><CR>"},
		{"\x02ABC\x03", "<STX>ABC<ETX>"},
		{"\x05", "<ENQ>"},
		{"\xff\x00", "<FF><00>"},
		{"", ""},
	}
	for _, c := range cases {
		if got := Escaped([]byte(c.in)); got != c.want {
			t.Errorf("Escaped(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}

func TestGuessFramesSplitsOnCRLF(t *testing.T) {
	// Dos lecturas del mismo peso y una de otro, como llegaría en modo continuo.
	stream := []byte("N12.395  \x0a\x0dN12.395  \x0a\x0dN01.250  \x0a\x0d")
	frames := GuessFrames(stream)
	if len(frames) != 2 {
		t.Fatalf("esperaba 2 formas distintas, hubo %d: %+v", len(frames), frames)
	}
	// La más repetida va primero.
	if frames[0].Count != 2 || frames[0].Text != "N12.395<SP><SP>" {
		t.Errorf("primera trama = %+v", frames[0])
	}
	if frames[1].Count != 1 || frames[1].Text != "N01.250<SP><SP>" {
		t.Errorf("segunda trama = %+v", frames[1])
	}
}

func TestGuessFramesIgnoresEmptySegments(t *testing.T) {
	// Delimitadores consecutivos no deben producir tramas vacías.
	frames := GuessFrames([]byte("\x0d\x0a\x0d\x0aAB\x0d\x0a\x0d\x0a"))
	if len(frames) != 1 || frames[0].Text != "AB" || frames[0].Count != 1 {
		t.Errorf("got %+v", frames)
	}
}

func TestGuessFramesWithoutDelimiter(t *testing.T) {
	// Si la báscula no usa CR/LF, todo el flujo es una sola trama candidata.
	// Sigue siendo útil: el volcado completo va aparte.
	frames := GuessFrames([]byte("12345"))
	if len(frames) != 1 || frames[0].Text != "12345" {
		t.Errorf("got %+v", frames)
	}
}

func TestGuessFramesEmpty(t *testing.T) {
	if got := GuessFrames(nil); len(got) != 0 {
		t.Errorf("esperaba ninguna trama, hubo %d", len(got))
	}
}

func TestTransliterate(t *testing.T) {
	in := "Ponga algo sobre la báscula y presione transmitir. ¿Está listo? —Sí"
	got := Transliterate(in)
	want := "Ponga algo sobre la bascula y presione transmitir. ?Esta listo? -Si"
	if got != want {
		t.Errorf("got  %q\nwant %q", got, want)
	}
	for _, r := range got {
		if r > 127 {
			t.Errorf("quedó un carácter no ASCII: %q en %q", r, got)
		}
	}
}
