
[← Volver a la Guía de Uso](Getting-Started-usage) | [Inicio](Home)

---

Este documento describe la infraestructura de menús y permisos que da soporte a un menú principal/de inicio/de tienda y a un menú de "back office"/administrador.

## Requisitos/Funcionalidades

* Proveer una separación entre el menú "normal" de la página de inicio (el grupo de menú "home") y un menú de página de inicio de "back office" (el grupo de menú "office").
* El menú "office" estará protegido de modo que solo quienes tengan permiso concedido para acceder a él puedan hacerlo.
* Una opción de configuración permitirá incluir un ícono de navegación del menú de oficina dentro del grupo de menú "home". Por defecto estará incluido, pero se puede excluir. En ese caso, el único acceso a la página de oficina será mediante un enlace directo o cambiando manualmente la URL.
* Un empleado que haya iniciado sesión siempre tendrá acceso a la página de inicio.
* Los empleados necesitarán tener permisos explícitos otorgados para el menú de oficina.
* Las opciones de menú disponibles en cualquiera de los dos grupos de menú son únicas para cada empleado y se establecen mediante la tabla de permisos en el mantenimiento de Empleados.
* Una opción de menú puede aparecer en ambos grupos de menú para un empleado determinado.
* Solo los permisos a nivel de módulo pueden usarse para determinar en qué menú aparece el ícono de navegación del módulo.

## Operaciones

Por defecto, el menú de inicio contendrá las siguientes opciones de menú.

* Oficina
* Clientes
* Artículos
* Kits de Artículos
* Proveedores
* Reportes
* Recepciones
* Ventas
* Mensajes
* Tarjetas de Regalo

Por defecto, el menú de oficina contendrá las siguientes opciones de menú

* Inicio
* Empleados
* Impuestos
* Configuración de Tienda
* Migración

Las opciones Oficina e Inicio se alternan entre los grupos de menú.

La opción de menú Oficina que aparece en el grupo de menú Inicio se puede quitar del grupo de menú Inicio mediante una opción de configuración en la pestaña de configuración General. El resultado de esta opción es que el usuario debe hacer clic en un enlace o escribir la URL para acceder al menú de oficina. Esto lo aísla un poco de la página de inicio, de modo que nadie intentará entrar al menú de oficina a menos que esté autorizado y sepa cuál es la URL para hacerlo.

