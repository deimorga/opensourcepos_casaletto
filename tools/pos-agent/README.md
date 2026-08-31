# pos-agent — el programa de la caja

Un solo ejecutable en el PC de la caja. Existe por un muro concreto: **el
navegador no puede abrir un puerto serie ni mandar bytes crudos a una
impresora**. Todo lo demás del sistema es web; esto es lo único que tiene que
vivir en el equipo del cliente.

Diseño completo: `docs/Tecnico/venta-por-peso-y-hardware-de-caja.md`, §5.

## Tres funciones, una sola instalación

| Función | Qué reemplaza |
|---|---|
| Leer la báscula | Que el cajero digite el peso a mano |
| Imprimir el recibo en ESC/POS crudo | La impresión del navegador y su diálogo |
| Abrir el cajón (`ESC p`) | Nada. Hoy no existe de ninguna forma |

Construirlo solo para la báscula sería desperdiciar el 80 % del trabajo, que es
la instalación y la distribución, no leer un puerto.

## Lo que este programa NO hace

**No interpreta la trama de la báscula.** Devuelve el texto crudo y el servidor
lo interpreta con `Token_lib::parse_scale()` y el patrón configurado en la
pantalla de báscula.

Es deliberado y conviene no "mejorarlo": así el agente queda tonto y estable, y
todo lo que cambia entre básculas vive en configuración del sistema, **donde se
corrige sin volver a pisar la caja**. El día que un cliente tenga otra báscula,
eso es una pantalla que se llena, no una versión que se distribuye.

## Contrato con la página

WebSocket en `ws://127.0.0.1:7878/ws`, mensajes JSON.

| Operación | Dirección | Contenido |
|---|---|---|
| `hello` | agente → página | `version`, `scale`, `printer` — se envía al conectar |
| `scale.read` | página → agente | pide el peso |
| `scale.weight` | agente → página | `raw`: la trama **tal cual**; `at`: cuándo llegó |
| `printer.raw` | página → agente | `data`: los bytes del recibo en base64 |
| `drawer.open` | página → agente | sin parámetros |
| `error` | agente → página | `code` + `message` |

El campo `id` viaja de ida y vuelta sin tocarse, para casar cada respuesta con
su petición.

**Los códigos de error se distinguen a propósito.** `sin_bascula` es una caja
que no tiene báscula —normal, y la página debe pedir el peso a mano—;
`sin_lectura` es una báscula que está y no contesta, que sí es una falla que
alguien tiene que atender. Confundirlas convierte una caja sin hardware en una
caja averiada.

Además, `GET /estado` devuelve un JSON de diagnóstico. Sin cabecera `Origin`
responde siempre: es la forma de comprobarlo con `curl` desde la propia máquina
el día del montaje.

## Seguridad

**El control es la lista de orígenes permitidos, y no es un detalle.** El
navegador deja que *cualquier* página hable con `127.0.0.1`, así que sin esa
puerta un anuncio o un enlace que el cajero abra podría pedirle a la caja que
**abra el cajón del dinero**.

- Se compara el origen **completo, con esquema**. `http://` no es `https://`.
- **Sin orígenes configurados no entra nadie.** Un agente recién instalado no
  puede ser una puerta abierta que nadie recuerda haber dejado.
- Escucha **solo en loopback**. Eso además evita que el Firewall de Windows
  pregunte nada al arrancar: solo pregunta por programas que escuchan hacia
  afuera.

## Configuración

`config.json`, junto al ejecutable. Los nombres están en español porque el día
del montaje alguien puede tener que abrirlo con el Bloc de notas frente al
cliente. Se genera con `pos-agent.exe -crear-config`.

```json
{
  "puerto_http": 7878,
  "origenes_permitidos": ["https://elnegocio.ospos-saas.micronuba.net"],
  "bascula": {
    "puerto": "COM3",
    "baudios": 9600, "bits_datos": 8, "paridad": "N", "bits_parada": 1,
    "comando": "",
    "frescura_ms": 3000,
    "espera_ms": 2500
  },
  "impresora": { "nombre": "POS-58", "abrir_cajon": [27, 112, 0, 25, 250] },
  "actualizacion": { "manifiesto": "", "cada_horas": 24 }
}
```

- **`puerto` vacío** = esta caja no tiene báscula. Es el estado de fábrica.
- **`comando`** es para las básculas que sólo hablan cuando se les pregunta (el
  formato por comando `W` del §5.10b). Vacío = la báscula transmite sola.
  Acepta `<CR>`, `<LF>`, `<ENQ>`, `<STX>`, `<ETX>`.
- **`frescura_ms`**: edad máxima de una trama para darla por buena. Con una
  báscula que transmite sola, el peso aparece instantáneo.
- **`abrir_cajon`**: el cajón **no cuelga del PC**, va a la impresora por RJ11.
  Abrirlo es imprimir una secuencia de control.

Un archivo ausente **no impide arrancar**, y uno roto tampoco: se sigue con los
valores por omisión y queda en la bitácora. Un agente muerto es invisible para
el cajero, que sólo ve que no hay peso.

## Bitácora

`pos-agent.log`, junto al ejecutable. Se trunca al pasar de 2 MB.

Arranca sin ventana, así que **sin este archivo no hay forma de saber por qué no
funciona**. Es lo primero que hay que mirar.

## Actualización a distancia

Está desde el primer día por aritmética, no por elegancia: con tres clientes son
doce cajas, y agregarla después significa rehacer el instalador y volver a pisar
las doce.

Se consulta un manifiesto (`{"version","url","sha256"}`), se descarga, **se
verifica el hash** y se aparta el ejecutable actual a `.viejo`. Windows deja
renombrar un ejecutable en uso, así que el proceso en marcha sigue corriendo
—nadie interrumpe una venta a medias— y la versión nueva entra sola en el
siguiente arranque. Una caja se apaga todas las noches; eso basta.

**Manifiesto y descarga tienen que ser HTTPS.** Sin cifrado, cualquiera en la red
del negocio podría servir su propio ejecutable, y el hash no salva nada si quien
lo publica es el atacante.

**Sale de fábrica apagada** (`manifiesto` vacío): mientras no exista un lugar
donde publicarlo, apuntar a la nada sería peor.

## Compilar

```sh
GOOS=windows GOARCH=386 CGO_ENABLED=0 \
  go build -trimpath -ldflags "-s -w -X main.version=1.0.0" -o pos-agent.exe .
```

**`GOARCH=386` es deliberado**, igual que en `scale-probe`: un binario de 32 bits
corre en cualquier Windows, y no hay nada aquí que gane con 64.

Se prueba sin hardware con `-simular`, que finge una báscula que transmite sola.
La trama que emite es **plausible pero inventada** —el formato real de esta
báscula sigue sin conocerse— y sirve para probar el transporte, nunca para
deducir el patrón.

## Pruebas

```sh
go test -race ./...
```
