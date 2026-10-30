<p align="center">
  <img src="../assets/icon-256.png" alt="PrestaShift" title="PrestaShift" width="128" height="128">
</p>

<h1 align="center">PrestaShift</h1>

<p align="center">
  <strong>Modulo di migrazione PrestaShop</strong>
  <br>
  <strong>Semplice · Elegante · Efficace</strong>
  <br>
  <a href="https://github.com/GajewskiMarcin/prestashift/releases">Scarica</a> ·
  <a href="#">Pagina prodotto</a>
</p>

<p align="center">
  <a href="../README.md">English</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md">Português</a> ·
  <a href="README.it.md">Italiano</a> ·
</p>

> **Un solo modulo:** Strumento professionale di migrazione dei dati per PrestaShop. Migra i dati del tuo negozio tra PrestaShop 1.7, 8 e 9 in tutta semplicità.

<p align="center">
  <img src="https://img.shields.io/badge/PrestaShop-1.7%2B-DF0067?logo=prestashop&logoColor=white" alt="PrestaShop 1.7+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL 5.7+">
  <img src="https://img.shields.io/badge/MariaDB-10.3%2B-003545?logo=mariadb&logoColor=white" alt="MariaDB 10.3+">
  <img src="https://img.shields.io/badge/license-AFL--3.0-orange.svg" alt="Licenza libera accademica 3.0 (AFL-3.0)">
</p>

# Il progetto PrestaShift è un modulo di migrazione dei dati per PrestaShop.

**In sintesi:** consente di migrare i dati di un negozio PrestaShop vecchio (1.7+) verso un negozio più recente (8 o 9), a lotti, con ripresa, registrazione dei log e anteprima preliminare.

Architettura in due parti:

