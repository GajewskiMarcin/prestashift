<p align="center">
  <img src="../assets/icon-256.png" alt="PrestaShift" title="PrestaShift" width="128" height="128">
</p>

<h1 align="center">PrestaShift</h1>

<p align="center">
  <strong>PrestaShop-Migrationsmodul</strong>
  <br>
  <strong>Einfach · Elegant · Effizient</strong>
  <br>
  <a href="https://github.com/GajewskiMarcin/prestashift/releases">Herunterladen</a> ·
  <a href="#">Produktseite</a>
</p>

<p align="center">
  <a href="../README.md">English</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md">Português</a> ·
  <a href="README.it.md">Italiano</a> ·
</p>

> **Ein einziges Modul:** Professionelles Datenmigrationswerkzeug für PrestaShop. Migrieren Sie die Daten Ihrer Boutique mit Leichtigkeit zwischen PrestaShop 1.7, 8 und 9.

<p align="center">
  <img src="https://img.shields.io/badge/PrestaShop-1.7%2B-DF0067?logo=prestashop&logoColor=white" alt="PrestaShop 1.7+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL 5.7+">
  <img src="https://img.shields.io/badge/MariaDB-10.3%2B-003545?logo=mariadb&logoColor=white" alt="MariaDB 10.3+">
  <img src="https://img.shields.io/badge/license-AFL--3.0-orange.svg" alt="Akademische freie Lizenz 3.0 (AFL-3.0)">
</p>

# Das Projekt PrestaShift ist ein Datenmigrationsmodul für PrestaShop.

**Zusammengefasst:** Es ermöglicht die Migration der Daten eines älteren PrestaShop-Shops (1.7+) in einen neueren Shop (8 oder 9), in Stapeln, mit Wiederaufnahme, Protokollierung und vorheriger Vorschau.

Architektur in zwei Teilen:

* Quelle (alter Shop) → Modul PSConnector (nur lesend, token-gesicherte API)
* Ziel (neuer Shop) → Modul PrestaShift (Migrationsengine)

## Funktionen

- **39-Schritte-Migrationspipeline** — Produkte, Kategorien, Kunden, Bestellungen, Bilder, Versanddienstleister, CMS und vieles mehr
- **Zwei Verbindungsmodi** — Bridge Connector (funktioniert serverübergreifend) oder Direkte Datenbank (schneller, gleicher Server)
- **Versionsbewusst** — automatische Erkennung von Quell-/Zielversionen mit Kompatibilitätswarnungen
- **PS 1.7 → 8/9-Transformationen** — `redirect_type`, `id_type_redirected` automatisch konvertiert
- **Inkrementelle Synchronisation** — migriert nur die Änderungen seit der letzten Synchronisation
- **Stapelverarbeitung** — konfigurierbare Stapelgrößen mit Pause/Fortsetzen
- **Bildübertragung** — lädt Bilder herunter und erzeugt alle Miniaturgrößen
- **Multistore-Unterstützung** — Ziel-Shop-ID wählen
- **Vorabprüfungen** — validiert PHP-Limits, Speicherplatz und cURL vor dem Start
- **Aufgaben nach der Migration** — automatischer Neuaufbau des Suchindex, Kategorienbaums, Cache leeren
- **Probelauf-Vorschau** — Datensatzzahlen vor der Migration ansehen
- **Dateiprotokollierung** — detaillierte Protokolle in `var/logs/prestashift.log`
- **Redirect-Map** — erzeugt eine 301-Redirect-Datei zur SEO-Erhaltung
- **Status-Zuordnung** — Bestellstatus zwischen Quelle und Ziel zuordnen
- **Oberflächensprachen** — Englisch, Polnisch, Französisch, Spanisch, Portugiesisch, Italienisch, Deutsch
- **Selektive Konfiguration** — migriert sichere Shop-Einstellungen (Name, SEO, Versand usw.)
- **Mehrsprachigkeit** — Englisch + Polnisch (übersetzbar über das PrestaShop-Backoffice)

## Migrierte Daten

| Bereich | Daten |
|---------|-------|
| **Katalog** | Produkte, Kategorien, Attribute, Merkmale, Lagerbestand, Packs, Virtuelle Produkte, Anpassungsfelder, Tags |
| **Preisgestaltung** | Spezielle Preise, Katalog-Preisregeln |
| **Medien** | Produktbilder (mit Miniaturen), Anhänge, Hersteller-Logos |
| **Kunden** | Kunden, Gruppen, Adressen, Wunschlisten |
| **Bestellungen** | Bestellungen, Bestelldetails, Verlauf, Zahlungen, Rechnungen, Gutschriften, Warenkörbe |
| **Marken** | Hersteller, Lieferanten, Produkt-Lieferanten-Verknüpfungen |
| **Versand** | Versanddienstleister, Bereiche, Lieferzonen, Gebühren |
| **Inhalt** | CMS-Seiten & -Kategorien, Meta/SEO, Kontakte, Physische Geschäfte |
| **Lokalisierung** | Länder, Bundesländer/Regionen, Zonen, Währungen, Sprachen, Steuerregeln |
| **Verwaltung** | Mitarbeiter, Profile, Warenkorb-Regeln (mit Bedingungen), Shop-Konfiguration |
| **Lager** | Verfügbare Lagermenge, Lagerbewegungen |

## Voraussetzungen

