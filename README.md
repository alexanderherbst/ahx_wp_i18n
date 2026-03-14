# AHX WP i18n

**Version:** 0.1.0  
**Autor:** Alexander Herbst  
**Text Domain:** `ahx_wp_i18n`

## Beschreibung

AHX WP i18n ist ein WordPress-Plugin zur vollständigen Verwaltung der Internationalisierung (i18n) von Plugins und Themes — direkt im WordPress-Backend und per WP-CLI.

Es ermöglicht das Erzeugen von POT-Vorlagendateien, das Erstellen und Bearbeiten von PO-Übersetzungsdateien, das Kompilieren zu MO-Binärdateien sowie das automatische Erkennen und Konvertieren von ungekennzeichneten Klartexten im Quellcode.

## Funktionen

### POT-Dateien erzeugen
- Scannt PHP-Quelldateien eines Plugins oder Themes nach `__(...)`, `_e(...)`, `_n(...)`, `_x(...)` und weiteren WordPress-Übersetzungsfunktionen
- Erkennt Übersetzerkommentare (`/* translators: ... */`) und Kontexte (`msgctxt`)
- Erzeugt eine standardkonforme `.pot`-Datei mit Header

### PO-Dateien erstellen und bearbeiten
- Erstellt eine neue `.po`-Datei für eine gewählte Locale aus einer vorhandenen `.pot`-Datei
- Integrierter PO-Editor im Backend: Alle `msgid`-Einträge mit Textfeldern für `msgstr`
- Unterstützt Plural-Formen (`msgid_plural`, `msgstr[0]`, `msgstr[1]`)
- Suchfunktion im Editor (Filter nach Quelltext)
- Speichert Änderungen zurück in die `.po`-Datei

### MO-Kompilierung
- Kompiliert `.po`-Dateien zu binären `.mo`-Dateien, die WordPress zur Laufzeit lädt
- Unterstützt Plural-Blöcke

### Plain-Text-Scanner (i18n-Assistent)
- Scannt PHP-Dateien nach Klartexten innerhalb von `echo`, String-Konkatenationen etc.
- Zeigt Kandidaten mit Vorschau und erlaubt selektive Konvertierung zu `__()` oder `_e()`
- Nach der Konvertierung: optionaler Deep-Link zum Commit-Dialog von `ahx_wp_github`

### Sprach-Statistik
- Zeigt auf der Detail-Seite alle gefundenen PO-Dateien für das gewählte Plugin/Theme
- Pro Locale: übersetzter Anteil, Pflegegrad, MO-Vorhanden-Status
- Pflegegrade farbkodiert:

| Grad | Schwellenwert | Farbe |
|---|---|---|
| Sehr gut | ≥ 95 % | Grün |
| Gut | ≥ 80 % | Hellgrün |
| Mittel | ≥ 50 % | Orange |
| Niedrig | < 50 % | Rot |
| Nicht gepflegt | 0 übersetzt | Rot |
| Keine Einträge | leere POT | Grau |

### Locale-Auswahl
- Dropdown mit 70+ Standard-Locales
- Ergänzt dynamisch alle in WordPress installierten Sprachen

### Text-Domain-Erkennung
- Automatische Ableitung der Text-Domain aus dem Plugin-/Theme-Header
- Alternativ: Auslesen aus dem `Project-Id-Version`-Header der gewählten POT-Datei
- Live-Synchronisation im Browser: Wechsel der POT-Datei aktualisiert das Text-Domain-Feld

## Backend-Navigation

Das Plugin registriert sich unter **Werkzeuge → i18n** (`tools.php`).

### Landingpage
- Listet alle installierten Plugins und Themes in je einer Tabelle
- Filter: Suchfeld, "nur aktivierte Plugins", "nur Child-Themes"
- Anzahl der Ergebnisse wird in der Tabellenüberschrift angezeigt
- "Details"-Schaltfläche öffnet die Detailseite für das jeweilige Ziel

### Detailseite (je Plugin / Theme)

| Abschnitt | Funktion |
|---|---|
| **Sprachstatus** | Übersicht aller PO-Dateien mit Locale, Pflegegrad, Abdeckung, MO-Status |
| **POT-Datei erzeugen** | Quellscan + POT-Ausgabe |
| **PO-Datei erstellen** | Neue Locale aus POT-Vorlage anlegen |
| **PO-Datei bearbeiten** | Editor für bestehende PO-Dateien |
| **MO kompilieren** | PO → MO kompilieren |
| **Plain-Text-Scanner** | Klartexte finden und in `__()` konvertieren |

## WP-CLI

Das Plugin registriert den Befehl `ahx-i18n`:

```bash
# POT erzeugen
wp ahx-i18n make_pot --target_type=plugin --target=mein-plugin --domain=mein-plugin

# MO kompilieren
wp ahx-i18n compile_mo /pfad/zur/datei.po
```

### `make_pot`

| Option | Standard | Beschreibung |
|---|---|---|
| `--target_type` | — | `plugin` oder `theme` |
| `--target` | — | Ordnername des Ziels |
| `--domain` | `ahx_wp_i18n` | Text-Domain |
| `--output` | — | Ausgabepfad der POT-Datei |
| `--plugins` | `true` | Plugins scannen |
| `--templates` | `true` | Themes scannen |

### `compile_mo`

```bash
wp ahx-i18n compile_mo <pfad_zur_po_datei>
```

## Integration mit ahx_wp_github

Nach einer Plain-Text-Konvertierung erscheint ein Hinweis mit einem Deep-Link zum Commit-Dialog von `ahx_wp_github`. Der Link öffnet sich in einem neuen Tab und befüllt den Commit-Dialog vor:

- **Commit-Nachricht:** `i18n-Korrekturen`
- **Version Bump:** `patch`

## Aktivierung

Bei der Plugin-Aktivierung wird:
- das Verzeichnis `wp-content/languages/ahx_wp_i18n` erstellt (falls nicht vorhanden)
- die Capability `manage_translations` der Rolle `administrator` hinzugefügt

## Dateistruktur

```
ahx_wp_i18n/
├── ahx_wp_i18n.php          # Haupt-Plugin-Datei
└── includes/
    ├── admin.php            # Backend-UI (Landingpage + Detailseite)
    ├── po_mo.php            # PO/MO-Parser, -Renderer, -Compiler, POT-Generator, Scanner
    └── cli.php              # WP-CLI-Befehle
```

## Technische Details

### PO-Parser (`po_mo.php`)
Vollständig in PHP implementierter Gettext-Parser ohne externe Abhängigkeiten. Unterstützt:
- `msgid`, `msgstr`, `msgctxt`
- `msgid_plural`, `msgstr[0]`, `msgstr[1]`
- Übersetzerkommentare (`#.`), Referenzen (`#:`), Flags (`#,`)
- Mehrzeilige Strings (Zeilenfortsetzung)

### Unterstützte WordPress-Übersetzungsfunktionen
`__()`, `_e()`, `_n()`, `_nx()`, `_x()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, `esc_attr_e()`, `esc_html_x()`, `esc_attr_x()`
