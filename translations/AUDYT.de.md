<p align="center">
  <a href="../AUDYT.md">English</a> ·
  <a href="AUDYT.fr.md">Français</a> ·
  <a href="AUDYT.de.md">Deutsch</a> ·
  <a href="AUDYT.es.md">Español</a> ·
  <a href="AUDYT.pt.md">Português</a> ·
  <a href="AUDYT.it.md">Italiano</a> ·
</p>

# PrestaShift-Audit

Jeder Punkt folgt derselben Struktur: **Angaben der PrestaShop-Dokumentation** (mit Quellenadresse), **Funktionsweise des Moduls** (Datei und Zeile), **Bewertung** und **Maßnahme**. Punkte, die sich weder auf die Dokumentation noch auf den Quellcode des Kerns stützen, werden als Hypothesen gekennzeichnet und erst korrigiert, wenn sie bestätigt wurden.

Quellen: technische PrestaShop-Dokumentation (`github.com/prestashop/docs`, Branch 9.x), abgerufen über Context7, sowie der Quellcode des Kerns in den Testcontainern (1.7.8.9, 8.2.4, 9.0.3, 9.1.0).

Status: Audit läuft, begonnen am 2026-10-21.

---

## A1. Bestand über die API des Kerns gespeichert

**Dokumentation.** Hook `actionUpdateQuantity`
(`modules/concepts/hooks/list-of-hooks/actionUpdateQuantity.md`): Typ `action`,
Position `front office`, Beschreibung: „Quantity is updated only when a customer
effectively places their order“. FAQ zum Lagerbestand (`faq/stock.md`): „When you edit
the virtual quantity of a Product you are going to edit the
`StockAvailable::quantity` field directly in your database. When you modify your
stock through a `StockMovement` you apply a delta to your existing stock.“

**Bisheriges Verhalten des Moduls.** `ProductMigrationStep::importStock()` und
`ProductAttributeMigrationStep::importStock()` riefen für jeden
Datensatz `StockAvailable::setQuantity()` und `setProductOutOfStock()` auf.
Dieser Aufruf speichert mit den Standardargumenten eine Lagerbewegung
(`StockManager::saveMovement()`) und löst `actionUpdateQuantity` aus.

**Bewertung.** Drei unterschiedliche Probleme:

1. Für jedes migrierte Produkt und jede Kombination wurde eine Lagerbewegung gespeichert. Durch Messungen bestätigt: Im Zielshop gab es 4.439 Produkte und 4.439 auf den Migrationszeitpunkt datierte Bewegungen, während es im Quellshop keine einzige Bewegung gab. Beim Import wird ein absoluter Lagerbestand festgelegt. Gemäß der FAQ muss daher das Feld direkt gespeichert werden und nicht eine Bestandsänderung.

2. Der Hook `actionUpdateQuantity` wurde für jedes Produkt einmal ausgelöst: Der Code von Drittanbieter-Modulen wurde somit während des Imports ausgeführt, entgegen der Beschreibung des Hooks („der Kunde hat eine Bestellung aufgegeben“). Im Testshop verursachte das Modul feedforge dabei einen fatalen Fehler, der die Migration beim Kunden unterbrochen hätte.

3. Kosten: 147 ms pro Produkt (gemessen unter PS 9.2 mit 100 Produkten), gegenüber 0,22 ms bei einem einzigen zusammengefassten Schreibvorgang pro Batch. Dies machte den größten Teil der Ausführungszeit des Batches aus.

**Maßnahme.** Die neue Datei `src/Service/StockWriter.php` schreibt mit einem einzigen `INSERT ... ON DUPLICATE KEY UPDATE` pro Batch in
`ps_stock_available`. Dabei werden alle in der Webservice-Dokumentation für diese Entität angegebenen Felder verwendet (`id_product`,
`id_product_attribute`, `id_shop`, `id_shop_group`, `quantity`,
`depends_on_stock`, `out_of_stock`), ohne Lagerbewegungen oder Hooks. Die Implementierung ist in beide Schritte integriert. Zur Überprüfung: `verify_stock.php` vergleicht den Bestand jedes Produkts und jeder Kombination anhand der ID-Zuordnungstabelle mit dem Bestand der Quelle.

