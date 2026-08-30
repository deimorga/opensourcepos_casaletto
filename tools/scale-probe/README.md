# scale-probe — descubridor del protocolo serie de la báscula

Herramienta de diagnóstico de un solo uso. Se conecta a la báscula **ROCHI
RC-A01E** por su puerto COM virtual, barre configuraciones y estímulos, y deja
**un único archivo de texto** con todo lo que la báscula haya dicho.

Ese archivo es el entregable. Con él se desarrolla el intérprete de trama sin
volver a necesitar el equipo físico.

## Por qué existe

Solo hay **cinco minutos** de acceso a la báscula, prestada, una sola vez. No se
puede desarrollar durante esos cinco minutos: hay que capturarlo todo y trabajar
después con la captura. Si la captura sale incompleta, no hay segunda
oportunidad.

Todo el diseño sale de ahí:

| Decisión | Motivo |
|---|---|
| Nunca aborta | Un puerto que no abre o una configuración que falla se registran y se sigue. Terminar sin archivo es el peor resultado posible. |
| Escribe a disco tras cada evento | Si desconectan la báscula a los tres minutos, lo capturado hasta ahí ya está en disco. |
| No descarta ningún byte | Lo que hoy parece basura puede ser el delimitador de la trama. Todo va en hexadecimal y en ASCII. |
| Pide **dos** pesos distintos | Con un solo peso no hay forma de saber qué parte de la trama es el número. |
| Presupuesto de tiempo medido | El barrido completo cabe en menos de 4 minutos, con margen dentro de los 5. |

Contexto completo: `docs/Tecnico/venta-por-peso-y-hardware-de-caja.md`, §5.8 a §5.10c.

## Qué hace, en orden

1. **Detecta los puertos COM.** Si hay uno, lo toma. Si hay varios, los lista con
   su descripción de Windows (`USB-SERIAL CH340 (COM3)`) y marca el que parece la
   báscula. Si no hay ninguno, explica que falta el driver CH341SER.
2. **Barre seis configuraciones**, empezando por la única documentada por el
   fabricante:

   | Orden | Configuración | Por qué |
   |---|---|---|
   | 1 | `9600 8-N-1` | La del manual ROCHI. Confirmada para este equipo exacto. |
   | 2 | `9600 7-E-1` | Formato F de la tabla POS-II (emulación CAS PD-II). |
   | 3 | `9600 7-O-1` | Variante impar; algunos firmwares la usan. |
   | 4 | `4800 8-N-1` | Velocidad alterna común en la gama. |
   | 5 | `2400 7-N-1` | Herencia de básculas viejas. |
   | 6 | `19200 8-N-1` | Velocidad alta ocasional. |

3. En **cada** configuración: escucha pasiva sin enviar nada, y después sondeos
   activos uno por uno — `W`, `W`+CRLF, `$`, `ENQ` (0x05), `CR` (0x0D), `P`, `S`,
   `SI`+CRLF — registrando qué respondió a cada cual. *(Enviar bytes a un puerto
   no reprograma la báscula: reprogramarla exige su teclado físico.)*
4. **Captura guiada**: le pide al operario dos pesos distintos, con cuenta
   regresiva en pantalla. Cada ventana se parte en dos mitades: la primera es
   escucha pura (todo byte que llegue es indiscutiblemente iniciado por la
   báscula) y en la segunda se insiste con `W` por si el equipo solo habla por
   comando.
5. **Escribe el archivo** `captura-bascula-AAAAMMDD-HHMMSS.txt` junto al
   ejecutable, con el resumen legible arriba y el volcado completo debajo.

## Presupuesto de tiempo

Con los valores por omisión:

```
por configuración = 3.5s escucha
                  + 8 × (1.2s sondeo + 0.12s lectura residual)
                  + 0.5s abrir/cerrar puerto                    = 14.56s
barrido           = 6 × 14.56s                                  = 87.4s
captura guiada    = 2 pesos × 30s                               = 60.0s
                                                      TOTAL       147.4s  (2m27s)
```

**Medido, no estimado.** Una corrida completa de punta a punta contra el puerto
simulado en el peor caso —báscula muda, todas las ventanas agotadas— tarda
**147,4 s reales**, que coincide con el cálculo.

Quedan ~93 s de los 240 s de tope para que el operario lea las pantallas, y
~153 s de margen sobre los 300 s reales de acceso a la báscula.

Si alguien sube una espera con un parámetro y el plan deja de caber, el
planificador **recorta solo**: primero acorta la ventana guiada, después degrada
las configuraciones de menor prioridad a solo escucha pasiva, y por último las
descarta — pero `9600 8-N-1` con sondeos y la fase de pesos reales nunca se
eliminan, porque sin ellas el archivo no sirve. Cada recorte queda anotado en el
reporte. La prueba `TestDefaultTimingsFitTheBudget` falla si alguien rompe el
presupuesto.

## Compilar el .exe para Windows desde Mac o Linux

Requiere Go 1.25 o superior. No hace falta ningún compilador de C: la librería
serie evita cgo salvo en el enumerador de macOS, que no interviene al compilar
para Windows.

```sh
cd tools/scale-probe
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o scale-probe.exe .
```

Sale **un ejecutable de unos 2,7 MB, sin instalador y sin dependencias**. Se
copia tal cual al portátil y se hace doble clic.

Para un portátil viejo de 32 bits: `GOARCH=386`. Para Windows on ARM:
`GOARCH=arm64`.

Comprobaciones antes de entregarlo:

