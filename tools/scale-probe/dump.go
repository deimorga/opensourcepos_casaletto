package main

import (
	"fmt"
	"sort"
	"strings"
)

// Logica pura de presentacion. Nada de esto toca hardware ni disco, y todo
// esta cubierto por pruebas: es lo unico que se puede verificar sin bascula.

const hexBytesPerLine = 16

// FormatHexDump rinde b en el formato clasico de `hexdump -C`: desplazamiento,
// 16 bytes en hexadecimal con un hueco a la mitad, y la columna ASCII.
//
// Los bytes no imprimibles salen como '.' en la columna ASCII pero SIEMPRE
// estan completos en la columna hexadecimal. Es deliberado: lo que hoy parece
// basura suele ser el delimitador de la trama, y este archivo es la unica
// oportunidad de capturarlo.
func FormatHexDump(b []byte, indent string) string {
	if len(b) == 0 {
		return indent + "(sin datos)\n"
	}
	var sb strings.Builder
	for off := 0; off < len(b); off += hexBytesPerLine {
		end := off + hexBytesPerLine
		if end > len(b) {
			end = len(b)
		}
		chunk := b[off:end]

		sb.WriteString(indent)
		fmt.Fprintf(&sb, "%04x  ", off)

		for i := 0; i < hexBytesPerLine; i++ {
			if i == hexBytesPerLine/2 {
				sb.WriteByte(' ')
			}
			if i < len(chunk) {
				fmt.Fprintf(&sb, "%02x ", chunk[i])
			} else {
				sb.WriteString("   ")
			}
		}
		sb.WriteString(" |")
		sb.WriteString(ASCIIPrintable(chunk))
		sb.WriteString("|\n")
	}
	return sb.String()
}

// ASCIIPrintable devuelve los bytes imprimibles tal cual y el resto como '.'.
func ASCIIPrintable(b []byte) string {
	out := make([]byte, len(b))
	for i, c := range b {
		if c >= 0x20 && c <= 0x7e {
			out[i] = c
		} else {
			out[i] = '.'
		}
	}
	return string(out)
}

// Escaped rinde los bytes en una sola linea, con los de control nombrados:
// "N12.395<SP><SP><LF><CR>". Sirve para el resumen, donde una tabla de
// hexadecimal no se lee de un vistazo.
func Escaped(b []byte) string {
	var sb strings.Builder
	for _, c := range b {
		switch {
		case c == 0x20:
			sb.WriteString("<SP>")
		case c == 0x0d:
			sb.WriteString("<CR>")
		case c == 0x0a:
			sb.WriteString("<LF>")
		case c == 0x02:
			sb.WriteString("<STX>")
		case c == 0x03:
			sb.WriteString("<ETX>")
		case c == 0x05:
			sb.WriteString("<ENQ>")
		case c == 0x06:
			sb.WriteString("<ACK>")
		case c == 0x09:
			sb.WriteString("<TAB>")
		case c >= 0x21 && c <= 0x7e:
			sb.WriteByte(c)
		default:
			fmt.Fprintf(&sb, "<%02X>", c)
		}
	}
	return sb.String()
}

// FrameGuess es una trama candidata vista en el flujo capturado.
type FrameGuess struct {
	Raw   []byte
	Text  string
	Count int
}

// GuessFrames parte el flujo capturado por CR y LF y agrupa las tramas
// identicas. Es una AYUDA para quien lea el archivo, no una interpretacion:
// el volcado hexadecimal completo va aparte y manda sobre esto.
//
// Se parte por CR y LF porque la trama documentada para la familia POS-II
// termina en <LF><CR> (§5.10b). Si el equipo real usa otro delimitador, este
// agrupamiento saldra pobre pero el volcado seguira intacto.
func GuessFrames(b []byte) []FrameGuess {
	var frames [][]byte
	cur := make([]byte, 0, 32)
	for _, c := range b {
		if c == 0x0d || c == 0x0a {
			if len(cur) > 0 {
				frames = append(frames, append([]byte(nil), cur...))
				cur = cur[:0]
			}
			continue
		}
		cur = append(cur, c)
	}
	if len(cur) > 0 {
		frames = append(frames, append([]byte(nil), cur...))
	}

	idx := map[string]int{}
	var out []FrameGuess
	for _, f := range frames {
		k := string(f)
		if i, ok := idx[k]; ok {
			out[i].Count++
			continue
		}
		idx[k] = len(out)
		out = append(out, FrameGuess{Raw: f, Text: Escaped(f), Count: 1})
	}
	// Mas repetidas primero: en modo continuo la trama real domina el conteo.
	sort.SliceStable(out, func(i, j int) bool { return out[i].Count > out[j].Count })
	return out
}

// transliterations mapea lo que el espanol necesita a ASCII plano.
//
// Existe porque una consola de Windows en su pagina de codigos heredada (850 o
// 437) convierte los acentos UTF-8 en mojibake, y el operario no es tecnico: si
// lee "Ponga algo sobre la bÃ¡scula" va a pensar que el programa esta roto. Se
// usa SOLO si no se pudo poner la consola en UTF-8; el archivo de resultados
// siempre se escribe con acentos.
var transliterations = strings.NewReplacer(
	"á", "a", "é", "e", "í", "i", "ó", "o", "ú", "u", "ü", "u", "ñ", "n",
	"Á", "A", "É", "E", "Í", "I", "Ó", "O", "Ú", "U", "Ü", "U", "Ñ", "N",
	"¿", "?", "¡", "!", "°", "o", "—", "-", "–", "-", "…", "...",
	"“", "\"", "”", "\"", "‘", "'", "’", "'", "«", "\"", "»", "\"",
)

// Transliterate quita los acentos para que la consola heredada de Windows no
// muestre basura.
func Transliterate(s string) string { return transliterations.Replace(s) }