---

## A2. Produkte als indexiert markiert, obwohl sie es nicht sind

**Dokumentation.** Migrationsleitfaden (`basics/keeping-up-to-date/migration.md`):
„Imported products are not immediately available for search to your customers.
You must refresh the search index to make them available. Go to Shop parameters >
Search > Indexing and click on »Add missing product to the index«“.
Beschreibung des Cronjobs zur Indexierung (`development/products-lifecycle/search-index.md`):
Der Parameter `full=1` erstellt den gesamten Index neu, während `full=0` oder ein fehlender Parameter bedeutet:
„it will index only missing products“. Der Befehl
`prestashop:search:index --full` wird für große Kataloge als zeitaufwendig beschrieben.

**Bisheriges Verhalten des Moduls.** `ProductMigrationStep` schrieb
`'indexed' => 1` in `ps_product` und `ps_product_shop`, und der Controller der
abschließenden Aufgaben führte bedingungslos `UPDATE ps_product SET indexed = 1` aus.
Gleichzeitig wurde die integrierte Neuerstellung des Index bei Katalogen mit mehr als
2.000 Produkten übersprungen.

**Bewertung.** Bei einem Katalog oberhalb dieses Grenzwerts wurde nichts indexiert,
obwohl alle Produkte `indexed = 1` hatten. Die von der Dokumentation empfohlene Methode („Add missing products to the index“) erkannte daher keine fehlenden Produkte: Der Benutzer klickte darauf, erhielt eine Bestätigung, aber die Suchmaschine lieferte weiterhin keine Ergebnisse. Durch Messungen bestätigt:
3.471 Produkte, davon 3.475 mit `indexed = 1`, und 681 Zeilen in
`ps_search_index`, die ausschließlich den Produkten des ursprünglichen Shops entsprachen.

**Maßnahme.** Migrierte Produkte erhalten `indexed = 0`, und das
bedingungslose `UPDATE` in den abschließenden Aufgaben wurde entfernt:
`Search::indexation()` setzt das Kennzeichen für die Produkte, die tatsächlich indexiert werden.

**Noch zu entscheiden.** Die Meldung, die angezeigt wird, wenn die Indexierung übersprungen wird, lautet derzeit „rebuild it in Shop Parameters > Search“. Nach dieser Korrektur ist die schnellere und dokumentationskonforme Option „Add missing products to the index“. Die Meldung sollte daher entsprechend angepasst werden. Da es sich jedoch um eine für den Benutzer sichtbare Textänderung sowie um sechs Übersetzungsdateien handelt, steht die Freigabe noch aus.

---

## A3. Bestellungen: Shop und Status — Hypothese verworfen

**Verdacht.** `OrderMigrationStep::importOrder()` setzt im Array
`'id_shop' => $data['id_shop']` und `'current_state' => $data['current_state']`,
also die Werte des alten Shops. Wenn dies so bliebe, würden die Bestellungen auf einen nicht vorhandenen Shop und einen nicht vorhandenen Status verweisen.

**Überprüfung.** Die Zeile wird über `IdMapper::row('orders', ...)` verarbeitet, und die Spaltenzuordnung (`IdMapper.php:200`) übersetzt `id_shop` über das Wörterbuch `shop` (`SchemaHelper::getTargetShopId()`), `id_shop_group` über
`shop_group` und `current_state` über `order_state`, also anhand der im Assistenten definierten Status-Zuordnungstabelle. Das Wörterbuch
`shop` ist in `IdMapper.php:1078` definiert.

**Bewertung.** Die Funktionsweise ist korrekt; hier liegt kein Fehler vor.
Dieser Punkt bleibt im Audit, um festzuhalten, dass er überprüft wurde.

