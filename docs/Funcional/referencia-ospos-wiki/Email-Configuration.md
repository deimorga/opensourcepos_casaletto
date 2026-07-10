[← Volver a Configuración](Configuration) | [Inicio](Home)

---

Esta guía explica cómo configurar la funcionalidad de correo electrónico usando Postfix con SMTP de Gmail en Linux (Ubuntu, Linux Mint, etc.).

## Requisitos previos

- Una cuenta de Gmail con **verificación en dos pasos** habilitada
- Una **contraseña de aplicación** generada para Postfix (no tu contraseña habitual de Gmail)

> **Generar contraseña de aplicación:** https://support.google.com/accounts/answer/185833

## Paso 1: Instalar Postfix

```bash
sudo apt-get install postfix
```

Durante la instalación:
- Selecciona **"Internet Site"** como tipo de configuración del servidor de correo
- Haz clic en **Ok** para continuar
- Acepta los valores predeterminados para el resto de las indicaciones

## Paso 2: Configurar Postfix para Gmail

Edita el archivo de configuración de Postfix:

```bash
sudo nano /etc/postfix/main.cf
```

Agrega o modifica las siguientes líneas:

```conf
relayhost = [smtp.gmail.com]:587
smtp_use_tls = yes
smtp_sasl_auth_enable = yes
smtp_sasl_security_options =
smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd
smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt
```

## Paso 3: Crear el archivo de autenticación de Gmail

Crea el archivo de contraseñas:

```bash
sudo nano /etc/postfix/sasl_passwd
```

Agrega tus credenciales de Gmail:

```conf
[smtp.gmail.com]:587 your_email@gmail.com:your_app_password
```

Reemplaza:
- `your_email@gmail.com` - Tu dirección de correo de Gmail
- `your_app_password` - La contraseña de aplicación que generaste en la configuración de tu cuenta de Google

> **Importante:** Sin espacios después de los dos puntos ni después de la contraseña.

## Paso 4: Asegurar el archivo de contraseñas

```bash
sudo chmod 600 /etc/postfix/sasl_passwd
sudo postmap /etc/postfix/sasl_passwd
```

## Paso 5: Reiniciar Postfix

```bash
sudo service postfix restart
```

## Paso 6: Configurar OSPOS

En OSPOS, ve a **Configuración → Configuración → Correo electrónico** y establece:

| Configuración | Valor |
|---------|-------|
| Protocolo | `smtp` |
| Servidor SMTP | `localhost` |
| Puerto SMTP | `25` |
| Cifrado SMTP | `None` |

Deja en blanco las demás configuraciones de correo y haz clic en **Enviar**.

## Paso 7: Probar el correo electrónico

Ahora deberías poder enviar recibos y facturas directamente desde OSPOS usando tu cuenta de Gmail.

## Solución de problemas

| Problema | Solución |
|-------|----------|
| Error de autenticación | Verifica que estés usando una contraseña de aplicación, no tu contraseña habitual de Gmail |
| Conexión rechazada | Verifica si Postfix está en ejecución: `sudo systemctl status postfix` |
| Los correos no se envían | Revisa los registros: `sudo tail -f /var/log/mail.log` |

## Ver también

- [Configuración](Configuration)
- [Requisitos mínimos del servidor](Minimum-Server-Requirements)
