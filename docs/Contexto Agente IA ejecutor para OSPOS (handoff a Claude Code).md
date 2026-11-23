# Contexto: Agente IA ejecutor para OSPOS (handoff a Claude Code)

Documento de traspaso desde una conversación en Claude.ai hacia Claude Code. No reemplaza el CLAUDE.md existente del repo; es contexto adicional para esta iniciativa. Repo local: opensourcepos\_casaletto (carpeta Proyectos en el Mac de Deiby).

## 1. Quién y para qué

Deiby Moreno, líder técnico y de arquitectura de Micronuba SAS (Bogotá, Colombia), quiere un piloto de un agente IA que apoye la operativa de OSPOS, el POS de Micronuba para restaurantes, minimercados, tiendas y bares (en este repo, la implementación para Casaletto/Casalito).

La idea inicial: que el vendedor, estando en el módulo de ventas, pueda hacer la venta por voz (o texto) además de operando la interfaz. Va diciendo qué vende y en qué cantidades; el agente consulta la base de datos, confirma que los productos existen y las cantidades, y con aprobación del vendedor genera la venta. El vendedor sigue pudiendo vender operando la plataforma directamente: el agente es una vía adicional, no un reemplazo.

## 2. Aclaraciones clave que dio Deiby (críticas, no perder)

1. **No es un asistente de funcionalidad.** No explica cómo usar OSPOS ni da instrucciones. Debe **ejecutar** operaciones según las solicitudes del vendedor.
2. **Referencia de arquitectura: el agente inteligente de PLAGIE.** PLAGIE ya tiene un agente que ejecuta operaciones (no asiste con funcionalidad). Aquí se busca algo muy parecido. Revisar cómo está construido en el repo de PLAGIE y replicar el patrón donde aplique.
3. **El agente debe conocer cómo se hace realmente la venta**: cómo termina ingresando la información en la base de datos y por medio de qué APIs/endpoints. No "propone"; conoce el proceso y lo ejecuta.
4. **Validaciones internas, bajo premisas de la operación de venta**: que el producto exista, que haya stock, precio vigente, cantidades razonables, y varias variaciones y premisas más según la interna de venta de OSPOS.
5. **Punto de control obligatorio del vendedor (stopper).** Una vez el agente recibe y arma el pedido, se detiene y muestra el resumen. El vendedor lo revisa y puede: aprobar (se ejecuta la venta), pedir modificaciones (se ajusta y vuelve a mostrar), o cancelar. Solo con aprobación explícita se registra la venta. Analogía: transacción de base de datos donde todo está listo en memoria y el COMMIT lo da el vendedor.

## 3. Flujo definido

1. El vendedor da instrucciones (voz o texto en lenguaje natural), en el contexto de la ventana/módulo en que está.
2. El agente interpreta y valida internamente contra la base de datos real: producto existe, stock suficiente, precio vigente y activo, cantidad en rango normal, cliente si el flujo lo requiere.
3. Punto de control: el agente muestra el resumen del pedido (ítems, cantidades, precios, total) y se detiene.
4. El vendedor aprueba, modifica o cancela.
5. Si aprueba, el agente ejecuta la venta por las mismas APIs/flujo que usa OSPOS y confirma que quedó registrada.

Casos a manejar: instrucciones ambiguas (por ejemplo "dos de lo de siempre") deben generar una aclaración, nunca una suposición; producto inexistente o sin stock se informa y se espera decisión; si el agente falla, el vendedor sigue operando OSPOS normalmente (fallback).

## 4. Arquitectura propuesta para el piloto

- **Widget flotante** en el módulo de ventas de OSPOS (entrada de texto, luego micrófono).
- **Intérprete/cerebro:** Claude API con herramientas (tool use).
- **Herramientas del agente:** consulta de productos, stock y precios; armado del pedido; ejecución de la venta contra los endpoints/controladores reales de OSPOS. Opción: MCP server ligero o endpoints PHP dedicados, siguiendo el patrón del agente de PLAGIE.
- **Contexto de ventana:** el agente recibe qué módulo/pantalla está activo para saber qué operaciones tiene disponibles.
- **Punto de control en UI:** resumen con botones aprobar, modificar, cancelar (y comandos de voz equivalentes en Fase 2).
- **Voz (Fase 2):** speech-to-text (por ejemplo Whisper o Deepgram) alimenta el mismo pipeline de texto. Latencia objetivo del ciclo menor a unos 3 segundos para no frenar la caja.

## 5. Fases

- **Fase 1 (MVP, solo texto):** widget donde el vendedor escribe; el agente valida, muestra el punto de control y ejecuta. Valida todo el flujo sin la complejidad de la voz.
- **Fase 2 (voz):** botón de micrófono, STT, mismo pipeline, comandos de voz para confirmar o cancelar.

## 6. Riesgos y cuidados

- Latencia en caja.
- Ambigüedad del lenguaje natural: preguntar, no asumir.
- Nunca registrar una venta sin aprobación explícita del vendedor.
- Respetar permisos y autenticación del vendedor logueado; el agente actúa con el alcance de ese usuario.
- Auditoría: dejar trazabilidad de que la venta fue originada por el agente y aprobada por el vendedor.
- Fallback siempre disponible a la operación manual.

## 7. Lo que Claude Code debe explorar primero

1. Estructura del repo y cómo está desplegado (Dockerfile, docker-compose.prod.yml y staging, ramas).
2. Ciclo de venta completo: controlador de ventas, manejo de ítems, cierre, pagos, descuento de inventario.
3. Cómo se consultan productos, stock y precios; reglas de validación existentes.
4. Qué APIs o rutas existen para ventas y cuáles habría que crear o exponer; autenticación y sesión.
5. Dónde insertar el widget en el frontend del módulo de ventas sin romper el flujo actual.
6. El agente de PLAGIE (repo de PLAGIE) como referencia de patrón.

Pregunta abierta que quedó pendiente: ¿OSPOS expone hoy una API REST para operaciones de venta, o las acciones ocurren directamente en controladores y base de datos? De eso depende si hace falta una capa de endpoints antes del agente.

## 8. Cómo trabajar en esta sesión

- Empezar leyendo este documento y el CLAUDE.md existente del repo.
- Primero explorar y mapear (sección 7); después proponer el diseño concreto para validar con Deiby antes de escribir código.
- Responder en español.