**Bei großem Datenbestand noch zu überprüfen.** Nach der Migration prüfen, ob
`orders.current_state` mit dem letzten Eintrag in `ps_order_history` übereinstimmt.
Dafür ist ein Testlauf mit einer großen Anzahl von Bestellungen erforderlich. Die Prüfung wird in die Messungen unter hoher Last aufgenommen.

---

## A4. Bilder: Formate und Erstellung der Vorschaubilder

**Verdacht 1.** Das Modul ruft aus dem alten Shop ausschließlich
`img/p/<pfad>/<id>.jpg` ab. Ein Shop, der auf PNG eingestellt ist, würde daher seine Bilder verlieren.

**Überprüfung.** Im Kern, `ImageManager.php:154`: „Desired file type **inside**
the image. If jpg and $forceType is false, format inside will be decided by
PS_IMAGE_QUALITY“. Der Dateiname auf der Festplatte bleibt unabhängig von der Einstellung `<id>.jpg`. Dies wird auch durch den Inhalt des Verzeichnisses
`img/p` im Quellshop bestätigt (408 Dateien, alle im Format `.jpg`). **Hypothese verworfen.**

**Verdacht 2.** PS 9 besitzt eine separate Einstellung `PS_IMAGE_FORMAT`
(`src/Core/Image/ImageFormatConfiguration.php`) für WebP und AVIF, und das Modul
erstellt nur `.jpg`-Vorschaubilder. Ein Shop, der WebP ausliefert, würde daher leere Bildbereiche anzeigen.

**Überprüfung.** `src/Adapter/Image/ImageRetriever::checkOrGenerateImageType()`
prüft `file_exists()` und erstellt das fehlende Vorschaubild bei Bedarf im
angeforderten Format. Dasselbe Verhalten gibt es in 1.7.8 (`ImageRetriever.php:182`)
und 8.2 (`ImageRetriever.php:324`). **Hypothese für alle Zielversionen verworfen.**

**Unerwartetes gegenteiliges Ergebnis.** Da der Kern fehlende Vorschaubilder selbst erstellt, ist es weitgehend unnötig, während der Migration für jedes Bild alle Formate zu generieren. Bei 100.000 Bildern und fünf Formaten bedeutet dies eine halbe Million Skalierungsvorgänge, während der Benutzer wartet. PrestaShop 9.1 verfügt über ein eigenes Werkzeug:
`php bin/console prestashop:thumbnails:regenerate products`
(Dokumentation `development/components/console/prestashop-thumbnails-regenerate.md`),
und im Administrationsbereich gibt es eine Schaltfläche zur Neugenerierung.

**Maßnahme (Vereinbarung vom 2026-10-21).** Die Erstellung der Vorschaubilder wurde entfernt; das Modul speichert nur noch das Originalbild. Diese Entscheidung basiert auf drei Punkten:

1. Der Kern erstellt ein fehlendes Vorschaubild bei dessen erster Verwendung, sowohl im Front Office (`ImageRetriever::checkOrGenerateImageType()`, derselbe Code in 1.7.8, 8.2 und 9.1) als auch im Administrationsbereich
(`ImageManager::thumbnail()`, das das Originalbild nach `img/tmp` skaliert, nachdem mit `file_exists()` geprüft wurde, dass das Original vorhanden ist).

2. Ein Kommentar in unserem Code behauptete, dass Vorschaubilder erforderlich seien, damit der Administrationsbereich das Bild anzeigen kann. Das ist falsch: Der Administrationsbereich erstellt sie aus dem Originalbild.

3. Das Referenzmodul macht dasselbe: `EDImport::copyImg()` führt
`copy($tmpfile, $path . '.jpg')` aus und erstellt keine Bildgrößen.

Eingesparter Aufwand: fünf Skalierungsvorgänge pro Bild, also eine halbe Million bei einem Katalog mit 100.000 Bildern. Wer möchte, dass alles sofort bereitsteht, kann „Regenerate thumbnails“ im Administrationsbereich oder ab Version 9.1 den Befehl
`php bin/console prestashop:thumbnails:regenerate products` verwenden.

