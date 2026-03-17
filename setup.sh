#!/usr/bin/env bash
# =============================================================================
#  ESL – Einrichtungsassistent
#  Erstellt die .env-Datei und prüft die SMTP-Verbindung.
#  Verwendung: bash setup.sh
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Farben & Hilfsfunktionen
# ---------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

info()    { echo -e "${CYAN}ℹ  $*${NC}"; }
success() { echo -e "${GREEN}✔  $*${NC}"; }
warn()    { echo -e "${YELLOW}⚠  $*${NC}"; }
error()   { echo -e "${RED}✘  $*${NC}" >&2; }
title()   { echo -e "\n${BOLD}${CYAN}=== $* ===${NC}\n"; }

ask() {
    local prompt="$1"
    local default="${2:-}"
    local input
    if [[ -n "$default" ]]; then
        read -r -p "$(echo -e "${BOLD}  $prompt${NC} [${default}]: ")" input
        echo "${input:-$default}"
    else
        read -r -p "$(echo -e "${BOLD}  $prompt${NC}: ")" input
        echo "$input"
    fi
}

ask_secret() {
    local prompt="$1"
    local default="${2:-}"
    local input
    if [[ -n "$default" ]]; then
        read -r -s -p "$(echo -e "${BOLD}  $prompt${NC} [aktueller Wert bleibt wenn leer]: ")" input
        echo ""
        echo "${input:-$default}"
    else
        read -r -s -p "$(echo -e "${BOLD}  $prompt${NC}: ")" input
        echo ""
        echo "$input"
    fi
}

confirm() {
    local prompt="$1"
    local default="${2:-j}"
    local input
    if [[ "$default" == "j" ]]; then
        read -r -p "$(echo -e "${BOLD}  $prompt${NC} [J/n]: ")" input
    else
        read -r -p "$(echo -e "${BOLD}  $prompt${NC} [j/N]: ")" input
    fi
    input="${input:-$default}"
    [[ "$input" =~ ^[JjYy]$ ]]
}

# Liest einen Wert aus der .env-Datei
env_get() {
    local key="$1"
    local file="$2"
    grep -E "^${key}=" "$file" 2>/dev/null | head -1 | cut -d'=' -f2- || echo ""
}

test_smtp_connection() {
    local host="$1"
    local port="$2"
    if command -v nc &>/dev/null; then
        nc -z -w 5 "$host" "$port" &>/dev/null && return 0
    elif command -v curl &>/dev/null; then
        curl -s --connect-timeout 5 "smtp://$host:$port" &>/dev/null && return 0
    fi
    return 1
}

# ---------------------------------------------------------------------------
# Begrüßung
# ---------------------------------------------------------------------------
clear
echo -e "${BOLD}"
echo "  ╔══════════════════════════════════════════════════╗"
echo "  ║       ESL – Einrichtungsassistent                ║"
echo "  ║   .env-Datei erstellen & E-Mail konfigurieren    ║"
echo "  ╚══════════════════════════════════════════════════╝"
echo -e "${NC}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env"

if [[ ! -f "$SCRIPT_DIR/.env.example" ]]; then
    error ".env.example nicht gefunden. Bitte Skript im Projektverzeichnis ausführen."
    exit 1
fi

# ---------------------------------------------------------------------------
# Bestehende .env laden oder Standardwerte setzen
# ---------------------------------------------------------------------------
UPDATE_MODE=false

if [[ -f "$ENV_FILE" ]]; then
    UPDATE_MODE=true
    info "Vorhandene .env gefunden – bestehende Werte werden als Vorgabe verwendet."
    echo ""

    # Bestehende Werte lesen
    CUR_DB_HOST=$(env_get "DB_HOST"    "$ENV_FILE")
    CUR_DB_PORT=$(env_get "DB_PORT"    "$ENV_FILE")
    CUR_DB_NAME=$(env_get "DB_NAME"    "$ENV_FILE")
    CUR_DB_USER=$(env_get "DB_USER"    "$ENV_FILE")
    CUR_DB_PASS=$(env_get "DB_PASS"    "$ENV_FILE")
    CUR_MAIL_FROM=$(env_get "MAIL_FROM"  "$ENV_FILE")
    CUR_SMTP_HOST=$(env_get "SMTP_HOST"  "$ENV_FILE")
    CUR_SMTP_PORT=$(env_get "SMTP_PORT"  "$ENV_FILE")
    CUR_SMTP_SECURE=$(env_get "SMTP_SECURE" "$ENV_FILE")
    CUR_SMTP_USER=$(env_get "SMTP_USER"  "$ENV_FILE")
    CUR_SMTP_PASS=$(env_get "SMTP_PASS"  "$ENV_FILE")
    CUR_APP_ENV=$(env_get "APP_ENV"    "$ENV_FILE")
