[← Volver a Documentación para Desarrolladores](Development-Index)

---

El proceso de traducción se realiza de dos maneras. De la forma normal, las traducciones se hacen en una interfaz web, y luego esta interfaz se integra a github mediante su API.

1. Las cadenas nuevas deben agregarse manualmente a cada archivo en el directorio `app/Language`, y luego la interfaz sincronizará esas nuevas cadenas.
2. Las cadenas aún no traducidas no se agregan manualmente a los archivos del directorio de idioma; deben hacerse en la interfaz.

Esa interfaz se llama [Weblate](https://translate.opensourcepos.org) - regístrate para ayudar a traducir esta gran aplicación. Después de registrarte puedes suscribirte a diferentes idiomas y se te notificará cada vez que se agregue una nueva traducción.

[![Translation status](https://translate.opensourcepos.org/widgets/opensourcepos/-/svg-badge.svg)](https://translate.opensourcepos.org/engage/opensourcepos/?utm_source=widget)

## Estado de las Traducciones

- [Primeros Pasos - Uso](Getting-Started-usage)
- [Lista Completa de Características](Complete-feature-datasheet#complete-list-of-features)

[![Translation status](https://translate.opensourcepos.org/widgets/opensourcepos/-/multi-green.svg)](https://translate.opensourcepos.org/engage/opensourcepos/?utm_source=widget)

## Guía de Traducciones

Ver también [Índice de Desarrollo: Traducciones](Development-Index#always-use-translations-event-hardcoded-strings)

Aunque no todas las pautas aplicarán directamente a todos los idiomas, nos gustaría proponer algunas "Pautas de Traducción" para usar y recomendar en todas las traducciones:

- Los títulos siguen las reglas de capitalización de formato título. Es decir, la primera letra de cada palabra en mayúscula excepto palabras poco importantes, y no se usa puntuación (por ejemplo, no "Cambiar contraseña." sino "Cambiar Contraseña").
- Las oraciones siguen las reglas gramaticales de puntuación y capitalización específicas del idioma (por ejemplo, no "uno o más ha procesado ventas o estás intentando eliminar tu cuenta" sino "uno o más ha procesado ventas o estás intentando eliminar tu cuenta.").
- Cuando las oraciones hacen referencia a un campo, se le nombra exactamente en el mismo formato que el campo (por ejemplo, no "el título es un campo obligatorio" sino "Título es un campo obligatorio").
- Usa un formato consistente de mensaje de éxito/fallo. Actualmente se ve "El {campo} se actualizó correctamente" y en otros lugares "{campo} actualización exitosa", pero deberíamos mantenernos con el estilo de mensajes "{campo} actualización exitosa" y "{campo} actualización fallida". Hay tres razones principales para esto: traducciones más concisas, consistencia, y permite eliminar docenas de líneas traducidas porque solo necesitamos una línea traducida para "actualización exitosa" y "actualización fallida" o "es un campo obligatorio", y en el código podemos llamar al nombre del campo y ya sea actualización exitosa o fallida. Por supuesto habrá excepciones donde queramos agregar más información, y para esas podemos tener una línea de traducción separada.
- Gift Card(s) en la traducción debería ser dos palabras (Tarjeta(s) de Regalo). En el código es una sola palabra, pero en español y la mayoría de los otros idiomas son dos palabras.

# Traducción Manual

**Nota:** El método preferido es usar Weblate en https://translate.opensourcepos.org para las traducciones.

Para agregar una traducción manualmente, se deben seguir los siguientes pasos (el alemán es un ejemplo en este caso):

- Edita los archivos de idioma en el directorio `app/Language/`, creando un nuevo subdirectorio para tu código de idioma (por ejemplo, `app/Language/de/` para alemán)
- El identificador de idioma debe seguir los códigos de configuración regional estándar (por ejemplo, `de`, `de-DE`, `de-AT`, `de-CH`). Ten cuidado de no seleccionar simplemente `de`, porque el alemán de Alemania es diferente del alemán en Suiza
- Haz un pull request basado en el último master. Este pull request debe contener los archivos de idioma PHP generados
- Ve a Configuración de Tienda y en la pestaña Locale (Configuración Regional) selecciona tu idioma