---

## A5. Kundenpasswörter im alten Format funktionieren im neuen Shop nicht mehr

**Dokumentation und Quellcode des Kerns.** Änderungen in Version 8.0
(`modules/core-updates/8.0.md`): „`Customer::$passwd` to be
`Validate::isHashedPassword()`“. Änderungen in Version 8.1: „Introduced
`PrestaShop\PrestaShop\Core\Security\Hashing` class to hash a password from a
plain text and a salt with md5“. Die Implementierung der Überprüfung
(`src/Core/Crypto/Hashing.php`): `checkHash($passwd, $hash, staticSalt =
_COOKIE_KEY_)` verwendet zwei Methoden: `bcrypt` mit
`password_verify(passwd, hash)` und `md5` mit `md5(staticSalt . $passwd) ===
$hash`.

**Bewertung.** Bcrypt-Passwörter werden problemlos übertragen, da ihre Überprüfung keine shop-spezifischen Informationen verwendet. Passwörter im alten MD5-Format werden mit dem `COOKIE_KEY` des Shops gesalzen, der die Überprüfung durchführt. Nach der Migration sind sie daher nicht mehr gültig: Der neue Shop besitzt einen anderen Schlüssel. Dieser Schlüssel befindet sich in
`app/config/parameters.php` und nicht in der Datenbank. Der Connector, der die Datenbank ausliest, hat daher keinen Zugriff darauf und kann ihn bei der Migration nicht übertragen.

Betroffen sind Konten, die unter Version 1.5 und 1.6 erstellt wurden und deren Inhaber sich seitdem nicht mehr angemeldet haben. Davon kann es im alten Shop sehr viele geben. Das Modul kopierte das Passwort, ohne auf dieses Problem hinzuweisen.

**Maßnahme (A5).** `CustomerMigrationStep::reportOldPasswords()` erkennt
Passwörter im MD5-Format (32 hexadezimale Zeichen) und schreibt eine Warnung in das Migrationsprotokoll. Diese enthält die Anzahl der betroffenen Konten im Batch sowie den Hinweis, dass diese Kunden die Funktion „Passwort vergessen“ verwenden müssen. Ihre Konten, Bestellungen und Adressen werden normal migriert. Eine andere Lösung als die Übertragung des Shop-Schlüssels ist nicht möglich. Dies würde jedoch die bereits im neuen Shop vorhandenen Konten gefährden.

---

## A6. Der Connector ließ mehr als nur „Lesezugriff“ zu

**Bisheriges Verhalten.** In `psconnector/api.php` prüfte die Aktion `query`
die Abfrage anhand einer Blacklist der ersten Wörter
(`INSERT`, `UPDATE`, `DELETE`, `DROP`, …) und wies sie nur zurück, wenn die Abfrage mit einem dieser Wörter begann.

**Bewertung.** Eine Sicherheitslücke, und keine kleine. Alles, was mit
`SELECT` begann, passierte diesen Filter:

- `SELECT ... INTO OUTFILE '/pfad/datei.php'` — mit dem MySQL-Recht
  `FILE`, das bei vielen Shared-Hosting-Anbietern vergeben wird, speichert diese Anweisung beliebige Dateien an einem Ort, an dem der Datenbankbenutzer Schreibrechte besitzt, einschließlich einer PHP-Datei im Shop-Verzeichnis.
  Dies ermöglicht die Remote-Ausführung von Code auf dem alten Shop.

- `SELECT LOAD_FILE('/etc/passwd')` — Lesen beliebiger Dateien,
  auf die MySQL zugreifen kann.

- `SELECT SLEEP(30)`, `BENCHMARK(...)` — Blockieren des PHP-Prozesses für
  eine beliebige Dauer.

- Ein Kommentar vor der Abfrage (`/* */` oder `--`) verdeckte das tatsächlich erste Wort.

Das Abhandenkommen des Connector-Tokens ermöglichte somit weit mehr als einen
einfachen Lesezugriff auf die Daten.