* Sorgente (negozio vecchio) → modulo PSConnector (sola lettura, API protetta da token)
* Destinazione (nuovo negozio) → modulo PrestaShift (motore di migrazione

## Funzionalità

- **Pipeline di migrazione in 39 passi** — prodotti, categorie, clienti, ordini, immagini, corrieri, CMS e molto altro
- **Due modalità di connessione** — Bridge Connector (funziona tra server) o Database diretto (più veloce, stesso server)
- **Adattamento alle versioni** — rilevamento automatico delle versioni sorgente/destinazione con avvisi di compatibilità
- **Trasformazioni PS 1.7 → 8/9** — `redirect_type`, `id_type_redirected` convertiti automaticamente
- **Sincronizzazione incrementale** — migra solo le modifiche dall'ultima sincronizzazione
- **Elaborazione a lotti** — dimensioni dei lotti configurabili con pausa/ripresa
- **Trasferimento immagini** — scarica le immagini e genera tutte le dimensioni delle miniature
- **Supporto multinegozio** — scegliere l'ID del negozio di destinazione
- **Controlli pre-volo** — valida i limiti PHP, lo spazio su disco e cURL prima di iniziare
- **Attività post-migrazione** — ricostruzione automatica dell'indice di ricerca, dell'albero delle categorie, svuotamento della cache
- **Anteprima a secco** — visualizzare il numero di record prima della migrazione
- **Registrazione su file** — log dettagliati in `var/logs/prestashift.log`
- **Mappa di redirezione** — genera un file di redirezioni 301 per preservare il SEO
- **Mappatura stati** — associare gli stati degli ordini tra sorgente e destinazione
- **Lingue dell'interfaccia** — inglese, polacco, francese, spagnolo, portoghese, italiano, tedesco
- **Configurazione selettiva** — migra le impostazioni sicure del negozio (nome, SEO, spedizione, ecc.)
- **Multilingue** — inglese + polacco (traducibile dal back-office di PrestaShop)

## Dati migrati

| Dominio | Dati |
|---------|------|
| **Catalogo** | Prodotti, Categorie, Attributi, Caratteristiche, Stock, Pacchetti, Prodotti virtuali, Campi di personalizzazione, Etichette |
| **Prezzi** | Prezzi specifici, Regole dei prezzi del catalogo |
| **Media** | Immagini prodotto (con miniature), Allegati, Loghi dei produttori |
| **Clienti** | Clienti, Gruppi, Indirizzi, Liste desideri |
| **Ordini** | Ordini, Dettagli ordine, Cronologia, Pagamenti, Fatture, Note di credito, Carrelli |
| **Marchi** | Produttori, Fornitori, Collegamenti Prodotto-Fornitore |
| **Spedizione** | Corrieri, Intervalli, Zone di consegna, Spese |
| **Contenuti** | Pagine e categorie CMS, Metadati/SEO, Contatti, Negozi fisici |
| **Localizzazione** | Paesi, Stati/regioni, Zone, Valute, Lingue, Regole fiscali |
| **Amministrazione** | Dipendenti, Profili, Regole del carrello (con condizioni), Configurazione del negozio |
| **Stock** | Stock disponibile, Movimenti di stock |

## Prerequisiti

- **Negozio di destinazione:** PrestaShop 1.7+ (dove è installato PrestaShift)
- **Negozio di origine:** PrestaShop 1.7+ (dove è installato PSConnector)
- PHP 7.4+ con l'estensione cURL
- MySQL 5.7+ / MariaDB 10.3+

## Installazione

### Sul negozio di DESTINAZIONE (nuovo negozio):
1. Carica la cartella `prestashift/` in `/modules/`
2. Installa tramite back-office → Moduli → « Migrazione PrestaShift »

### Sul negozio di ORIGINE (negozio vecchio):
1. Carica la cartella `psconnector/` in `/modules/`
2. Installa tramite back-office → Moduli → « PrestaShift Connector »
3. Copia il token sicuro generato dalla pagina di configurazione del modulo

### Avviare la migrazione:
1. Apri PrestaShift sul negozio di destinazione
2. Inserisci l'URL del negozio di origine + il token
3. Seleziona il perimetro dei dati
4. Configura le opzioni (dimensione dei lotti, pulizia destinazione, ecc.)
5. Avvia la migrazione

## Architettura

```
Negozio di origine (PS 1.7/8)          Negozio di destinazione (PS 8/9)
┌─────────────────┐            ┌──────────────────┐
│   PSConnector    │◄──HTTP──► │   PrestaShift     │
│   (gateway       │   Bridge  │   (motore di      │
│    API in        │           │    migrazione)     │
│    lettura)      │           │                   │
└─────────────────┘            └──────────────────┘
```

PSConnector espone un punto di accesso API sicuro e in sola lettura. PrestaShift vi si connette via HTTP, recupera i dati a lotti, li trasforma per la compatibilità tra versioni, quindi li importa nel database di destinazione.

Alternativa: connessione diretta al database (PDO) per le migrazioni sullo stesso server — più veloce, senza gateway.

## Sicurezza

- Autenticazione tramite token (esadecimale di 64 caratteri)
- Il connettore è **in sola lettura** — le operazioni di scrittura sono bloccate
- L'accesso ai file è limitato alle directory `img/`, `download/`, `upload/`
- Protezione contro il path traversal con validazione `realpath()`
- Confronto del token a tempo costante (`hash_equals`)

## Rapporti di errore (opt-in)

Il modulo può inviare via e-mail un rapporto di errore all'autore, per aiutare a diagnosticare i problemi di migrazione.
**È disabilitato per impostazione predefinita** — lo attivi con l'interruttore *Invia rapporti di errore allo sviluppatore*
nella fase Opzioni.

Quando è attivato, una migrazione fallita invia: il messaggio di errore e il payload di debug, le tue versioni di PrestaShop e
PHP, `memory_limit` / `max_execution_time`, l'URL del negozio di origine, il passo a cui la migrazione si è fermata e le impostazioni utilizzate (dimensioni dei lotti, timeout, perimetro), ciò che l'ultima chiamata al
negozio di origine ha fatto, le ultime righe del log di migrazione, nonché l'indirizzo e-mail del dipendente connesso. Nulla viene inviato finché l'interruttore è disattivato, e nessun dato di clienti, ordini o catalogo è
mai trasmesso. Vedi [TelemetryService.php](prestashift/src/Service/TelemetryService.php) — è
l'unico percorso di codice che invia qualcosa all'autore. L'altra connessione in uscita del modulo
(`ConnectorClient`) comunica solo con l'URL del negozio di origine che configuri tu stesso.

## Supporto

- [Segnalare un problema](https://github.com/GajewskiMarcin/prestashift/issues)
- [Discussioni](https://github.com/GajewskiMarcin/prestashift/discussions)
- [Offrimi un caffè](https://buymeacoffee.com/marcingajewski)

## Autore

Creato da [marcingajewski.pl](https://marcingajewski.pl)

## Licenza

[Licenza libera accademica 3.0 (AFL-3.0)](LICENSE) — la licenza standard dei moduli PrestaShop.

### Avviso di attribuzione

Quanto segue è un **Avviso di attribuzione** ai sensi della sezione 6 dell'AFL-3.0. La sezione 6 richiede
che qualsiasi opera derivata lo conservi:

> PrestaShift è stato creato da Marcin Gajewski — https://marcingajewski.pl
> Progetto originale: https://github.com/GajewskiMarcin/prestashift
> Sostenere il lavoro: https://buymeacoffee.com/marcingajewski
>
> Le opere derivate e i fork devono conservare questo avviso, il credito all'autore e il link di sostegno mostrati
> nella schermata finale del modulo. « PrestaShift » è il nome del modulo originale: se pubblichi una
> versione modificata, ti preghiamo di darle il tuo nome.

I fork sono benvenuti — le correzioni inviate tramite pull request avvantaggiano tutti gli utenti del modulo.

Contributions go through pull requests to `main`.

### Ringraziamenti

PrestaShift migliora con queste persone:

| Chi | Contributo |
|---|---|
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |

La stessa lista è nel modulo: **Parametri → Informazioni → Grazie**.
