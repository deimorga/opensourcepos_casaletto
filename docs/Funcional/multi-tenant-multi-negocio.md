# Multi-tenant: de Casaletto a plataforma SaaS multi-negocio

> **Documento vivo.** Se actualiza al cierre de cada fase del desarrollo si algo cambia respecto a lo aquí descrito. Ver también el documento técnico: `docs/Tecnico/multi-tenant-arquitectura.md`.

## 1. Contexto y motivación

opensourcepos_casaletto es un fork de Open Source POS que hoy opera en producción para un único negocio: Casaletto. El plan de negocio es empezar a vender la misma solución a otros restaurantes-cliente, manteniendo Casaletto como cliente propio y primero.

Escala esperada: 10-20 negocios-cliente en el primer año, hasta ~100 eventualmente. Cada negocio-cliente puede tener, además, varias sedes propias (sucursales) — esto **ya existe hoy** en el sistema vía el concepto de "sede" (`stock_locations`) y no se rediseña; el cambio de este proyecto agrega una capa por encima: la de **negocio** (tenant).

Distinción clave que usa este documento:
- **Negocio / tenant**: un cliente que le compra la plataforma (ej. Casaletto, o el restaurante que se sume después). Cada negocio es independiente y no comparte datos con otro.
- **Sede**: una sucursal física de un mismo negocio (ej. Casaletto Centro, Casaletto Norte). Varias sedes pueden pertenecer al mismo negocio.

## 2. Objetivo

Convertir la plataforma en un SaaS multi-negocio donde:
- Cada negocio-cliente tiene sus datos completamente aislados de los demás (ventas, inventario, empleados, configuración).
- Dar de alta un negocio nuevo es una operación de rutina, sin migraciones de datos, sin tocar infraestructura, sin afectar a los negocios ya activos.
- Casaletto sigue operando exactamente igual que hoy durante y después de la transición — se convierte en el primer negocio-cliente ("tenant #1") de la plataforma, sin migración de datos ni corte de servicio.

## 3. Roles

| Rol | Quién es | Qué cambia respecto a hoy |
|---|---|---|
| **Empleado de un negocio** | Mesero, cajero, administrador del restaurante | **Nada.** Sigue entrando por la URL de su negocio (ej. `casaletto.midominio.com`) con su usuario y contraseña de siempre. Sus permisos y sedes siguen funcionando igual. |
| **Dueño de negocio** | Propietario de uno o más negocios-cliente registrados en la plataforma | **Nuevo.** Si tiene un solo negocio, no ve diferencia. Si tiene más de un negocio (ej. compró dos franquicias distintas), entra por un login neutral y elige a cuál de sus negocios quiere entrar. |
| **Administrador de plataforma** | Equipo de Casaletto / operador del SaaS | **Nuevo.** Login separado, exclusivo para gestionar el alta de negocios nuevos en la plataforma. No puede "entrar como" un negocio-cliente para operar en su nombre (fuera de alcance de este proyecto). |

## 4. Flujos funcionales

### 4.1 Login de empleado (sin cambios)
El empleado entra por la URL de su negocio, pone usuario/contraseña, y usa el sistema exactamente igual que hoy — ventas, inventario, sedes, reportes, todo sin diferencia perceptible.

### 4.2 Login de dueño con más de un negocio (nuevo)
1. El dueño entra a un login neutral de la plataforma (no a la URL de un negocio específico).
2. Si su cuenta está ligada a un solo negocio, entra directo a ese negocio.
3. Si está ligada a varios, ve un selector de negocio.
4. Al elegir uno, se le redirige a la URL de ese negocio, donde vuelve a autenticarse con las credenciales de empleado de ese negocio específico (no es un "entrar sin contraseña" — es una comodidad de no tener que recordar la URL de cada negocio, no un inicio de sesión único real).

### 4.3 Alta de un negocio nuevo (nuevo)
El administrador de plataforma da de alta un negocio-cliente nuevo desde su panel: define un identificador único (slug/subdominio), y el sistema aprovisiona todo lo necesario para que ese negocio pueda empezar a operar de inmediato, con sus propios datos completamente separados de cualquier otro negocio.

### 4.4 Gestión de sedes dentro de un negocio (sin cambios)
Cada negocio sigue pudiendo tener varias sedes, gestionarlas, y filtrar reportes/ventas/inventario por sede, exactamente como ya funciona hoy.

## 5. Alcance y fuera de alcance

**Dentro de alcance de este proyecto:**
- Aislamiento completo de datos entre negocios.
- Alta de negocios nuevos sin afectar a los existentes.
- Login de dueño con selector simple entre sus negocios.
- Panel de administrador de plataforma para gestionar el registro de negocios.
- Actualización del stack tecnológico (ver documento técnico) como parte del mismo trabajo, dado que se toca la misma infraestructura de base de datos y despliegue.

**Fuera de alcance (decisión explícita, no se construye en este proyecto):**
- Inicio de sesión único real (SSO) entre negocios de un mismo dueño — la versión actual requiere volver a autenticarse al cambiar de negocio.
- "Entrar como" un negocio-cliente desde el panel de administrador de plataforma (impersonation de soporte).
- Analítica o reportes consolidados entre varios negocios-cliente (se podría resolver más adelante con una integración separada, sin necesidad de rediseñar el aislamiento de datos).

## 6. Estado de avance

Fase 0 (documentación), Fase 1 (cerrar huecos de sede en ventas, turnos, gastos, recepciones y mesas) y Fase 2 (actualización de MariaDB y PHP a versiones con soporte vigente) completas — sin impacto visible para el usuario final. Detalle técnico en `docs/Tecnico/multi-tenant-arquitectura.md`.

**Importante para el despliegue de la Fase 2 en staging/producción** (no en desarrollo local): el salto de versión de MariaDB requiere un procedimiento cuidadoso porque esos ambientes tienen datos reales de Casaletto — no es un cambio que se aplique solo con el deploy automático, hay que planificarlo como una ventana de mantenimiento explícita.

## 7. Continuidad de Casaletto

Un punto central de este proyecto: **Casaletto no migra datos a ningún lado.** El negocio actual se convierte en el primer negocio-cliente de la plataforma usando su base de datos actual tal cual está. El resto de los negocios se suman después, cada uno con sus propios datos, sin tocar los de Casaletto.