**Maßnahme.** Die Blacklist wurde durch eine Whitelist ersetzt, da das
Modul an die Quelle ausschließlich `SELECT`-Abfragen sendet (überprüft: `SHOW COLUMNS`
in `SchemaHelper` wird im Zielshop ausgeführt, nicht über den Connector). Ab sofort gilt: Kommentare werden vor der Prüfung entfernt, die Abfrage muss mit `SELECT` beginnen, pro Abfrage ist nur eine Anweisung zulässig und `INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE`, `BENCHMARK`,
`SLEEP`, `GET_LOCK` und `RELEASE_LOCK` werden zurückgewiesen.

**Überprüfung.** `test_connector_guard.php` sendet 11 Abfragen an den
echten Connector: Drei normale Leseabfragen (darunter eine mit JOIN und eine mit Unterabfrage) werden akzeptiert. Eine direkte Schreiboperation, eine Schreiboperation, die hinter einem Blockkommentar verborgen ist, eine hinter einem Zeilenkommentar verborgene Schreiboperation, zwei Anweisungen in einer Abfrage, das Schreiben einer Datei, das Lesen einer Datei,
`SLEEP` und `BENCHMARK` werden zurückgewiesen. Alle 11 Ergebnisse entsprechen den Erwartungen.

**Auswirkung für Benutzer.** Der Connector wurde auf Version 1.3.0 aktualisiert. Der Schutz greift auf der Seite des alten Shops: Er schützt daher Benutzer, die die neue Version des Plugins im Quellshop installieren. Das Modul funktioniert mit beiden Versionen.

---

## A7. Aufgaben, die nur einmal in einer einzigen HTTP-Anfrage ausgeführt werden

**Verdacht.** Das Modul verteilt die Arbeit auf mehrere Batches, führt jedoch vier Vorgänge nur einmal in einer einzigen HTTP-Anfrage aus: die Neuerstellung der Kategoriebaumstruktur, die Neuerstellung des Suchindex, die Katalogpreisregeln und die Korrekturabfragen `integrity`. Bei einem großen Katalog kann jeder dieser Vorgänge das maximale Ausführungszeitlimit der Anfrage überschreiten (30 s beim Hosting-Anbieter). In diesem Fall würde die Migration im letzten Schritt fehlschlagen.

**Messung.** Shop mit 50.123 Produkten und 3.122 Kategorien (separate Datenbank, um die anderen Tests nicht zu beeinträchtigen):

| Vorgang | Dauer |
|---|---|
| `Category::regenerateEntireNtree()` | 0,13 s |
| `SpecificPriceRule::applyAllRules()` mit einer einzigen Regel | 1,48 s |
| `integrity`-Korrekturabfragen (3 repräsentative Fälle) | weniger als eine Sekunde |
| `Search::indexation(true)` | mehr als 10 Minuten |

**Bewertung.** Die ersten drei Vorgänge stellen selbst bei 50.000 Produkten kein Problem dar. Der einzige zeitaufwendige Vorgang ist die vollständige Neuerstellung des Suchindex. Das Modul überspringt diese jedoch bei mehr als 2.000 Produkten und überlässt sie den Werkzeugen des Shops. Nach der Korrektur A2 (die Produkte haben `indexed = 0`) funktioniert dies genau so, wie es die PrestaShop-Migrationsdokumentation empfiehlt. Dieser Punkt ist daher ohne Codeänderung abgeschlossen.

**Methode.** Diese Schlussfolgerung erforderte drei Sekunden Messzeit.
Zuvor war ein zweistündiger Testlauf mit 50.000 Produkten geplant, der dieselben zwei Zahlen geliefert hätte. Grundsatz für den weiteren Verlauf des Audits: die Kosten eines Batches und eines Vorgangs messen, statt Millionen von Zeilen zu verarbeiten. Kein Test von mehr als zehn Minuten ohne eindeutig nachgewiesene Notwendigkeit.

---
