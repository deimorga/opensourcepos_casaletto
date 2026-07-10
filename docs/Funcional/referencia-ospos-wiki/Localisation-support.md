
La localización fue agregada en el [issue 458](https://github.com/jekkos/opensourcepos/issues/458) y requiere la extensión `php-intl`. Para que todo funcione correctamente, asegúrate de que esté instalada primero.

## Instalación de la extensión php-intl
`php-intl` se puede instalar de la siguiente manera:

* En un sistema basado en Debian, ejecutando `sudo apt-get install php-intl` o habilitando manualmente la extensión intl en tu cpanel (si usas hosting compartido).
* En una instalación wamp, siguiendo las instrucciones [en esta publicación](http://stackoverflow.com/questions/23431788/how-to-install-intl-php-extension-with-wamp-server)

## Encontrar una localización adecuada

A continuación, debe elegirse un código de localización adecuado. [Una lista completa de códigos de idioma](https://github.com/opensourcepos/opensourcepos/tree/master/app/Language) se puede encontrar directamente en nuestro repositorio. Este código de idioma determinará, en primer lugar, el símbolo y la posición de la moneda, así como los separadores decimal y de miles. El símbolo de la moneda puede luego sobrescribirse independientemente de la localización seleccionada.
