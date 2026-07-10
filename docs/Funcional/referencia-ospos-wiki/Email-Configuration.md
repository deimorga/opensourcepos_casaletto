[← Back to Configuration](Configuration) | [Home](Home)

---

This guide explains how to configure email functionality using Postfix with Gmail SMTP on Linux (Ubuntu, Linux Mint, etc.).

## Prerequisites

- A Gmail account with **2-Step Verification** enabled
- An **App Password** generated for Postfix (not your regular Gmail password)

> **Generate App Password:** https://support.google.com/accounts/answer/185833

## Step 1: Install Postfix

```bash
sudo apt-get install postfix
```

During installation:
- Select **"Internet Site"** as the mail server configuration type
- Click **Ok** to proceed
- Accept all defaults for the remaining prompts

## Step 2: Configure Postfix for Gmail

Edit the Postfix configuration file:

```bash
sudo nano /etc/postfix/main.cf
```

Add or modify the following lines:

```conf
relayhost = [smtp.gmail.com]:587
smtp_use_tls = yes
smtp_sasl_auth_enable = yes
smtp_sasl_security_options =
smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd
smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt
```

## Step 3: Create Gmail Authentication File

Create the password file:

```bash
sudo nano /etc/postfix/sasl_passwd
```

Add your Gmail credentials:

```conf
[smtp.gmail.com]:587 your_email@gmail.com:your_app_password
```

Replace:
- `your_email@gmail.com` - Your Gmail email address
- `your_app_password` - The app password you generated in Google Account settings

> **Important:** No spaces after the colon or password.

## Step 4: Secure the Password File

```bash
sudo chmod 600 /etc/postfix/sasl_passwd
sudo postmap /etc/postfix/sasl_passwd
```

## Step 5: Restart Postfix

```bash
sudo service postfix restart
```

## Step 6: Configure OSPOS

In OSPOS, navigate to **Settings → Configuration → Email** and set:

| Setting | Value |
|---------|-------|
| Protocol | `smtp` |
| SMTP Server | `localhost` |
| SMTP Port | `25` |
| SMTP Encryption | `None` |

Leave other email settings blank and click **Submit**.

## Step 7: Test Email

You should now be able to send receipts and invoices directly from OSPOS using your Gmail account.

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Authentication failed | Verify you're using an App Password, not your regular Gmail password |
| Connection refused | Check if Postfix is running: `sudo systemctl status postfix` |
| Emails not sending | Check logs: `sudo tail -f /var/log/mail.log` |

## See Also

- [Configuration](Configuration)
- [Minimum Server Requirements](Minimum-Server-Requirements)