```sh
go test ./...          # pruebas de la lógica que no necesita báscula
go vet ./...
GOOS=windows go vet ./...
gofmt -l .             # no debe listar nada
```

### Ensayar sin báscula

```sh
go run . -simular          # la báscula simulada transmite sola (modo continuo)
go run . -simular-mudo     # solo responde a comandos: el caso más probable
go run . -simular -rapido  # lo mismo, con las esperas a la mitad
```

Recorre exactamente el mismo camino que el día real y escribe un archivo de
resultados de verdad. **Conviene ensayarlo con el operario antes del día de la
captura**, para que llegue sabiendo qué va a ver en pantalla.

### Parámetros

Ninguno es necesario: el uso normal es doble clic.

| Parámetro | Para qué |
|---|---|
| `-puerto COM3` | Forzar un puerto en vez de detectarlo. |
| `-listar` | Solo listar los puertos y salir. |
| `-escucha 3.5s` | Escucha pasiva por configuración. |
| `-sondeo 1.2s` | Espera de respuesta por sondeo. |
| `-residual 120ms` | Lectura corta antes de cada sondeo, para no perder bytes espontáneos. |
| `-ventana 30s` | Ventana de captura por cada peso. |
| `-pesos 2` | Cuántos pesos distintos se piden. |
| `-presupuesto 4m` | Tope de tiempo del barrido. |
| `-config-guiadas 2` | Cuántas configuraciones se usan en la fase guiada. |
| `-rapido` | Reduce todas las esperas a la mitad. |
| `-sin-pausa` | No esperar ENTER al final (ejecución automática). |

---

# GUÍA PARA QUIEN VA A USAR EL PROGRAMA

**Imprima esta página y llévela.** No hace falta saber de computadores.

Va a tener la báscula solo **cinco minutos**. El programa hace todo solo; usted
solo tiene que seguir lo que diga la pantalla.

## Antes de ir (hágalo el día anterior, con calma)

1. Copie el archivo **`scale-probe.exe`** al Escritorio del portátil.
2. Instale el driver de la báscula: **CH341SER**. Botón derecho sobre
   `SETUP.EXE` → **Ejecutar como administrador** → botón `INSTALL`.
3. **Reinicie el portátil.** Sin reiniciar, a veces no funciona.

## Los pasos, el día de la captura

1. **Conecte el cable USB** de la báscula al portátil.
2. **Encienda la báscula.** Espere a que la pantalla muestre ceros.
3. **Haga doble clic en `scale-probe.exe`.**
   Si Windows muestra un aviso azul de "Windows protegió su PC":
   haga clic en **Más información** y luego en **Ejecutar de todas formas**.
4. Si le muestra una lista de puertos, escriba el número que tiene el
   símbolo **`>`** al lado y presione ENTER. Ese es la báscula.
5. **Ponga un objeto sobre la báscula** (medio kilo o más: una bolsa de arroz, un
   par de papas) y **déjelo ahí**. Presione ENTER.
6. Espere. La pantalla va sola durante unos dos minutos. **No toque nada.**
7. Cuando le pida el **PESO 1**, deje el objeto encima y presione la tecla de la
   báscula que dice **TRANSMITIR** (en algunas dice `PRINT` o `IMPRIMIR`)
   **varias veces, una cada 3 segundos**, hasta que se acabe la cuenta regresiva.
8. Cuando le pida el **PESO 2**, **cambie el objeto por otro que pese
   claramente distinto** y repita lo mismo: presionar transmitir varias veces.

   > Esto es lo más importante de todo. Con dos pesos distintos se puede saber
   > qué parte de lo que manda la báscula es el número. Con uno solo, no.

9. Al final la pantalla le muestra **la ruta de un archivo** que termina en
   `.txt`. **Envíe ese archivo completo** por WhatsApp o correo. Está en el
   Escritorio, junto al programa, y su nombre empieza por `captura-bascula-`.
10. Presione ENTER para cerrar la ventana.

## Si algo sale mal

| Lo que ve | Qué hacer |
|---|---|
| **"NO SE ENCONTRÓ NINGÚN PUERTO COM"** | Falta el driver CH341SER, o el cable no es de datos, o la báscula está apagada. Instale el driver, **reinicie**, y vuelva a intentar. Pruebe otro puerto USB y otro cable. |
| La ventana **se abre y se cierra sola** | Fue un error muy temprano. Abra el Símbolo del sistema, arrastre el `.exe` a la ventana y presione ENTER: así el mensaje se queda a la vista. |
| Windows dice **"protegió su PC"** | Normal: el programa no está firmado. **Más información** → **Ejecutar de todas formas**. |
| Dice **"no se pudo abrir el puerto"** | Otro programa está usando la báscula. Cierre cualquier programa de básculas o terminal serial y vuelva a intentar. |
| Al final dice **"NO SE RECIBIÓ NINGÚN BYTE"** | **Envíe el archivo igual.** Deja constancia de todo lo que se probó, y eso también sirve. |
| Dice **"todas las tramas son iguales"** | Los dos pesos no eran distintos, o la báscula no llegó a estabilizarse. Si todavía tiene la báscula, **repita** con dos objetos de peso claramente diferente. |
| Se **desconectó la báscula** a mitad | El archivo ya está en el Escritorio con todo lo capturado hasta ese momento. **Envíelo igual.** |

**Regla de oro: si hay un archivo `captura-bascula-*.txt`, envíelo.** Aunque
parezca que salió mal, aunque esté incompleto. Siempre sirve más que nada.
