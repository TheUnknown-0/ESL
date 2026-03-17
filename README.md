# Schwarzes Brett & Vorschlagssystem

Eine PHP-Webanwendung für das Schwarze Brett und Vorschlagssystem. Projekte können vorgeschlagen, verwaltet und auf einem Schwarzen Brett angezeigt werden.

## Technischer Stack

- PHP 8.2 (OOP, PDO)
- MySQL 8.0
- Tailwind CSS (via CDN)
- Docker + Docker Compose (PHP-FPM + Nginx + MySQL)

## Quickstart

### 1. Umgebungsvariablen konfigurieren

**Empfohlen – interaktiver Assistent:**

```bash
bash setup.sh
```

Das Skript führt Sie Schritt für Schritt durch die Konfiguration, testet die SMTP-Verbindung und erstellt die `.env` automatisch.

**Alternativ – manuell:**

```bash
cp .env.example .env
# Werte in .env anpassen
```

> ⚠️ Die `.env`-Datei ist in `.gitignore` eingetragen und wird **nie** ins Repository übertragen. Nur `.env.example` wird versioniert.

### 2. Anwendung starten

```bash
docker compose up -d
```

### 3. Anwendung aufrufen

Öffnen Sie [http://localhost:8080](http://localhost:8080) im Browser.

---

## E-Mail-Einrichtung (Serveradmin)

Die Anwendung sendet E-Mail-Benachrichtigungen (neue Vorschläge, Entscheidungen). Ohne Konfiguration nutzt PHP den lokalen `mail()`-Befehl – auf den meisten Servern landet das direkt im Spam. Empfohlen wird ein dedizierter SMTP-Account.

### Schritt 1 – `.env` anlegen

```bash
cp .env.example .env
```

### Schritt 2 – SMTP-Zugangsdaten eintragen

Öffnen Sie `.env` und füllen Sie den E-Mail-Block aus:

```dotenv
# Absenderadresse (erscheint als „Von:" in der E-Mail)
MAIL_FROM=noreply@ihre-schule.de

# SMTP-Server Ihres Mailproviders
SMTP_HOST=mail.ihre-schule.de
SMTP_PORT=587          # 587 = STARTTLS (empfohlen), 465 = SSL, 25 = unverschlüsselt
SMTP_SECURE=tls        # tls (STARTTLS), ssl oder leer lassen

# Zugangsdaten des Absender-E-Mail-Kontos
SMTP_USER=noreply@ihre-schule.de
SMTP_PASS=sicheres-passwort
```

**Typische Einstellungen nach Anbieter:**

| Anbieter | SMTP_HOST | SMTP_PORT | SMTP_SECURE |
|---|---|---|---|
| Schul-Mailserver (Postfix) | mail.ihre-schule.de | 587 | tls |
| Gmail | smtp.gmail.com | 587 | tls |
| Office 365 | smtp.office365.com | 587 | tls |
| Web.de | smtp.web.de | 587 | tls |
| GMX | mail.gmx.net | 587 | tls |

> **Gmail/Google Workspace:** Erstellen Sie unter „Google-Konto → Sicherheit → App-Passwörter" ein App-Passwort und tragen Sie dieses als `SMTP_PASS` ein (kein normales Kontopasswort).

### Schritt 3 – Admin-E-Mail-Adresse hinterlegen

Damit Benachrichtigungen ankommen, muss der Admin-Account eine E-Mail-Adresse haben:

1. Als Admin einloggen
2. „Verwaltung" → „Nutzerverwaltung" öffnen
3. Beim Admin-Nutzer eine E-Mail-Adresse eintragen
4. E-Mail-Benachrichtigungen aktivieren (Checkbox)

### Schritt 4 – Container neu starten

```bash
docker compose down && docker compose up -d
```

### Schritt 5 – Versand testen

Reichen Sie einen (nicht anonymen) Vorschlag ein. Der Admin sollte eine Benachrichtigung erhalten. Fehler werden in `docker logs <container-name>` protokolliert:

```bash
docker compose logs app
```

### Kein SMTP-Server verfügbar?

Ohne SMTP-Konfiguration (`SMTP_HOST` leer lassen) fällt die Anwendung auf PHP `mail()` zurück. Das erfordert einen lokal konfigurierten MTA (z.B. Postfix, msmtp). Für lokale Entwicklung eignet sich [Mailpit](https://mailpit.axllent.org/) als Mail-Testserver:

```bash
# Mailpit als zusätzlicher Docker-Container starten
docker run -d --name mailpit -p 8025:8025 -p 1025:1025 axllent/mailpit
```

Dann in `.env`:
```dotenv
SMTP_HOST=host.docker.internal
SMTP_PORT=1025
SMTP_SECURE=
SMTP_USER=
SMTP_PASS=
```

Alle E-Mails sind dann unter [http://localhost:8025](http://localhost:8025) einsehbar.

---

### Standard-Login

- **Benutzername:** `admin`
- **Passwort:** `Admin123!`

> ⚠️ Bitte ändern Sie das Admin-Passwort nach dem ersten Login!

## Funktionen

- **Login** mit Brute-Force-Schutz (max. 5 Fehlversuche, dann 15 Min. Sperre)
- **Schwarzes Brett** – Alle Projekte als Karten-Grid mit farblichen Status-Anzeigen und Live-Update (alle 30 Sek.)
- **Vorschlag einreichen** – Neues Projekt vorschlagen (optional anonym), E-Mail-Benachrichtigung an Admins
- **Verwaltung** (nur Admins):
  - Projektverwaltung: Status ändern, Entscheidungsbegründung hinzufügen
  - Nutzerverwaltung: Nutzer anlegen, löschen, Passwort zurücksetzen, E-Mail bearbeiten

## Sicherheit

- CSRF-Schutz auf allen Formularen
- Prepared Statements (SQL-Injection-Schutz)
- XSS-Schutz durch `htmlspecialchars()`
- Bcrypt-Passwort-Hashing
- Session-Sicherheit (HttpOnly, SameSite Cookies, `session_regenerate_id()`)
- Fehler werden nur ins Log geschrieben (`display_errors = Off`)
- Zugangsdaten nur über Umgebungsvariablen

## Projektstruktur

```
├── docker-compose.yml
├── Dockerfile
├── .env.example
├── nginx/
│   └── default.conf
├── docker/
│   └── mysql/
│       └── init.sql
└── src/
    ├── index.php          (Router)
    ├── config.php         (Konfiguration)
    ├── auth.php           (Authentifizierung)
    ├── pages/
    │   ├── login.php
    │   ├── nav.php
    │   ├── schwarzes-brett.php
    │   ├── vorschlag.php
    │   └── verwaltung.php
    ├── includes/
    │   ├── db.php
    │   ├── csrf.php
    │   ├── mailer.php
    │   └── functions.php
    └── api/
        └── projects.php
```
