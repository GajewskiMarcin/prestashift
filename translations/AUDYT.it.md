<p align="center">
  <a href="../AUDYT.md">English</a> ·
  <a href="AUDYT.fr.md">Français</a> ·
  <a href="AUDYT.de.md">Deutsch</a> ·
  <a href="AUDYT.es.md">Español</a> ·
  <a href="AUDYT.pt.md">Português</a> ·
  <a href="AUDYT.it.md">Italiano</a> ·
</p>

**# Audit PrestaShift**

Ogni punto segue la stessa struttura: **cosa indica la documentazione PrestaShop** (con l'indirizzo della fonte), **cosa fa il modulo** (file e riga), **verdetto** e **azione**. I punti che non si basano né sulla documentazione né sul codice del core sono segnalati come ipotesi e non vengono corretti finché non sono stati confermati.

Fonti: documentazione tecnica PrestaShop (`github.com/prestashop/docs`, branch 9.x), recuperata tramite context7, e codice del core nei container di test (1.7.8.9, 8.2.4, 9.0.3, 9.1.0).

Stato: audit in corso, iniziato il 2026-10-21.

---

**## A1. Stock registrato tramite l'API del core**

**Documentazione.** Hook `actionUpdateQuantity`
(`modules/concepts/hooks/list-of-hooks/actionUpdateQuantity.md`): tipo `action`,
posizione `front office`, descrizione: «Quantity is updated only when a customer
effectively places their order». FAQ sulle giacenze (`faq/stock.md`): «When you edit
the virtual quantity of a Product you are going to edit the
`StockAvailable::quantity` field directly in your database. When you modify your
stock through a `StockMovement` you apply a delta to your existing stock.»

**Cosa faceva il modulo.** `ProductMigrationStep::importStock()` e
`ProductAttributeMigrationStep::importStock()` chiamavano
`StockAvailable::setQuantity()` e `setProductOutOfStock()` per ogni
record. Questa chiamata, con gli argomenti predefiniti, registra un movimento
di stock (`StockManager::saveMovement()`) e attiva `actionUpdateQuantity`.

**Verdetto.** Tre problemi distinti:

1. Un movimento di stock per ogni prodotto e variante migrati. Confermato
   dalle misurazioni: nel negozio di destinazione, 4.439 prodotti e 4.439 movimenti
   datati al momento della migrazione, contro zero movimenti nel negozio sorgente.
   L'importazione imposta una quantità assoluta; conformemente alle FAQ, è quindi
   opportuno registrare direttamente il campo, non una variazione.

2. L'hook `actionUpdateQuantity` veniva attivato una volta per prodotto: il codice
   dei moduli di terze parti veniva quindi eseguito durante l'importazione,
   contrariamente alla descrizione dell'hook («il cliente ha effettuato un ordine»).
   Nel negozio di test, il modulo feedforge ha provocato un errore fatale in quel
   momento, che avrebbe interrotto la migrazione presso il cliente.

3. Costo: 147 ms per prodotto (misurati su PS 9.2, 100 prodotti), contro 0,22 ms
   con una sola scrittura raggruppata per lotto. Questo rappresentava la maggior
   parte del tempo di esecuzione del lotto.

**Azione.** Il nuovo file `src/Service/StockWriter.php` scrive in
`ps_stock_available` con un solo `INSERT ... ON DUPLICATE KEY UPDATE` per lotto,
utilizzando tutti i campi indicati dalla documentazione del webservice per questa
entità (`id_product`,
`id_product_attribute`, `id_shop`, `id_shop_group`, `quantity`,
`depends_on_stock`, `out_of_stock`), senza movimenti né hook. Integrato in
entrambe le fasi. Per la verifica: `verify_stock.php` confronta lo stock di
ogni prodotto e variante con quello della sorgente, utilizzando la tabella di
corrispondenza degli ID.

---

**## A2. Prodotti contrassegnati come indicizzati quando in realtà non lo sono**

**Documentazione.** Guida alla migrazione (`basics/keeping-up-to-date/migration.md`):
«Imported products are not immediately available for search to your customers.
You must refresh the search index to make them available. Go to Shop parameters >
Search > Indexing and click on »Add missing product to the index«».
Descrizione dell'attività cron di indicizzazione (`development/products-lifecycle/search-index.md`):
il parametro `full=1` ricostruisce l'intero indice, `full=0` o assente —
«it will index only missing products». Il comando
`prestashop:search:index --full` è descritto come lungo da eseguire sui
cataloghi di grandi dimensioni.

**Cosa faceva il modulo.** `ProductMigrationStep` scriveva
`'indexed' => 1` in `ps_product` e `ps_product_shop`, e il controller delle
attività finali eseguiva `UPDATE ps_product SET indexed = 1` senza condizioni.
Nel frattempo, la ricostruzione integrata dell'indice veniva ignorata per i
cataloghi con più di 2.000 prodotti.

**Verdetto.** Per un catalogo che superava la soglia, non veniva indicizzato
nulla, mentre tutti i prodotti avevano `indexed = 1`. Il metodo raccomandato dalla
documentazione («Add missing products to the index») non rilevava quindi alcun
prodotto mancante: l'utente cliccava, riceveva una conferma, ma il motore di
ricerca continuava a non restituire alcun risultato. Confermato dalle misurazioni:
3.471 prodotti, 3.475 con `indexed = 1` e 681 righe in
`ps_search_index`, corrispondenti esclusivamente ai prodotti del negozio
originale.

**Azione.** Ai prodotti migrati viene assegnato `indexed = 0` e
l'`UPDATE` incondizionato delle attività finali è stato eliminato: `Search::indexation()`
imposta il flag per i prodotti che indicizza effettivamente.

**Da decidere.** Il messaggio visualizzato quando l'indicizzazione viene ignorata
indica attualmente «rebuild it in Shop Parameters > Search». Dopo questa correzione,
l'opzione più rapida e conforme alla documentazione è «Add missing products to
the index»; il messaggio dovrebbe quindi essere modificato di conseguenza. Tuttavia,
si tratta di una modifica del testo visibile all'utente e di sei file di
traduzione: è in attesa di approvazione.

---

**## A3. Ordini: negozio e stato — ipotesi respinta**

**Sospetto.** `OrderMigrationStep::importOrder()` inserisce nell'array
`'id_shop' => $data['id_shop']` e `'current_state' => $data['current_state']`,
cioè i valori del vecchio negozio. Se rimanessero invariati, gli ordini
punterebbero a un negozio e a uno stato inesistenti.

**Verifica.** La riga passa attraverso `IdMapper::row('orders', ...)` e la
corrispondenza delle colonne (`IdMapper.php:200`) traduce `id_shop` tramite
il dizionario `shop` (`SchemaHelper::getTargetShopId()`), `id_shop_group` tramite
`shop_group` e `current_state` tramite `order_state`, utilizzando cioè la tabella
di corrispondenza degli stati definita nell'assistente. Il dizionario
`shop` è definito in `IdMapper.php:1078`.

**Verdetto.** Il funzionamento è corretto, non c'è alcun errore in questo punto.
Questo elemento rimane nell'audit per documentare che è stato verificato.

**Da verificare su larga scala.** Dopo la migrazione, verificare che
`orders.current_state` corrisponda all'ultima voce di `ps_order_history` —
ciò richiede un'esecuzione su un volume elevato di ordini e sarà integrato
nella misurazione su larga scala.

---

**## A4. Immagini: formati e generazione delle miniature**

**Sospetto 1.** Il modulo recupera dal vecchio negozio soltanto
`img/p/<percorso>/<id>.jpg`; un negozio configurato in PNG perderebbe quindi le proprie immagini.

**Verifica.** Nel core, `ImageManager.php:154`: «Desired file type **inside**
the image. If jpg and $forceType is false, format inside will be decided by
PS_IMAGE_QUALITY». Il nome del file sul disco rimane `<id>.jpg`, indipendentemente
dall'impostazione; ciò è confermato anche dal contenuto della directory
`img/p` nel negozio sorgente (408 file, tutti in `.jpg`). **Ipotesi respinta.**

**Sospetto 2.** PS 9 dispone di un'impostazione distinta `PS_IMAGE_FORMAT`
(`src/Core/Image/ImageFormatConfiguration.php`) per webp e avif, mentre il modulo
genera soltanto miniature `.jpg`: un negozio che distribuisce immagini in webp
visualizzerebbe quindi spazi vuoti.

**Verifica.** `src/Adapter/Image/ImageRetriever::checkOrGenerateImageType()`
controlla `file_exists()` e genera al volo la miniatura mancante nel
formato richiesto. Lo stesso comportamento è presente in 1.7.8 (`ImageRetriever.php:182`)
e 8.2 (`ImageRetriever.php:324`). **Ipotesi respinta** per tutte le
versioni di destinazione.

**Conclusione opposta a quella prevista.** Poiché il core genera autonomamente
le miniature mancanti, generare tutti i formati per ogni immagine durante la
migrazione è un lavoro in gran parte inutile. Per 100.000 immagini e cinque
formati, significa mezzo milione di operazioni di ridimensionamento mentre
l'utente è in attesa. PrestaShop 9.1 dispone di uno strumento dedicato:
`php bin/console prestashop:thumbnails:regenerate products`
(documentazione `development/components/console/prestashop-thumbnails-regenerate.md`),
e il pannello di amministrazione dispone di un pulsante per la rigenerazione.

**Azione (accordo del 2026-10-21).** La generazione delle miniature è stata
rimossa; il modulo salva soltanto l'originale. Questa decisione si basa su tre
elementi:

1. Il core genera la miniatura mancante al primo utilizzo, nel
   front office (`ImageRetriever::checkOrGenerateImageType()`, stesso codice in
   1.7.8, 8.2 e 9.1) e nel pannello di amministrazione
   (`ImageManager::thumbnail()`, che ridimensiona l'originale verso `img/tmp`,
   dopo aver verificato con `file_exists()` che l'originale esista).

2. Un commento nel nostro codice affermava che le miniature erano necessarie
   affinché il pannello di amministrazione visualizzasse l'immagine. È falso:
   il pannello le calcola a partire dall'originale.

3. Il modulo di riferimento fa la stessa cosa: `EDImport::copyImg()` esegue
   `copy($tmpfile, $path . '.jpg')` e non genera alcuna dimensione.

Costo eliminato: cinque operazioni di ridimensionamento per immagine, ovvero mezzo
milione per un catalogo di 100.000 immagini. Chi desidera che tutto sia pronto
immediatamente dispone della funzione «Regenerate thumbnails» nel pannello
di amministrazione oppure, a partire dalla versione 9.1, del comando
`php bin/console prestashop:thumbnails:regenerate products`.

---

**## A5. Le password dei clienti nel vecchio formato non funzioneranno nel nuovo negozio**

**Documentazione e codice del core.** Modifiche della versione 8.0
(`modules/core-updates/8.0.md`): «`Customer::$passwd` to be
`Validate::isHashedPassword()`». Modifiche della versione 8.1: «Introduced
`PrestaShop\PrestaShop\Core\Security\Hashing` class to hash a password from a
plain text and a salt with md5». L'implementazione della verifica
(`src/Core/Crypto/Hashing.php`): `checkHash($passwd, $hash, staticSalt =
_COOKIE_KEY_)` utilizza due metodi — `bcrypt` con
`password_verify(passwd, hash)` e `md5` con `md5(staticSalt . $passwd) ===
$hash`.

**Verdetto.** Le password bcrypt vengono trasferite senza problemi, perché
la loro verifica non utilizza alcun elemento specifico del negozio. Le password
nel vecchio formato md5 sono sottoposte a salt con la chiave `COOKIE_KEY` del
negozio che effettua la verifica; dopo la migrazione, quindi, non saranno più
valide: il nuovo negozio dispone di una chiave diversa. Questa chiave si trova
in `app/config/parameters.php`, non nel database; il connettore che legge il
database non vi ha quindi accesso e nessuna migrazione può trasferirla.
Il problema riguarda gli account creati con le versioni 1.5 e 1.6 i cui
proprietari non hanno effettuato nuovamente l'accesso da allora: potrebbero
essere molto numerosi nel vecchio negozio. Il modulo copiava la password senza
segnalare questo problema.

**Azione (A5).** `CustomerMigrationStep::reportOldPasswords()` rileva le
password in formato md5 (32 caratteri esadecimali) e registra nel log
della migrazione un avviso che indica il numero di questi account nel lotto,
oltre all'informazione che questi clienti dovranno utilizzare la funzione
«password dimenticata». I loro account, ordini e indirizzi vengono invece
migrati normalmente. Non è possibile risolvere il problema in altro modo
se non trasferendo la chiave del negozio, il che comprometterebbe gli account
già presenti nel nuovo negozio.

---

## A6. Il connettore accettava più di quanto consentisse la modalità «sola lettura»

**Cosa faceva.** In `psconnector/api.php`, l'azione `query`
verificava la query utilizzando una lista nera di prime parole
(`INSERT`, `UPDATE`, `DELETE`, `DROP`, …) e la rifiutava soltanto se la query
iniziava con una di queste parole.

**Verdetto.** Una vulnerabilità, e non di poco conto. Tutto ciò che iniziava con
`SELECT` superava il filtro:

- `SELECT ... INTO OUTFILE '/percorso/file.php'` — con il privilegio MySQL
  `FILE`, concesso da molti hosting condivisi, questa istruzione
  salva qualsiasi file in una posizione in cui l'utente del database
  ha il diritto di scrivere, compreso un file PHP nella directory del negozio.
  Si tratta di un'esecuzione di codice da remoto sul vecchio negozio.

- `SELECT LOAD_FILE('/etc/passwd')` — lettura di qualsiasi file
  accessibile a MySQL.

- `SELECT SLEEP(30)`, `BENCHMARK(...)` — blocco del processo PHP per
  un periodo di tempo arbitrario.

- Un commento inserito prima della query (`/* */` oppure `--`) nascondeva la vera
  prima parola.

La fuga del token del connettore aveva quindi un valore ben superiore al semplice
accesso in lettura ai dati.

**Azione.** La lista nera è stata sostituita con una lista bianca, perché il
modulo invia alla sorgente soltanto query `SELECT` (verificato: `SHOW COLUMNS`
in `SchemaHelper` viene eseguito sul negozio di destinazione, non tramite il
connettore). Ora: i commenti vengono rimossi prima della verifica,
la query deve iniziare con `SELECT`, è consentita una sola istruzione per
query e `INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE`, `BENCHMARK`,
`SLEEP`, `GET_LOCK` e `RELEASE_LOCK` vengono rifiutati.

**Verifica.** `test_connector_guard.php` invia 11 query al
connettore reale: tre letture ordinarie (di cui una con JOIN e una con
sottoquery) vengono accettate; una scrittura diretta, una scrittura nascosta
dietro un commento di blocco e un commento di riga, due istruzioni
nella stessa query, la scrittura di un file, la lettura di un file,
`SLEEP` e `BENCHMARK` vengono rifiutati. Tutti e 11 i risultati sono conformi
alle aspettative.

**Conseguenze per gli utenti.** Il connettore è stato aggiornato
alla versione 1.3.0. La protezione agisce sul lato del vecchio negozio: protegge
quindi gli utenti che installano la nuova versione del plugin sul
negozio sorgente; il modulo funziona con entrambe le versioni.

---

## A7. Attività eseguite una sola volta, in un'unica richiesta

**Sospetto.** Il modulo suddivide il lavoro in lotti, ma esegue quattro
operazioni una sola volta, in un'unica richiesta HTTP: la ricostruzione
dell'albero delle categorie, la ricostruzione dell'indice di ricerca,
le regole dei prezzi del catalogo e le query di correzione `integrity`. Con un
catalogo di grandi dimensioni, ognuna di queste operazioni può superare il tempo
massimo consentito per la richiesta (30 s nell'ambiente di test), causando il
fallimento della migrazione nell'ultima fase.

**Misurazione.** Negozio con 50.123 prodotti e 3.122 categorie (database
separato per non interferire con gli altri test):

| operazione | durata |
|---|---|
| `Category::regenerateEntireNtree()` | 0,13 s |
| `SpecificPriceRule::applyAllRules()` con una sola regola | 1,48 s |
| query di correzione `integrity` (3 casi rappresentativi) | meno di un secondo |
| `Search::indexation(true)` | oltre 10 minuti |

**Verdetto.** Le prime tre operazioni non presentano problemi,
nemmeno con 50.000 prodotti. L'unica operazione costosa è la ricostruzione
completa dell'indice di ricerca — e il modulo la ignora oltre i 2.000 prodotti,
lasciandola agli strumenti del negozio. Dopo la correzione A2 (i prodotti
hanno `indexed = 0`), il funzionamento corrisponde esattamente a quanto
raccomandato dalla documentazione di migrazione PrestaShop. Questo punto è quindi
chiuso senza modifiche al codice.

**Metodo.** Questa conclusione ha richiesto tre secondi di misurazione.
In precedenza, avevo previsto un'esecuzione di due ore su 50.000 prodotti, che
avrebbe fornito gli stessi due dati. Principio per il seguito dell'audit: misurare
il costo di un lotto e di un'operazione, anziché elaborare milioni di righe;
nessun test di durata superiore a dieci minuti senza una necessità chiaramente
dimostrata.

---