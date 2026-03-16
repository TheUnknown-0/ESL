<?php
/**
 * Theme-Helper
 * Liest Nutzerpräferenzen aus der Session und stellt CSS-Klassen bereit.
 * Muss nach session_start() eingebunden werden.
 * Setzt $themeHtmlClasses für das <html>-Tag.
 * outputThemeHead() gibt das Tailwind-Config und CSS-Overrides aus.
 */

$_theme = $_SESSION['theme'] ?? 'light';
$_style = $_SESSION['style'] ?? 'default';

$_parts = [];
if ($_theme === 'dark') $_parts[] = 'dark';
if ($_style === 'anthropic') $_parts[] = 'style-anthropic';
$themeHtmlClasses = implode(' ', $_parts);

function outputThemeHead(): void
{
    echo '<script>tailwind.config={darkMode:"class"}</script>' . "\n";
    echo '<style>' . getThemeCss() . '</style>' . "\n";
}

function getThemeCss(): string
{
    return '
/* ============================================================
   DARK MODE – Standard
   ============================================================ */
html.dark body { background-color:#0f172a!important; color:#e2e8f0; }
html.dark .bg-gray-100 { background-color:#0f172a!important; }
html.dark .bg-gray-50  { background-color:#1e293b!important; }
html.dark .bg-white    { background-color:#1e293b!important; }
html.dark header.bg-white, html.dark div.bg-white { background-color:#1e293b!important; }
html.dark .bg-gray-200 { background-color:#334155!important; }

html.dark .text-gray-900 { color:#f1f5f9!important; }
html.dark .text-gray-800 { color:#f1f5f9!important; }
html.dark .text-gray-700 { color:#cbd5e1!important; }
html.dark .text-gray-600 { color:#94a3b8!important; }
html.dark .text-gray-500 { color:#64748b!important; }
html.dark .text-gray-400 { color:#475569!important; }

html.dark .border-gray-200 { border-color:#334155!important; }
html.dark .border-gray-300 { border-color:#475569!important; }
html.dark .divide-gray-200>*+* { border-color:#334155!important; }

html.dark .shadow    { box-shadow:0 1px 3px rgba(0,0,0,.6)!important; }
html.dark .shadow-md { box-shadow:0 4px 6px rgba(0,0,0,.6)!important; }
html.dark .shadow-xl { box-shadow:0 20px 25px rgba(0,0,0,.7)!important; }

html.dark input, html.dark textarea, html.dark select {
    background-color:#0f172a!important;
    color:#e2e8f0!important;
    border-color:#475569!important;
}
html.dark input::placeholder, html.dark textarea::placeholder { color:#475569!important; }

html.dark .bg-green-100  { background-color:#14532d!important; }
html.dark .text-green-700{ color:#86efac!important; }
html.dark .border-green-400{ border-color:#16a34a!important; }
html.dark .bg-red-100    { background-color:#450a0a!important; }
html.dark .text-red-700  { color:#fca5a5!important; }
html.dark .border-red-400{ border-color:#dc2626!important; }
html.dark .text-green-600{ color:#4ade80!important; }
html.dark .text-red-600  { color:#f87171!important; }

html.dark .bg-gray-200.text-gray-700 { color:#cbd5e1!important; }
html.dark .hover\:bg-gray-300:hover  { background-color:#475569!important; }

/* ============================================================
   ANTHROPIC STYLE – Light
   Authentisches Anthropic-Branding:
   Warmes Creme, Terrakotta-Akzente, modernes Sans-Serif
   ============================================================ */

/* --- Google Font laden (Styrene/System-Fallback) --- */
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");

html.style-anthropic {
    --a-bg:       #FAF9F6;
    --a-surface:  #FFFFFF;
    --a-subtle:   #F0EDE8;
    --a-border:   #E5E0D8;
    --a-text:     #1A1A1A;
    --a-text2:    #6B6B6B;
    --a-text3:    #999999;
    --a-primary:  #D97757;
    --a-primary-h:#BF5C3C;
    --a-green:    #2E7D5B;
    --a-green-h:  #226847;
    --a-red:      #C44536;
    --a-yellow:   #B8860B;
}

html.style-anthropic body {
    background-color: var(--a-bg)!important;
    color: var(--a-text)!important;
    font-family: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif!important;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Typografie */
html.style-anthropic h1,
html.style-anthropic h2,
html.style-anthropic h3 {
    font-family: "Inter", system-ui, -apple-system, sans-serif!important;
    letter-spacing: -0.02em!important;
    font-weight: 700!important;
    color: var(--a-text)!important;
}

/* Hintergründe */
html.style-anthropic .bg-gray-100 { background-color: var(--a-bg)!important; }
html.style-anthropic .bg-gray-50  { background-color: var(--a-subtle)!important; }
html.style-anthropic .bg-white    { background-color: var(--a-surface)!important; }
html.style-anthropic header.bg-white,
html.style-anthropic div.bg-white  { background-color: var(--a-surface)!important; }
html.style-anthropic .bg-gray-200 { background-color: var(--a-subtle)!important; }

/* Textfarben */
html.style-anthropic .text-gray-900 { color: var(--a-text)!important; }
html.style-anthropic .text-gray-800 { color: var(--a-text)!important; }
html.style-anthropic .text-gray-700 { color: #3D3D3D!important; }
html.style-anthropic .text-gray-600 { color: var(--a-text2)!important; }
html.style-anthropic .text-gray-500 { color: var(--a-text3)!important; }
html.style-anthropic .text-gray-400 { color: #BBBBBB!important; }

/* Rahmen & Trennlinien */
html.style-anthropic .border-gray-200 { border-color: var(--a-border)!important; }
html.style-anthropic .border-gray-300 { border-color: #D4CFC6!important; }
html.style-anthropic .border-gray-100 { border-color: var(--a-border)!important; }
html.style-anthropic .divide-gray-200>*+* { border-color: var(--a-border)!important; }

/* Schatten – weicher und wärmer */
html.style-anthropic .shadow    { box-shadow: 0 1px 2px rgba(140,120,90,.08), 0 1px 3px rgba(140,120,90,.06)!important; }
html.style-anthropic .shadow-md { box-shadow: 0 2px 8px rgba(140,120,90,.10), 0 1px 3px rgba(140,120,90,.06)!important; }
html.style-anthropic .shadow-xl { box-shadow: 0 8px 30px rgba(140,120,90,.12), 0 2px 8px rgba(140,120,90,.06)!important; }
html.style-anthropic .shadow-lg { box-shadow: 0 4px 16px rgba(140,120,90,.12)!important; }

/* Primäre Buttons – Terrakotta/Coral */
html.style-anthropic .bg-blue-600         { background-color: var(--a-primary)!important; }
html.style-anthropic .bg-blue-500         { background-color: var(--a-primary)!important; }
html.style-anthropic .hover\:bg-blue-700:hover { background-color: var(--a-primary-h)!important; }
html.style-anthropic .hover\:bg-blue-600:hover { background-color: var(--a-primary-h)!important; }
html.style-anthropic .text-blue-600       { color: var(--a-primary)!important; }
html.style-anthropic .focus\:ring-blue-500:focus { --tw-ring-color: var(--a-primary)!important; }
html.style-anthropic .focus\:ring-2:focus { --tw-ring-color: rgba(217,119,87,.35)!important; }

/* Sekundäre Buttons – Warmes Grau */
html.style-anthropic .bg-gray-200.text-gray-700,
html.style-anthropic a.bg-gray-200 {
    background-color: var(--a-subtle)!important;
    color: var(--a-text2)!important;
    border: 1px solid var(--a-border)!important;
}
html.style-anthropic .hover\:bg-gray-300:hover {
    background-color: #E5E0D8!important;
}

/* Grün / Erstellen */
html.style-anthropic .bg-green-600        { background-color: var(--a-green)!important; }
html.style-anthropic .hover\:bg-green-700:hover{ background-color: var(--a-green-h)!important; }
html.style-anthropic .text-green-600      { color: var(--a-green)!important; }

/* Rot */
html.style-anthropic .bg-red-500          { background-color: var(--a-red)!important; }
html.style-anthropic .hover\:bg-red-600:hover { background-color: #A83228!important; }

/* Gelb / Passwort */
html.style-anthropic .bg-yellow-500       { background-color: var(--a-yellow)!important; }
html.style-anthropic .hover\:bg-yellow-600:hover { background-color: #9A7209!important; }

/* Formularfelder */
html.style-anthropic input,
html.style-anthropic textarea,
html.style-anthropic select {
    border-color: var(--a-border)!important;
    border-radius: 8px!important;
    transition: border-color .15s, box-shadow .15s!important;
}
html.style-anthropic input:focus,
html.style-anthropic textarea:focus,
html.style-anthropic select:focus {
    border-color: var(--a-primary)!important;
    box-shadow: 0 0 0 3px rgba(217,119,87,.15)!important;
    outline: none!important;
}

/* Karten – sanftere Rundung */
html.style-anthropic .rounded-lg { border-radius: 12px!important; }
html.style-anthropic .rounded-md { border-radius: 8px!important; }

/* Success / Error Alerts */
html.style-anthropic .bg-green-100 { background-color: #ECF5F0!important; }
html.style-anthropic .border-green-400 { border-color: var(--a-green)!important; }
html.style-anthropic .text-green-700 { color: #1B5E40!important; }
html.style-anthropic .bg-red-100 { background-color: #FCEEED!important; }
html.style-anthropic .border-red-400 { border-color: var(--a-red)!important; }
html.style-anthropic .text-red-700 { color: #8B2A1E!important; }

/* Status-Badges (subtilere Farben) */
html.style-anthropic .bg-gray-200.text-gray-800 { background-color:#EDEAE5!important; color:#4A4A4A!important; }
html.style-anthropic .bg-yellow-200 { background-color:#FDF3DC!important; }
html.style-anthropic .text-yellow-800 { color:#7A5B0B!important; }
html.style-anthropic .bg-blue-200 { background-color:#E8EEF6!important; }
html.style-anthropic .text-blue-800 { color:#2E5480!important; }
html.style-anthropic .bg-purple-200 { background-color:#EFEBF5!important; }
html.style-anthropic .text-purple-800 { color:#5B3D8F!important; }
html.style-anthropic .bg-green-200 { background-color:#E2F0E9!important; }
html.style-anthropic .text-green-800 { color:#1B5E40!important; }
html.style-anthropic .bg-red-200 { background-color:#FCEEED!important; }
html.style-anthropic .text-red-800 { color:#8B2A1E!important; }

/* Tabellen-Header */
html.style-anthropic thead.bg-gray-50 th { color: var(--a-text2)!important; font-weight:600!important; text-transform:uppercase!important; font-size:.75rem!important; letter-spacing:.04em!important; }

/* Nav-Kacheln */
html.style-anthropic a.block.bg-white:hover {
    box-shadow: 0 4px 16px rgba(217,119,87,.12)!important;
    border-color: rgba(217,119,87,.3)!important;
}

/* Hover-Übergänge global */
html.style-anthropic a, html.style-anthropic button { transition: all .15s ease!important; }

/* ============================================================
   ANTHROPIC STYLE – Dark
   Dunkles Warmton-Theme
   ============================================================ */
html.dark.style-anthropic {
    --a-bg:       #1A1816;
    --a-surface:  #252220;
    --a-subtle:   #302C28;
    --a-border:   #3D3833;
    --a-text:     #EDEBE8;
    --a-text2:    #A39E96;
    --a-text3:    #7A756E;
    --a-primary:  #E09070;
    --a-primary-h:#C87858;
    --a-green:    #4CAF7A;
    --a-green-h:  #3D9466;
    --a-red:      #E06050;
    --a-yellow:   #D4A840;
}

html.dark.style-anthropic body {
    background-color: var(--a-bg)!important;
    color: var(--a-text)!important;
}
html.dark.style-anthropic h1,
html.dark.style-anthropic h2,
html.dark.style-anthropic h3 { color: var(--a-text)!important; }

html.dark.style-anthropic .bg-gray-100 { background-color: var(--a-bg)!important; }
html.dark.style-anthropic .bg-gray-50  { background-color: var(--a-subtle)!important; }
html.dark.style-anthropic .bg-white    { background-color: var(--a-surface)!important; }
html.dark.style-anthropic header.bg-white,
html.dark.style-anthropic div.bg-white  { background-color: var(--a-surface)!important; }
html.dark.style-anthropic .bg-gray-200 { background-color: var(--a-subtle)!important; }

html.dark.style-anthropic .text-gray-900 { color: var(--a-text)!important; }
html.dark.style-anthropic .text-gray-800 { color: var(--a-text)!important; }
html.dark.style-anthropic .text-gray-700 { color: #C4BFB8!important; }
html.dark.style-anthropic .text-gray-600 { color: var(--a-text2)!important; }
html.dark.style-anthropic .text-gray-500 { color: var(--a-text3)!important; }
html.dark.style-anthropic .text-gray-400 { color: #5A5650!important; }

html.dark.style-anthropic .border-gray-200 { border-color: var(--a-border)!important; }
html.dark.style-anthropic .border-gray-300 { border-color: #4A453E!important; }
html.dark.style-anthropic .divide-gray-200>*+* { border-color: var(--a-border)!important; }

html.dark.style-anthropic .shadow    { box-shadow: 0 1px 3px rgba(0,0,0,.4)!important; }
html.dark.style-anthropic .shadow-md { box-shadow: 0 2px 8px rgba(0,0,0,.4)!important; }
html.dark.style-anthropic .shadow-xl { box-shadow: 0 8px 30px rgba(0,0,0,.5)!important; }

html.dark.style-anthropic input,
html.dark.style-anthropic textarea,
html.dark.style-anthropic select {
    background-color: var(--a-bg)!important;
    color: var(--a-text)!important;
    border-color: var(--a-border)!important;
}
html.dark.style-anthropic input:focus,
html.dark.style-anthropic textarea:focus,
html.dark.style-anthropic select:focus {
    border-color: var(--a-primary)!important;
    box-shadow: 0 0 0 3px rgba(224,144,112,.2)!important;
}
html.dark.style-anthropic input::placeholder,
html.dark.style-anthropic textarea::placeholder { color: var(--a-text3)!important; }

html.dark.style-anthropic .bg-green-100  { background-color: #1A2F22!important; }
html.dark.style-anthropic .text-green-700{ color: #6FCF97!important; }
html.dark.style-anthropic .border-green-400{ border-color: var(--a-green)!important; }
html.dark.style-anthropic .text-green-600{ color: var(--a-green)!important; }
html.dark.style-anthropic .bg-red-100    { background-color: #2F1A1A!important; }
html.dark.style-anthropic .text-red-700  { color: #F09080!important; }
html.dark.style-anthropic .border-red-400{ border-color: var(--a-red)!important; }

html.dark.style-anthropic .bg-gray-200.text-gray-700 { color: #C4BFB8!important; }
html.dark.style-anthropic .hover\:bg-gray-300:hover { background-color: #4A453E!important; }

html.dark.style-anthropic a.bg-gray-200 {
    background-color: var(--a-subtle)!important;
    color: var(--a-text2)!important;
    border: 1px solid var(--a-border)!important;
}

/* Dark Status-Badges */
html.dark.style-anthropic .bg-yellow-200 { background-color:#3D3018!important; }
html.dark.style-anthropic .text-yellow-800 { color:#E8C860!important; }
html.dark.style-anthropic .bg-blue-200 { background-color:#1A2838!important; }
html.dark.style-anthropic .text-blue-800 { color:#7EB0E0!important; }
html.dark.style-anthropic .bg-purple-200 { background-color:#2A1E38!important; }
html.dark.style-anthropic .text-purple-800 { color:#B89CE0!important; }
html.dark.style-anthropic .bg-green-200 { background-color:#1A2F22!important; }
html.dark.style-anthropic .text-green-800 { color:#6FCF97!important; }
html.dark.style-anthropic .bg-red-200 { background-color:#2F1A1A!important; }
html.dark.style-anthropic .text-red-800 { color:#F09080!important; }
html.dark.style-anthropic .bg-gray-200.text-gray-800 { background-color:var(--a-subtle)!important; color:var(--a-text2)!important; }

html.dark.style-anthropic thead.bg-gray-50 th { color: var(--a-text2)!important; }

html.dark.style-anthropic a.block.bg-white:hover {
    box-shadow: 0 4px 16px rgba(224,144,112,.08)!important;
}
';
}