else
    # Standardwerte für Ersteinrichtung
    CUR_DB_HOST="db"
    CUR_DB_PORT="3306"
    CUR_DB_NAME="webapp"
    CUR_DB_USER="webuser"
    CUR_DB_PASS=""
    CUR_MAIL_FROM="noreply@example.com"
    CUR_SMTP_HOST=""
    CUR_SMTP_PORT="587"
    CUR_SMTP_SECURE="tls"
    CUR_SMTP_USER=""
    CUR_SMTP_PASS=""
    CUR_APP_ENV="production"
fi

# ---------------------------------------------------------------------------
# Abschnittsauswahl (nur im Update-Modus)
# ---------------------------------------------------------------------------
DO_DB=true
DO_MAIL=true
DO_APP=true

if [[ "$UPDATE_MODE" == true ]]; then
    title "Was möchten Sie ändern?"

    echo -e "  Wählen Sie die Abschnitte, die neu konfiguriert werden sollen."
    echo -e "  ${DIM}(Nicht ausgewählte Abschnitte behalten ihre aktuellen Werte.)${NC}"
    echo ""

    echo -e "  Aktuelle Konfiguration:"
    echo -e "  ${DIM}  Datenbank:   ${CUR_DB_USER}@${CUR_DB_HOST}:${CUR_DB_PORT}/${CUR_DB_NAME}${NC}"
    if [[ -n "$CUR_SMTP_HOST" ]]; then
        echo -e "  ${DIM}  E-Mail:      ${CUR_MAIL_FROM}  via ${CUR_SMTP_HOST}:${CUR_SMTP_PORT}${NC}"
    else
        echo -e "  ${DIM}  E-Mail:      ${CUR_MAIL_FROM}  (PHP mail() Fallback)${NC}"
    fi
    echo -e "  ${DIM}  Umgebung:    ${CUR_APP_ENV}${NC}"
    echo ""

    confirm "  [1] Datenbank neu konfigurieren?" "n" && DO_DB=true  || DO_DB=false
    confirm "  [2] E-Mail / SMTP neu konfigurieren?" "n" && DO_MAIL=true || DO_MAIL=false
    confirm "  [3] Anwendungsumgebung neu konfigurieren?" "n" && DO_APP=true || DO_APP=false

    echo ""

    if [[ "$DO_DB" == false && "$DO_MAIL" == false && "$DO_APP" == false ]]; then
        info "Keine Abschnitte ausgewählt – nichts wurde geändert."
        exit 0
    fi

    # Backup anlegen
    cp "$ENV_FILE" "${ENV_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
    success "Backup der aktuellen .env erstellt."
fi

# Variablen mit aktuellen Werten vorbelegen (werden ggf. überschrieben)
DB_HOST="$CUR_DB_HOST"
DB_PORT="$CUR_DB_PORT"
DB_NAME="$CUR_DB_NAME"
DB_USER="$CUR_DB_USER"
DB_PASS="$CUR_DB_PASS"
MAIL_FROM="$CUR_MAIL_FROM"
SMTP_HOST="$CUR_SMTP_HOST"
SMTP_PORT="$CUR_SMTP_PORT"
SMTP_SECURE="$CUR_SMTP_SECURE"
SMTP_USER="$CUR_SMTP_USER"
SMTP_PASS="$CUR_SMTP_PASS"
APP_ENV="$CUR_APP_ENV"