- **Ziel-Shop:** PrestaShop 1.7+ (wo PrestaShift installiert ist)
- **Quell-Shop:** PrestaShop 1.7+ (wo PSConnector installiert ist)
- PHP 7.4+ mit cURL-Erweiterung
- MySQL 5.7+ / MariaDB 10.3+

## Installation

### Auf dem ZIEL-Shop (neuer Shop):
1. Laden Sie den Ordner `prestashift/` nach `/modules/` hoch
2. Installieren über Backoffice → Module → „PrestaShift Migration"

### Auf dem QUELL-Shop (alter Shop):
1. Laden Sie den Ordner `psconnector/` nach `/modules/` hoch
2. Installieren über Backoffice → Module → „PrestaShift Connector"
3. Kopieren Sie das generierte sichere Token von der Modulkonfigurationsseite

### Migration starten:
1. Öffnen Sie PrestaShift im Ziel-Shop
2. Geben Sie Quell-Shop-URL + Token ein
3. Datenumfang auswählen
4. Optionen konfigurieren (Stapelgröße, Ziel bereinigen usw.)
5. Migration starten

## Architektur

```
Quell-Shop (PS 1.7/8)          Ziel-Shop (PS 8/9)
┌─────────────────┐            ┌──────────────────┐
│   PSConnector    │◄──HTTP──► │   PrestaShift     │
│   (nur-lesende   │   Bridge  │   (Migrations-    │
│    API-Brücke)   │           │    engine)        │
└─────────────────┘            └──────────────────┘
```

PSConnector stellt einen sicheren, nur lesbaren API-Endpunkt bereit. PrestaShift verbindet sich per HTTP, ruft Daten in Stapeln ab, transformiert sie für die Versionskompatibilität und importiert sie in die Zieldatenbank.

Alternative: Direkte Datenbankverbindung (PDO) für Migrationen auf demselben Server — schneller, keine Brücke notwendig.

## Sicherheit

- Token-basierte Authentifizierung (64-stelliges Hex)
- Der Connector ist **nur lesend** — Schreiboperationen werden blockiert
- Der Dateizugriff ist auf die Verzeichnisse `img/`, `download/`, `upload/` beschränkt
- Pfad-Traversal-Schutz mit `realpath()`-Validierung
- Zeitkonstanter Token-Vergleich (`hash_equals`)

## Fehlerberichte (opt-in)

Das Modul kann per E-Mail einen Fehlerbericht an den Autor senden, um bei der Diagnose von Migrationsproblemen zu helfen.
**Es ist standardmäßig deaktiviert** — Sie aktivieren es mit dem Schalter *Send Error Reports to Developer*
im Schritt Optionen.

Ist es aktiviert, sendet eine fehlgeschlagene Migration: die Fehlermeldung und die Debug-Payload, Ihre PrestaShop- und
PHP-Versionen, `memory_limit` / `max_execution_time`, die URL des Quell-Shops, den Schritt, bei dem die Migration gestoppt hat, und die verwendeten Einstellungen (Stapelgrößen, Verzögerungen, Umfang), was der letzte Aufruf beim
Quell-Shop tat, die letzten Zeilen des Migrationsprotokolls sowie die E-Mail-Adresse des angemeldeten
Mitarbeiters. Solange der Schalter aus ist, wird nichts gesendet, und es werden niemals Kunden-, Bestell- oder Katalogdaten
übertragen. Siehe [TelemetryService.php](prestashift/src/Service/TelemetryService.php) — dies ist der
einzige Codepfad, der etwas an den Autor sendet. Die andere ausgehende Verbindung des Moduls
(`ConnectorClient`) spricht ausschließlich mit der Quell-Shop-URL, die Sie selbst konfigurieren.

## Support

- [Problem melden](https://github.com/GajewskiMarcin/prestashift/issues)
- [Diskussionen](https://github.com/GajewskiMarcin/prestashift/discussions)
- [Kauf mir einen Kaffee](https://buymeacoffee.com/marcingajewski)

## Autor

Erstellt von [marcingajewski.pl](https://marcingajewski.pl)

## Lizenz

[Akademische freie Lizenz 3.0 (AFL-3.0)](LICENSE) — die Standardlizenz für PrestaShop-Module.

### Attributionshinweis

Das Folgende ist ein **Attributionshinweis** gemäß Abschnitt 6 der AFL-3.0. Abschnitt 6 verlangt,
dass jedes abgeleitete Werk ihn beibehält:

> PrestaShift wurde von Marcin Gajewski erstellt — https://marcingajewski.pl
> Originalprojekt: https://github.com/GajewskiMarcin/prestashift
> Arbeit unterstützen: https://buymeacoffee.com/marcingajewski
>
> Abgeleitete Werke und Forks müssen diesen Hinweis, den Autorencredit und den Unterstützungslink beibehalten, die auf
> dem letzten Bildschirm des Moduls angezeigt werden. „PrestaShift" ist der Name des Originalmoduls: Wenn Sie eine
> geänderte Version veröffentlichen, geben Sie ihr bitte Ihren eigenen Namen.

Forks sind willkommen — als Pull Requests zurückgesendete Korrekturen nutzen allen Benutzern des Moduls.

Beiträge werden über Pull Requests an `main` eingereicht.

### Danksagung

PrestaShift verbessert sich dank dieser Personen:

| Wer | Beitrag |
|---|---|
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |

Die gleiche Liste findet sich im Modul: **Einstellungen → Über → Danksagung**.
