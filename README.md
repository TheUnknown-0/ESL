# Schwarzes Brett & Vorschlagssystem

Eine PHP-Webanwendung für das Schwarze Brett und Vorschlagssystem. Projekte können vorgeschlagen, verwaltet und auf einem Schwarzen Brett angezeigt werden.

## Technischer Stack

- PHP 8.2 (OOP, PDO)
- MySQL 8.0
- Tailwind CSS (via CDN)
- Docker + Docker Compose (PHP-FPM + Nginx + MySQL)

## Quickstart

### 1. Umgebungsvariablen konfigurieren

```bash
cp .env.example .env
```

Passen Sie die Werte in `.env` nach Bedarf an.

### 2. Anwendung starten

```bash
docker compose up -d
```

### 3. Anwendung aufrufen

Öffnen Sie [http://localhost:8080](http://localhost:8080) im Browser.

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