# ---------------------------------------------------------------------------
# Datenbank
# ---------------------------------------------------------------------------
if [[ "$DO_DB" == true ]]; then
    title "Datenbank"
    info "Diese Werte müssen mit der MySQL-Konfiguration in docker-compose.yml übereinstimmen."
    echo ""

    DB_HOST=$(ask "Datenbank-Host (Docker-Service-Name)" "$CUR_DB_HOST")
    DB_PORT=$(ask "Datenbank-Port" "$CUR_DB_PORT")
    DB_NAME=$(ask "Datenbank-Name" "$CUR_DB_NAME")
    DB_USER=$(ask "Datenbank-Benutzer" "$CUR_DB_USER")
    DB_PASS=$(ask_secret "Datenbank-Passwort" "$CUR_DB_PASS")

    if [[ -z "$DB_PASS" ]]; then
        warn "Kein Passwort angegeben – Standardwert wird verwendet (unsicher!)."
        DB_PASS="geheimespasswort"
    fi
else
    info "Datenbank-Konfiguration unverändert übernommen."
fi

# ---------------------------------------------------------------------------
# E-Mail / SMTP
# ---------------------------------------------------------------------------
if [[ "$DO_MAIL" == true ]]; then
    title "E-Mail (SMTP)"

    echo -e "  ${YELLOW}Ohne SMTP nutzt die Anwendung PHP mail() – E-Mails landen häufig im Spam.${NC}"
    echo ""

    CONFIGURE_SMTP=false
    if [[ -n "$CUR_SMTP_HOST" ]]; then
        confirm "SMTP ist aktuell konfiguriert. SMTP-Einstellungen bearbeiten?" "j" && CONFIGURE_SMTP=true || CONFIGURE_SMTP=false
    else
        confirm "Einen SMTP-Server konfigurieren?" "j" && CONFIGURE_SMTP=true || CONFIGURE_SMTP=false
    fi

    if [[ "$CONFIGURE_SMTP" == true ]]; then
        echo ""
        info "Häufige Einstellungen:"
        echo -e "  ${CYAN}Schul-Mailserver   ${NC}→  Host: mail.ihre-schule.de  Port: 587  Secure: tls"
        echo -e "  ${CYAN}Gmail              ${NC}→  Host: smtp.gmail.com       Port: 587  Secure: tls"
        echo -e "  ${CYAN}Office 365         ${NC}→  Host: smtp.office365.com   Port: 587  Secure: tls"
        echo -e "  ${CYAN}GMX                ${NC}→  Host: mail.gmx.net         Port: 587  Secure: tls"
        echo ""

        SMTP_HOST=$(ask "SMTP-Server (Hostname)" "$CUR_SMTP_HOST")
        SMTP_PORT=$(ask "SMTP-Port" "$CUR_SMTP_PORT")

        echo ""
        echo -e "  Verschlüsselung:"
        echo -e "    ${BOLD}tls${NC}  – STARTTLS (empfohlen, Port 587)"
        echo -e "    ${BOLD}ssl${NC}  – Implizites TLS  (Port 465)"
        echo -e "    ${BOLD}     ${NC}– Keine Verschlüsselung (nicht empfohlen)"
        SMTP_SECURE=$(ask "Verschlüsselung" "$CUR_SMTP_SECURE")

        SMTP_USER=$(ask "SMTP-Benutzername / Absender-E-Mail" "$CUR_SMTP_USER")
        SMTP_PASS=$(ask_secret "SMTP-Passwort" "$CUR_SMTP_PASS")

        echo ""
        MAIL_FROM=$(ask "Absenderadresse (From:)" "${CUR_MAIL_FROM:-$SMTP_USER}")

        echo ""
        info "Verbindung zu ${SMTP_HOST}:${SMTP_PORT} wird geprüft..."
        if test_smtp_connection "$SMTP_HOST" "$SMTP_PORT"; then
            success "Server ${SMTP_HOST}:${SMTP_PORT} ist erreichbar."
        else
            warn "Server ${SMTP_HOST}:${SMTP_PORT} konnte nicht erreicht werden."
            warn "Prüfen Sie Hostname, Port und ggf. Firewall-Regeln."
            warn "Die .env wird trotzdem gespeichert – Wert kann später korrigiert werden."
        fi
    else
        SMTP_HOST=""
        SMTP_PORT="587"
        SMTP_SECURE="tls"
        SMTP_USER=""
        SMTP_PASS=""
        MAIL_FROM=$(ask "Absenderadresse (From:)" "$CUR_MAIL_FROM")
        warn "Kein SMTP konfiguriert. PHP mail() wird als Fallback genutzt."
    fi
else
    info "E-Mail-Konfiguration unverändert übernommen."
fi

# ---------------------------------------------------------------------------
# Anwendungsumgebung
# ---------------------------------------------------------------------------
if [[ "$DO_APP" == true ]]; then
    title "Anwendungsumgebung"
    APP_ENV=$(ask "Umgebung (production / development)" "$CUR_APP_ENV")
else
    info "Anwendungsumgebung unverändert übernommen."
fi

# ---------------------------------------------------------------------------
# .env schreiben
# ---------------------------------------------------------------------------
cat > "$ENV_FILE" <<EOF
# Generiert von setup.sh am $(date '+%Y-%m-%d %H:%M:%S')

# Datenbank
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

# E-Mail
MAIL_FROM=${MAIL_FROM}
SMTP_HOST=${SMTP_HOST}
SMTP_PORT=${SMTP_PORT}
SMTP_SECURE=${SMTP_SECURE}
SMTP_USER=${SMTP_USER}
SMTP_PASS=${SMTP_PASS}

# Anwendung
APP_ENV=${APP_ENV}
EOF

echo ""
success ".env wurde gespeichert: $ENV_FILE"

# ---------------------------------------------------------------------------
# Zusammenfassung
# ---------------------------------------------------------------------------
title "Zusammenfassung"

echo -e "  ${BOLD}Datenbank${NC}"
echo -e "    Host:      ${DB_HOST}:${DB_PORT}"
echo -e "    Datenbank: ${DB_NAME}"
echo -e "    Benutzer:  ${DB_USER}"
echo ""
echo -e "  ${BOLD}E-Mail${NC}"
echo -e "    Absender:  ${MAIL_FROM}"
if [[ -n "$SMTP_HOST" ]]; then
    echo -e "    SMTP:      ${SMTP_HOST}:${SMTP_PORT} (${SMTP_SECURE:-keine Verschlüsselung})"
    echo -e "    Benutzer:  ${SMTP_USER}"
else
    echo -e "    SMTP:      PHP mail() (Fallback)"
fi
echo ""
echo -e "  ${BOLD}Anwendung${NC}"
echo -e "    Umgebung:  ${APP_ENV}"
echo ""

# ---------------------------------------------------------------------------
# Nächste Schritte
# ---------------------------------------------------------------------------
title "Nächste Schritte"

echo -e "  ${BOLD}1.${NC} Anwendung starten (oder neu starten):"
echo -e "     ${CYAN}docker compose up -d${NC}"
echo ""
echo -e "  ${BOLD}2.${NC} Im Browser öffnen:"
echo -e "     ${CYAN}http://localhost:8080${NC}"
echo ""

if [[ "$UPDATE_MODE" == false ]]; then
    echo -e "  ${BOLD}3.${NC} Als Admin einloggen:"
    echo -e "     Benutzer: ${CYAN}admin${NC}   Passwort: ${CYAN}Admin123!${NC}"
    echo -e "     ${YELLOW}⚠  Passwort nach dem ersten Login ändern!${NC}"
    echo ""
    echo -e "  ${BOLD}4.${NC} Admin-E-Mail-Adresse hinterlegen:"
    echo -e "     Verwaltung → Nutzerverwaltung → E-Mail eintragen + Benachrichtigungen aktivieren"
    echo ""
fi

if [[ -n "$SMTP_HOST" ]]; then
    local_step=$( [[ "$UPDATE_MODE" == false ]] && echo "5" || echo "3" )
    echo -e "  ${BOLD}${local_step}.${NC} E-Mail-Versand testen:"
    echo -e "     Nicht-anonymen Vorschlag einreichen → Admin erhält eine Benachrichtigung."
    echo -e "     Bei Problemen: ${CYAN}docker compose logs app${NC}"
    echo ""
fi

success "Fertig."
