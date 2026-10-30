<p align="center">
  <a href="AUDYT.md">English</a> ·
  <a href="translations/AUDYT.fr.md">Français</a> ·
  <a href="translations/AUDYT.de.md">Deutsch</a> ·
  <a href="translations/AUDYT.es.md">Español</a> ·
  <a href="translations/AUDYT.pt.md">Português</a> ·
  <a href="translations/AUDYT.it.md">Italiano</a> ·
</p>

# Audyt PrestaShift

Każda pozycja ma tę samą budowę: **co mówi dokumentacja PrestaShop** (z adresem
źródła), **co robi moduł** (plik i linia), **werdykt** i **działanie**. Pozycje
bez podstawy w dokumentacji albo w kodzie rdzenia są oznaczone jako hipotezy i
nie są naprawiane, dopóki nie zostaną potwierdzone.

Źródła: dokumentacja techniczna PrestaShop (`github.com/prestashop/docs`,
gałąź 9.x) pobierana przez context7, oraz kod rdzenia w kontenerach testowych
(1.7.8.9, 8.2.4, 9.0.3, 9.1.0).

Stan: audyt w toku, rozpoczęty 2026-10-21.

---

## A1. Stan magazynowy zapisywany przez API rdzenia

**Dokumentacja.** Hook `actionUpdateQuantity`
(`modules/concepts/hooks/list-of-hooks/actionUpdateQuantity.md`): typ `action`,
lokalizacja `front office`, opis: *„Quantity is updated only when a customer
effectively places their order"*. FAQ o stanach (`faq/stock.md`): *„When you edit
the virtual quantity of a Product you are going to edit the
`StockAvailable::quantity` field directly in your database. When you modify your
stock through a `StockMovement` you apply a delta to your existing stock."*

**Co robił moduł.** `ProductMigrationStep::importStock()` i
`ProductAttributeMigrationStep::importStock()` wołały
`StockAvailable::setQuantity()` oraz `setProductOutOfStock()` dla każdego
rekordu. To wywołanie, z domyślnymi argumentami, zapisuje ruch magazynowy
(`StockManager::saveMovement()`) i odpala `actionUpdateQuantity`.

**Werdykt.** Trzy osobne wady:

1. Ruch magazynowy na każdy przeniesiony produkt i kombinację. Potwierdzone
   pomiarem: w sklepie docelowym 4439 produktów i 4439 ruchów z datą migracji,
   przy zerze ruchów w sklepie źródłowym. Import ustawia stan absolutny, więc
   zgodnie z FAQ właściwy jest zapis pola, nie delta.
2. Hook `actionUpdateQuantity` uruchamiany raz na produkt, czyli kod obcych
   modułów wykonuje się w trakcie importu, wbrew opisowi hooka („klient złożył
   zamówienie"). Na sklepie testowym wywalił się na tym fatalnym błędem moduł
   feedforge, co zabiłoby migrację u klienta.
3. Koszt: 147 ms na produkt (zmierzone na PS 9.2, 100 produktów), wobec 0,22 ms
   przy jednym zapisie wsadowym na partię. To była większość czasu partii.

**Działanie.** Nowy `src/Service/StockWriter.php` zapisuje `ps_stock_available`
jednym `INSERT ... ON DUPLICATE KEY UPDATE` na partię, z zestawem pól, jaki
podaje dokumentacja webservice dla tej encji (`id_product`,
`id_product_attribute`, `id_shop`, `id_shop_group`, `quantity`,
`depends_on_stock`, `out_of_stock`), bez ruchów i bez hooków. Wpięte w oba kroki.
Do weryfikacji: `verify_stock.php` porównuje stan każdego produktu i kombinacji
ze źródłem przez mapę ID.

---

## A2. Produkty oznaczane jako zaindeksowane, choć indeksu nie ma

**Dokumentacja.** Przewodnik migracji (`basics/keeping-up-to-date/migration.md`):
*„Imported products are not immediately available for search to your customers.
You must refresh the search index to make them available. Go to Shop parameters >
Search > Indexing and click on »Add missing product to the index«."* Opis crona
indeksacji (`development/products-lifecycle/search-index.md`): parametr `full=1`
przebudowuje cały indeks, `full=0` lub pominięty — *„it will index only missing
products"*. Komenda `prestashop:search:index --full` jest opisana jako
czasochłonna na dużych katalogach.

**Co robił moduł.** `ProductMigrationStep` wpisywał `'indexed' => 1` do
`ps_product` i `ps_product_shop`, a kontroler w zadaniach końcowych wykonywał
`UPDATE ps_product SET indexed = 1` bezwarunkowo. Jednocześnie wbudowana
przebudowa indeksu jest pomijana dla katalogów powyżej 2000 produktów.

**Werdykt.** Przy katalogu powyżej progu nic nie było indeksowane, a wszystkie
produkty miały `indexed = 1`. Zalecana przez dokumentację droga („Add missing
products to the index") nie widziała wtedy żadnego brakującego produktu:
użytkownik klikał, dostawał potwierdzenie i wyszukiwarka nadal nie zwracała nic.
Potwierdzone pomiarem: 3471 produktów, 3475 z `indexed = 1`, a w
`ps_search_index` 681 wierszy, czyli wyłącznie te z pierwotnego sklepu.

**Działanie.** Migrowane produkty dostają `indexed = 0`, a bezwarunkowy `UPDATE`
w zadaniach końcowych usunięty: flagę ustawia `Search::indexation()` dla tych
produktów, które faktycznie zaindeksuje.

**Do decyzji.** Komunikat pokazywany przy pominięciu indeksacji mówi dziś
„rebuild it in Shop Parameters > Search". Po tej poprawce szybsza i zgodna z
dokumentacją jest opcja „Add missing products to the index" i tak powinien
brzmieć komunikat, ale to zmiana tekstu widocznego dla użytkownika plus sześć
plików tłumaczeń, więc czeka na zgodę.

---

## A3. Zamówienia: sklep i status — hipoteza odrzucona

**Podejrzenie.** `OrderMigrationStep::importOrder()` wpisuje do tablicy
`'id_shop' => $data['id_shop']` i `'current_state' => $data['current_state']`,
czyli wartości ze starego sklepu. Gdyby tak zostało, zamówienia wskazywałyby
nieistniejący sklep i nieistniejący status.

**Weryfikacja.** Wiersz przechodzi przez `IdMapper::row('orders', ...)`, a mapa
kolumn (`IdMapper.php:200`) tłumaczy `id_shop` przez słownik `shop`
(`SchemaHelper::getTargetShopId()`), `id_shop_group` przez `shop_group`, a
`current_state` przez `order_state`, czyli przez mapę statusów ustawianą w
kreatorze. Słownik `shop` jest zdefiniowany w `IdMapper.php:1078`.

**Werdykt.** Działa poprawnie, nie ma tu błędu. Pozycja zostaje w audycie jako
zapis, że to sprawdzono.

**Do sprawdzenia na skali.** Czy po migracji `orders.current_state` zgadza się z
ostatnim wpisem w `ps_order_history` — wymaga przebiegu z zakresem zamówień,
wchodzi do pomiaru skali.

---

## A4. Zdjęcia: formaty i generowanie miniatur

**Podejrzenie 1.** Moduł pobiera ze starego sklepu wyłącznie `img/p/<ścieżka>/<id>.jpg`,
więc sklep ustawiony na PNG straciłby zdjęcia.

**Weryfikacja.** `ImageManager.php:154` w rdzeniu: *„Desired file type **inside**
the image. If jpg and $forceType is false, format inside will be decided by
PS_IMAGE_QUALITY"*. Nazwa pliku na dysku pozostaje `<id>.jpg` niezależnie od
ustawienia; potwierdzone też zawartością katalogu `img/p` w sklepie źródłowym
(408 plików, wszystkie `.jpg`). **Hipoteza odrzucona.**

**Podejrzenie 2.** PS 9 ma osobne ustawienie `PS_IMAGE_FORMAT`
(`src/Core/Image/ImageFormatConfiguration.php`) dla webp i avif, a moduł generuje
tylko miniatury `.jpg`, więc sklep podający webp pokazywałby puste miejsca.

**Weryfikacja.** `src/Adapter/Image/ImageRetriever::checkOrGenerateImageType()`
sprawdza `file_exists()` i generuje brakującą miniaturę w locie, w żądanym
formacie. To samo zachowanie jest w 1.7.8 (`ImageRetriever.php:182`) i 8.2
(`ImageRetriever.php:324`). **Hipoteza odrzucona** dla wszystkich wersji
docelowych.

**Wniosek odwrotny do oczekiwanego.** Skoro rdzeń dogenerowuje miniatury sam,
nasze generowanie wszystkich typów przy każdym zdjęciu w trakcie migracji jest w
dużej mierze zbędną pracą. Przy 100 tys. zdjęć i pięciu typach to pół miliona
operacji skalowania w czasie, gdy użytkownik czeka. PrestaShop 9.1 ma na to
własne narzędzie: `php bin/console prestashop:thumbnails:regenerate products`
(dokumentacja `development/components/console/prestashop-thumbnails-regenerate.md`),
a panel ma przycisk regeneracji.

**Działanie (zgoda 2026-10-21).** Generowanie miniatur usunięte, moduł zapisuje
sam oryginał. Podstawa jest potrójna:

1. Rdzeń generuje brakującą miniaturę przy pierwszym użyciu, w front office
   (`ImageRetriever::checkOrGenerateImageType()`, ten sam kod w 1.7.8, 8.2
   i 9.1) i w panelu (`ImageManager::thumbnail()`, który skaluje z oryginału do
   `img/tmp`, sprawdzając wcześniej `file_exists()` oryginału).
2. Komentarz w naszym kodzie twierdził, że miniatury są potrzebne, żeby panel
   pokazał zdjęcie. To nieprawda, panel liczy je z oryginału.
3. Moduł odniesienia robi to samo: `EDImport::copyImg()` wykonuje
   `copy($tmpfile, $path . '.jpg')` i nie generuje żadnych rozmiarów.

Koszt, który odpada: pięć operacji skalowania na zdjęcie, czyli pół miliona przy
katalogu ze 100 tys. zdjęć. Kto chce mieć wszystko gotowe od razu, ma „Regenerate
thumbnails" w panelu albo, od 9.1, `php bin/console prestashop:thumbnails:regenerate
products`.

---

## A5. Hasła klientów w starym formacie nie zadziałają w nowym sklepie

**Dokumentacja i kod rdzenia.** Zmiany w 8.0
(`modules/core-updates/8.0.md`): *„`Customer::$passwd` to be
`Validate::isHashedPassword()`"*. Zmiany w 8.1: *„Introduced
`PrestaShop\PrestaShop\Core\Security\Hashing` class to hash a password from a
plain text and a salt with md5"*. Implementacja sprawdzania
(`src/Core/Crypto/Hashing.php`): `checkHash($passwd, $hash, $staticSalt =
_COOKIE_KEY_)` przechodzi po dwóch metodach — `bcrypt` z
`password_verify($passwd, $hash)` i `md5` z `md5($staticSalt . $passwd) ===
$hash`.

**Werdykt.** Hasła bcrypt przenoszą się bez problemu, bo weryfikacja nie używa
niczego, co należy do sklepu. Hasła w starym formacie md5 są solone kluczem
`_COOKIE_KEY_` **sklepu sprawdzającego**, więc po migracji nie przejdą: nowy
sklep ma inny klucz. Klucz leży w `app/config/parameters.php`, nie w bazie, więc
konektor czytający bazę nie ma do niego dostępu i żadna migracja go nie
przeniesie. Dotyczy kont założonych na 1.5 i 1.6, których właściciele od tamtej
pory się nie logowali — w starym sklepie może ich być bardzo dużo. Moduł kopiował
hasło i nie mówił o tym ani słowa.

**Działanie (A5).** `CustomerMigrationStep::reportOldPasswords()` rozpoznaje hasła w
formacie md5 (32 znaki szesnastkowe) i wypisuje w dzienniku migracji ostrzeżenie
z liczbą takich kont w partii, razem z informacją, że ci klienci muszą użyć
„nie pamiętam hasła", a ich konta, zamówienia i adresy są przeniesione
normalnie. Nie da się tego naprawić inaczej bez przeniesienia klucza sklepu, co
rozwaliłoby istniejące konta w nowym sklepie.

---

## A6. Konektor przyjmował więcej niż „tylko czytanie"

**Co robił.** `psconnector/api.php`, akcja `query`, sprawdzała zapytanie czarną
listą pierwszych słów (`INSERT`, `UPDATE`, `DELETE`, `DROP`, …) i odrzucała je
tylko wtedy, gdy zapytanie **zaczynało się** od takiego słowa.

**Werdykt.** Dziura, i nie drobna. Przez to sito przechodziło wszystko, co
zaczyna się od `SELECT`:

- `SELECT ... INTO OUTFILE '/sciezka/plik.php'` — przy uprawnieniu MySQL `FILE`,
  które wiele hostingów współdzielonych nadaje, zapisuje dowolny plik tam, gdzie
  użytkownik bazy ma prawo pisać, czyli także plik PHP w katalogu sklepu. To jest
  zdalne wykonanie kodu na starym sklepie.
- `SELECT LOAD_FILE('/etc/passwd')` — odczyt dowolnego pliku widocznego dla MySQL.
- `SELECT SLEEP(30)`, `BENCHMARK(...)` — zajęcie procesu PHP na dowolnie długo.
- Komentarz przed zapytaniem (`/* */` albo `--`) ukrywał prawdziwe pierwsze słowo.

Czyli wyciek tokenu konektora był wart znacznie więcej niż dostęp do czytania
danych.

**Działanie.** Czarna lista zamieniona na białą, bo moduł wysyła do źródła
wyłącznie `SELECT` (sprawdzone: `SHOW COLUMNS` w `SchemaHelper` wykonuje się na
sklepie docelowym, nie przez konektor). Teraz: komentarze są obierane przed
sprawdzeniem, zapytanie musi zaczynać się od `SELECT`, dopuszczona jest jedna
instrukcja na żądanie, a `INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE`,
`BENCHMARK`, `SLEEP`, `GET_LOCK` i `RELEASE_LOCK` są odrzucane.

**Weryfikacja.** `test_connector_guard.php` wysyła 11 zapytań do prawdziwego
konektora: trzy zwykłe odczyty (w tym z JOIN i z podzapytaniem) przechodzą, a
zapis wprost, zapis ukryty za komentarzem blokowym i liniowym, dwie instrukcje w
jednym żądaniu, zapis pliku, odczyt pliku, `SLEEP` i `BENCHMARK` są odrzucane.
Wszystkie 11 zgodnie z oczekiwaniem.

**Skutek dla użytkowników.** Konektor podbity do 1.3.0. Zabezpieczenie działa po
stronie starego sklepu, więc chroni tych, którzy wgrają nową wersję wtyczki na
sklep źródłowy; moduł działa z oboma wersjami.

---

## A7. Zadania wykonywane raz, w jednym żądaniu

**Podejrzenie.** Moduł dzieli pracę na partie, ale cztery operacje wykonuje raz,
w jednym żądaniu HTTP: przebudowę drzewa kategorii, przebudowę indeksu
wyszukiwania, reguły cenowe katalogu i zapytania naprawcze `integrity`. Przy
dużym katalogu każda z nich może przekroczyć limit czasu żądania (u zgłaszającego
30 s), a wtedy migracja kończy się błędem na ostatnim kroku.

**Pomiar.** Sklep z 50 123 produktami i 3122 kategoriami (osobna baza, żeby nie
zakłócać innych testów):

| operacja | czas |
|---|---|
| `Category::regenerateEntireNtree()` | 0,13 s |
| `SpecificPriceRule::applyAllRules()` z jedną regułą | 1,48 s |
| zapytania naprawcze `integrity` (3 reprezentatywne) | poniżej sekundy |
| `Search::indexation(true)` | powyżej 10 minut |

**Werdykt.** Trzy pierwsze nie są problemem nawet przy 50 tys. produktów.
Jedyna kosztowna operacja to pełna przebudowa indeksu wyszukiwania — i tę moduł
pomija powyżej 2000 produktów, zostawiając ją narzędziom sklepu, co po poprawce
z A2 (produkty mają `indexed = 0`) działa dokładnie tak, jak zaleca dokumentacja
migracji PrestaShop. Czyli obszar zamknięty bez zmian w kodzie.

**Metoda.** Ten wniosek kosztował trzy sekundy pomiaru. Wcześniej planowałem
dwugodzinny przebieg na 50 tys. produktów, który dałby te same dwie liczby.
Zasada na dalszą część audytu: mierzyć koszt jednej partii i jednej operacji, a
nie przepuszczać miliony wierszy; żaden test dłuższy niż dziesięć minut bez
wyraźnej potrzeby.

---

## A8. Przyjazne adresy: ustawienie przeniesione, reguły przepisywania nie

**Kod rdzenia.** `Meta::update()` i `Meta::delete()` kończą się wywołaniem
`Tools::generateHtaccess()` (`classes/Meta.php:206` i `:222`), a ta metoda czyta
`PS_REWRITING_SETTINGS` (`classes/Tools.php:2246`), żeby zdecydować, czy wpisać
do `.htaccess` reguły przepisywania adresów. Innymi słowy w PrestaShop włączenie
przyjaznych adresów to dwie rzeczy: wiersz w konfiguracji **i** plik.

**Co robił moduł.** `ConfigurationMigrationStep` przenosi `PS_REWRITING_SETTINGS`
przez `Configuration::updateValue()`, a `MetaMigrationStep` zapisuje wiersze meta
zapytaniami SQL. Żadna z tych dróg nie przechodzi przez `Meta::update()`, więc
`.htaccess` nigdy nie był odświeżany.

**Werdykt.** Sklep, którego stara wersja miała przyjazne adresy włączone, a nowa
wyłączone, po migracji ma ustawienie włączone i brak reguł w pliku. Skutek:
404 na każdej karcie produktu i każdej kategorii, czyli sklep wygląda na zepsuty
przez migrację, choć dane są w porządku. Odwrotny kierunek (nowy sklep miał je
już włączone) działa przypadkiem, bo plik już zawiera reguły.

**Działanie.** Nowe zadanie końcowe: jeśli zakres obejmował konfigurację i po
migracji `PS_REWRITING_SETTINGS` jest włączone, moduł wywołuje
`Tools::generateHtaccess()` — dokładnie to, co robi sam sklep przy zmianie tego
ustawienia — i pokazuje to w liście zadań końcowych. Gdy wywołanie się nie uda,
komunikat mówi, co zrobić ręcznie (wyłączyć i włączyć przyjazne adresy w
Parametry sklepu > Ruch i SEO).

**Do dokończenia.** Dwa nowe komunikaty są po angielsku i czekają na
tłumaczenia w sześciu plikach.

---

## A9. Każda instrukcja zatwierdzana osobno

**Pomiar.** 300 jednakowych wstawek (wiersz ~1 KB, jak opis produktu) na sklepie
testowym, trzy drogi:

| sposób | czas | na wiersz |
|---|---|---|
| instrukcja po instrukcji, każda zatwierdzana osobno | 8,405 s | 28,02 ms |
| te same 300 w jednej transakcji | 0,380 s | 1,27 ms |
| jedna instrukcja wielowierszowa | 0,029 s | 0,10 ms |

**Werdykt.** Dominującym kosztem partii nie były ani zapytania do starego
sklepu, ani sieć, a to, że baza zatwierdzała każdą wstawkę osobno na dysk. Moduł
wykonywał kilkaset instrukcji na partię, więc płacił ten koszt kilkaset razy.

**Działanie.** `MigrationManager::processBatch()` otwiera transakcję przed
wykonaniem zadania i zatwierdza ją po nim; `IdMapper::finishBatch()` z jego
`ALTER TABLE` wykonuje się dopiero po zatwierdzeniu, bo DDL zamknąłby
transakcję niejawnie. Kontroler w obsłudze błędu wycofuje transakcję.

Sprawdzone przed wpięciem: żaden krok migracji nie wykonuje DDL-a,
`SpecificPriceRule::applyAllRules()` w rdzeniu też nie, a `CleanupService`
(`TRUNCATE`, `ALTER`) działa poza partiami, przy starcie sesji.

**Skutek dodatkowy, poprawnościowy.** Partia jest teraz wszystko-albo-nic. Do tej
pory partia przerwana w połowie zostawiała część rekordów zapisanych, a
wznowienie dopisywało do niepełnego stanu.

---

## A10. Powiązania tagów zapisywane wiersz po wierszu

**Pomiar z pełnego przebiegu.** Partia 50 tagów: 5-6 s, z jednym skokiem do
**28,33 s** przy limicie żądania 30 s. Dla porównania krok cech produktów, który
przenosi tyle samo powiązań, robił partię w 0,30 s.

**Różnica w kodzie.** `FeatureProductMigrationStep` woła `IdMapper::prepare()`
hurtowo dla wszystkich odwołań i wykonuje **jedną** instrukcję `INSERT IGNORE` na
partię. `TagMigrationStep` pytał mapę ID o każdy produkt osobno i wykonywał
osobną wstawkę na każde powiązanie, czyli około 500 instrukcji na partię.

**Działanie.** Krok tagów przerobiony na wzór kroku cech: jedno hurtowe
`prepare('product')` i jedna instrukcja na partię.

---

## Wynik pełnego przebiegu przez panel (2026-10-21)

Sklep źródłowy 1.7.8.9: 2020 produktów, 3009 kategorii, 6039 kombinacji, 4023
zdjęć, 2002 ceny, po 6 tys. powiązań cech i tagów. Cel: PrestaShop 9.1.0, PHP
8.5, limity jak u zgłaszających (30 s na żądanie, 128 MB). Droga: logowanie do
panelu, token kontrolera, `start_migration` i `run_batch` partia po partii, na
koniec `post_migration` — dokładnie to, co robi JavaScript kreatora.

| | przed zmianami | po zmianach |
|---|---|---|
| cały przebieg, 809 partii | 2746 s (46 min) | **657 s (11 min)** |
| najdłuższe żądanie HTTP | 28,33 s | **4,60 s** |
| błędy | 0 | 0 |
| zadania końcowe | 5,2 s | 3,2 s |
| `integrity` | 1,6 s | 1,5 s |
| ruchy magazynowe dopisane przez migrację | 4439 | **0** |
| produkty z `indexed = 1` bez indeksu | 2020 | **0** |

Zgodność danych ze źródłem, sprawdzana pole po polu przez mapę ID na próbce
z każdej encji (kategorie, produkty, kombinacje, zdjęcia, producenci, dostawcy,
wartości cech, atrybuty, klienci, adresy, zamówienia, kupony, CMS, sklepy,
przewoźnicy, ceny, grupy podatkowe): **zero rozbieżności, przed i po zmianach.**

Uwaga do tego zdania: w pierwszej wersji narzędzia kolumny tłumaczone nie były
sprawdzane wcale, mimo że raport mówił „zgodne" — patrz A13. Wynik powyżej
pochodzi z poprawionego narzędzia, po sprawdzeniu samego narzędzia.

Jedno zadanie zwolniło: cechy produktów, 41,4 → 62,4 s (0,31 → 0,51 s na partię).
Ten krok już wcześniej wykonywał jedną wstawkę wsadową, więc transakcja dokłada
mu tylko narzut.

---

## A11. Co się dzieje, gdy partia pada w połowie

**Test awarii i wznowienia (przez panel, PrestaShop 9.0).** Migracja startuje
normalnie, dwie partie przechodzą, a trzecia zostaje wysłana z tokenem, którego
stary sklep nie uzna — czyli dokładnie to, co zgłaszali użytkownicy, tylko
wywołane celowo.

Wynik: partia odrzucona, komunikat `Connector Error: 403 Forbidden — the
connector rejected the token (Unauthorized access.). Copy the token again from
the Connector module on the source shop.`, `next_batch` ustawione na false (więc
kreator przestaje pytać o kolejne partie), a **ta sama partia wysłana ponownie z
poprawnym tokenem przechodzi i migracja idzie dalej** (zadanie `addresses`,
offset 0 → 50). To jest droga, którą po poprawce wykonuje przycisk „Resume".

**Test atomowości partii.** Awaria w połowie zapisu wywołana przez ukrycie w
sklepie docelowym tabeli `ps_customer_group`, do której krok klientów pisze jako
drugiej. Ten sam przebieg, dwa warianty kodu:

| ta sama awaria w połowie partii | klientów zostawionych w celu |
|---|---|
| bez transakcji (jak moduł działał do 1.6.0) | **3** |
| z transakcją na partię (1.7.0) | **0** |

Czyli partia naprawdę jest wszystko-albo-nic, a nie tylko w teorii. Wcześniej
wznowienie dopisywało do stanu, w którym część rekordów tej partii już była.

---

## A12. Powtarzalność: drugi przebieg bez czyszczenia

**Test.** Po pełnej migracji ten sam pełny przebieg puszczony ponownie, z
odznaczonym „Clean Target Data" (809 partii, 697 s, zero błędów). Następnie
porównanie liczby wierszy w siedemnastu tabelach ze sklepem źródłowym.

**Wynik.** Żadnych duplikatów. Trzy tabele różnią się od źródła i każda różnica
jest zamierzona:

| tabela | źródło | cel | dlaczego |
|---|---|---|---|
| `category_product` | 6053 | 6054 | produkt 20 w źródle ma kategorię domyślną 8, ale nie jest z nią powiązany; moduł dopisuje brakujące powiązanie, bo w PrestaShop kategoria domyślna musi być jedną z kategorii produktu |
| `product_supplier` | 2072 | 2070 | w źródle są dwa wiersze wskazujące dostawcę `0` i nieistniejącego `99`; `integrity` je usuwa (poprawka błędu „Invalid Supplier id: 0") |
| `specific_price` | 2002 | 2003 | jeden wiersz z `id_specific_price_rule = 1`, wygenerowany przez przeniesioną regułę cenową katalogu, tak jak robi to sam PrestaShop |

Pozostałe czternaście tabel (produkty, opisy, kategorie, kombinacje, powiązania
atrybutów, zdjęcia, opisy zdjęć, stany, cechy, tagi, pola personalizacji,
klienci, adresy, zamówienia) zgadzają się co do wiersza.

**Metoda ustalania różnic.** Nie na oko z sumy: `diff_links.php` porównuje liczbę
wierszy powiązań dla każdego rodzica osobno, przez mapę ID, i wskazuje konkretny
rekord. Pierwsza próba wyjaśnienia (heurystyka „produkty z więcej niż trzema
powiązaniami") wskazała zły trop i została odrzucona.

---

## A13. Weryfikator, który nie weryfikował (błąd w narzędziu, nie w module)

**Co się stało.** `verify_all.php` porównywał jedną kolumnę tłumaczoną na encję
zapytaniem `SELECT name ... ORDER BY id_lang LIMIT 1`. PrestaShop w
`Db::getRow()` dopisuje `LIMIT 1` **bezwarunkowo** (`classes/db/Db.php:645` w
1.7.8, `:650` w 8.2), więc powstawało `LIMIT 1 LIMIT 1`:

- na 8.2 i 9.1 zapytanie rzucało wyjątek, który łapał `try/catch` w skrypcie,
  więc porównanie nazw **nie wykonywało się wcale**, a raport pokazywał „zgodne";
- na 1.7.8 `getValue()` zwracał `false` bez wyjątku, więc narzędzie porównywało
  pustkę z nazwą i zgłosiło **248 rozbieżności, których nie było**.

**Weryfikacja, że dane są dobre.** Zapytanie wprost do bazy celu 1.7.8:
kategoria 20960 ma nazwę „Categoria de teste 960", a wszystkie 3009 wierszy
`ps_category_lang` mają niepuste nazwy. Czyli moduł przeniósł tłumaczenia
poprawnie na wszystkich trzech wersjach.

**Działanie.** Z zapytania usunięty własny `LIMIT`, a `catch` przestał zjadać
błędy inne niż brak tabeli — milczące pomijanie sprawdzenia było tu groźniejsze
niż fałszywy alarm.

**Sprawdzenie samego narzędzia.** Po poprawce celowo zepsute dwa wiersze w celu
(nazwa kategorii 20500 i cena produktu 100500). Narzędzie wskazało dokładnie te
dwa i nic więcej. Potem wiersze przywrócone i puszczona weryfikacja **wszystkich
rekordów, nie próbki**: 3009 kategorii, 2020 produktów, 610 wartości cech, 225
atrybutów, 2002 ceny, 3100 kombinacji i zdjęć — zero rozbieżności.

**Wniosek metodyczny.** Narzędzie, które raportuje „zgodne", trzeba najpierw
zmusić do zgłoszenia błędu, którego się spodziewamy. Inaczej mierzy się własne
milczenie.

---

## Macierz wersji docelowych (2026-10-21)

Ten sam pełny przebieg przez panel, to samo źródło (1.7.8.9, 2020 produktów),
trzy cele:

| cel | PHP | partii | czas | błędy | zgodność danych |
|---|---|---|---|---|---|
| PrestaShop 9.1.0 | 8.5 | 809 | 657 s | 0 | zero rozbieżności |
| PrestaShop 8.2.4 | 8.1 | 809 | 569 s | 0 | zero rozbieżności |
| PrestaShop 1.7.8.9 | 7.4 | 809 | 578 s | 0 | zero rozbieżności |

W każdym przypadku wykonały się też zadania końcowe, razem z nowym
odświeżeniem reguł przepisywania adresów. Czasy przebiegów 8.2 i 1.7.8 są
zaniżone względem 9.1 tylko dlatego, że biegły równolegle na jednym serwerze
bazy — nie są miarą wydajności wersji.

---

## Lista K z `ANALIZA-ID.md` — stan zamknięcia

Dwanaście błędów znalezionych przy analizie numerów ID (16.09.2026). Każdy
sprawdzony w obecnym kodzie:

| | błąd | stan | dowód |
|---|---|---|---|
| K1 | przy ponownym przebiegu produkty aktualizowały tylko cenę, datę i podatek | zamknięty | `upsert('product', $row, ['id_product'])` — z aktualizacji wyłączony tylko klucz główny, więc zmienia się każde pole |
| K2 | klienci: aktualizowany tylko e-mail, adresy: tylko data | zamknięty | `upsert('customer', $row, ['id_customer'])` |
| K3 | krok płatności i faktur szedł po zamówieniach **nowego** sklepu i niszczył je | zamknięty | krok czyta `{prefix}orders` ze źródła, a usuwa po `$tid` z mapy ID (`order_invoice`, `order_invoice_payment`, `order_carrier`) |
| K4 | warunki reguł cenowych (kategoria, marka) nie były przenoszone, więc reguła działała na cały sklep | zamknięty | `CatalogPriceRuleMigrationStep::loadConditions()` plus encje `specific_price_rule_condition_group` i `_condition` w mapie ID |
| K5 | przewoźnicy dostawali nowe numery, a zamówienia wskazywały stare; każdy przebieg dodawał przewoźników | zamknięty | `id_carrier` tłumaczone w mapie kolumn `orders`; narastanie przewoźników naprawione w 1.7.0 (A-fix, patrz [[A12]] i commit) |
| K6 | konfiguracja: język, waluta i kraj kopiowane jako numery; teksty zapisywane po numerze wiersza | zamknięty | `ConfigurationMigrationStep::$idKeys` tłumaczy pięć kluczy, a teksty zapisywane po nazwie klucza |
| K7 | lokalizacja zapisywana po numerze, nadpisywała waluty i języki nowego sklepu | zamknięty | dopasowanie po kodzie ISO w `IdMapper::matchExisting()` (kraj, waluta, język, stan) |
| K8 | czyszczenie katalogu kasowało marki, dostawców i załączniki mimo osobnych pól wyboru, a nie kasowało starych cen specjalnych | zamknięty | `CleanupService::$plan` ma osobne wpisy `manufacturers`, `suppliers`, `attachments`, a `catalog` kasuje `specific_price`, `specific_price_priority`, `stock_mvt`, `product_comment` |
| K9 | wiersze produkt–dostawca z dostawcą 0 kopiowane bez filtra (błąd zgłoszony z PS 8/9) | zamknięty | `IntegrityService` usuwa je; zmierzone w pełnym przebiegu: dokładnie dwa takie wiersze źródła (dostawca `0` i `99`) nie trafiły do celu |
| K10 | przełącznik „Wymuś zachowanie ID" nic nie robił | zamknięty | w kodzie nie ma już żadnego odwołania |
| K11 | siedem tabel w ogóle nieprzenoszonych (rabaty w zamówieniach, podatki pozycji i faktur, produkty powiązane, przewoźnicy produktów, ograniczenia kuponów, zwroty) | zamknięty | każda obsługiwana: `order_cart_rule`, `order_detail_tax`, `order_return` w kroku zamówień, `order_invoice_tax` w kroku płatności, `accessory` w kroku produktów, `product_carrier` w kroku przewoźników, `cart_rule_country` i pozostałe ograniczenia w kroku kuponów |
| K12 | mapa przekierowań 301 porównywała produkty po tym samym numerze w obu sklepach | zamknięty | `RedirectMapService` tłumaczy numer przez `IdMapper::find('product', …)` |

---

## A14. Regresja z 1.3.0: aktualizacja po migracji zrobionej starszą wersją

**Zgłoszenie.** Użytkowniczka napisała, że migrację z tego samego sklepu zrobiła
miesiąc wcześniej i przeszła dobrze, a teraz, chcąc dociągnąć nowe zamówienia,
klientów i produkty, dostaje błędy, choć niczego nie zmieniała.

**Co się dzieje.** Do wersji 1.3.0 migracja kopiowała numery źródła bez żadnej
mapy. Mapa ID wprowadzona w 1.3.0 nic o tych rekordach nie wie, a
`IdMapper::offset()` przy pustej mapie bierze `MAX(id)` z nowego sklepu jako
offset. Czyli kolejny przebieg nadaje każdemu rekordowi numer `źródło + MAX` i
**zapisuje drugą kopię całego katalogu zamiast go zaktualizować.**

**Potwierdzenie testem.** Sklep docelowy z 3009 kategoriami i 2020 produktami
zmigrowanymi 1:1, mapa ID wyczyszczona (dokładnie stan sklepu po migracji
sprzed 1.3.0), następnie przebieg przyrostowy bez czyszczenia:

| | przed poprawką | po poprawce |
|---|---|---|
| kategorie (było 3009) | 6016 | **3009** |
| produkty (było 2020) | 4039 | **2020** |
| kombinacje (było 6039) | 7088 | **6039** |
| najwyższy numer kategorii (w źródle 22999) | 26006 | **22999** |
| mapa ID | 6960 wpisów, 642 jako 1:1 | **21771 wpisów, wszystkie 1:1** |

Po przyjęciu numerów dane nadal zgadzają się ze źródłem (weryfikacja pól na
próbce z każdej encji: zero rozbieżności).

**Działanie.** `IdMapper::adoptLegacyIds()` przy pierwszym uruchomieniu dla
danego źródła, gdy mapa jest pusta i użytkownik nie zaznaczył czyszczenia,
sprawdza dowodowo, czy nowy sklep już zawiera rekordy tego źródła pod ich
własnymi numerami: pobiera 50 produktów (w razie potrzeby kategorii) i porównuje
`date_add`, a dla produktów też `reference`. Przy zgodności co najmniej 90%
zapisuje mapę jako 1:1 i zostawia offsety na zerze. Gdy dowody nie wychodzą, nie
robi nic — sklep z własnymi rekordami pod tymi numerami nie zostanie nadpisany.

**To jest moja regresja**, wprowadzona razem z mapą ID w 1.3.0. Timeouty, które
ta sama użytkowniczka zgłaszała wcześniej, to osobna sprawa i pochodzą z
wzorca czytania obecnego od 1.0.0 (A1, A9, A10).

---

## A15. Dwa błędy w moim własnym kodzie, znalezione przy naprawianiu A14

**1. `ConnectorResult::fetchAll($mode)` ignorował tryb.** Zawsze zwracał wiersze
jako tablice asocjacyjne, niezależnie od tego, o co pytał wywołujący. Moje
pierwsze podejście do adopcji użyło `PDO::FETCH_COLUMN`, dostało tablice,
rzutowanie `(int)` dało z nich `1`, i na encję trafiał jeden wiersz mapy zamiast
tysięcy. Poprawione: tryby `FETCH_COLUMN` i `FETCH_NUM` są obsługiwane, bo
cicha podmiana wyniku na inny niż zamówiony to pułapka dla każdego następnego
kawałka kodu.

**2. `adoptEntity()` raportował zamiar, nie skutek.** Zwracał liczbę
przygotowanych wierszy, nie sprawdzając, czy `INSERT` się wykonał — dlatego
dziennik twierdził „przyjęto 3009 kategorii", gdy w mapie było ich kilkadziesiąt.
Teraz sprawdza wynik zapytania, liczy `Affected_Rows()` i przy porażce zapisuje
błąd w dzienniku.

Oba błędy były moje i oba znalazły się tylko dlatego, że test porównywał liczby
wierszy w bazie, a nie treść komunikatu modułu.

---

## A16. Załączniki zapisywane jako strona błędu 403

**Jak wyszło.** Przebieg z włączonym przenoszeniem plików (4023 zdjęcia i 200
załączników). Zdjęcia przeszły poprawnie — sumy kontrolne plików w celu zgodne ze
źródłem. Załączniki nie: plik w celu miał **277 bajtów** zamiast 647, a w środku
leżała strona `<title>403 Forbidden</title>` od Apache.

**Przyczyna.** `AttachmentMigrationStep::downloadFile()` pobierał plik zwykłym
żądaniem HTTP z `http://sklep/download/<hash>`, a PrestaShop chroni katalog
`download/` własnym `.htaccess`. Odpowiedzią jest więc strona 403, którą moduł
zapisywał na dysk bez jakiegokolwiek sprawdzenia i bez słowa w dzienniku.
Konektor ma akcję plikową z dostępem do `download/` — czyli jedyna droga, która
działa, nie była używana.

**Działanie.**

1. Przy połączeniu przez konektor plik jest pobierany przez konektor.
2. Pobrana treść jest sprawdzana względem `file_size` z tabeli załącznika;
   cokolwiek innej długości nie jest zapisywane, a w dzienniku pojawia się
   ostrzeżenie mówiące, co zrobić.
3. Przy połączeniu bezpośrednim do bazy plik pozostaje nieosiągalny (katalog jest
   zablokowany), więc moduł mówi to wprost: skopiuj katalog `download/` ręcznie
   albo migruj przez konektor.
4. Plik już istniejący w celu, ale o złym rozmiarze, jest pobierany ponownie —
   dzięki temu sklep, który ma u siebie te 277-bajtowe śmieci po wcześniejszej
   migracji, naprawia się sam przy następnym przebiegu. Sprawdzone: pliki
   wróciły do 647 bajtów i zgadzają się sumami kontrolnymi ze źródłem.
5. Zdjęcia dostały analogiczne sprawdzenie: treść musi być obrazem
   (`getimagesizefromstring`), bo strona błędu zapisana jako `<id>.jpg` to
   zepsute zdjęcie i cisza w dzienniku.

---

## Macierz opcji (mały sklep, 2026-10-22)

Źródło: 1.7.8.9, 20 produktów, 9 kategorii, 39 kombinacji, 23 zdjęcia z
plikami, 20 załączników z plikami, 3 klienci, 7 adresów, 5 zamówień, 5 koszyków,
3 kupony, 6 przewoźników, 5 stron CMS, 40 stron meta, 2 kontakty, 5 sklepów
stacjonarnych, 2 pracownicy. Każdy przypadek to pełny przebieg przez panel.

| przypadek | co sprawdza | partii | czas | błędy |
|---|---|---|---|---|
| konektor, czyszczenie, pliki | podstawowa droga | 74 | 23,4 s | 0 |
| „pomiń pliki" | opcja skip_files | 74 | 28,5 s | 0 |
| połączenie bezpośrednie do bazy | tryb direct | 74 | 11,8 s | 0 |
| drugi przebieg bez czyszczenia | brak duplikatów | 74 | 25,8 s | 0 |
| tryb przyrostowy (delta) | filtr daty | 63 | 21,8 s | 0 |
| partia 1 rekordu | skrajna wartość | 77 | 26,8 s | 0 |
| partia 500 rekordów | skrajna wartość | 72 | 27,9 s | 0 |
| zakres: tylko klienci | pojedynczy zakres | 6 | 1,7 s | 0 |
| zakres: zamówienia | pojedynczy zakres | 15 | 5,3 s | 0 |
| zakres: CMS i meta | pojedynczy zakres | 4 | 1,2 s | 0 |
| zakres: przewoźnicy i strefy | pojedynczy zakres | 5 | 1,8 s | 0 |
| raportowanie błędów włączone | telemetria | 74 | 26,4 s | 0 |
| tryb debugowania wyłączony | bez dziennika | 74 | 27,5 s | 0 |
| opóźnienie między partiami | delay 100 ms | 74 | 40,8 s | 0 |

Sprawdzenia poza „zero błędów":

- **pliki**: sumy kontrolne 20 załączników i zdjęć w celu zgodne ze źródłem,
  zdjęcia w celu są prawidłowymi obrazami;
- **multistore** (cel z dwoma sklepami, PrestaShop 9.0): migracja do sklepu 1,
  potem do sklepu 2 bez czyszczenia. Produkty współdzielone (20 globalnie, nie
  40), `product_shop` po 20 wierszy na każdy sklep, `category_shop` po 9,
  `stock_available` dla sklepu 2 obecne, **sklep 1 nietknięty** — czyli
  zachowanie zgodne z multistore PrestaShop;
- **tryb bezpośredni** wykrył błąd `meta` (duplikat strony `cart`), który
  pochodził z mojej zbyt szerokiej adopcji numerów z A14 i został naprawiony.

---

## A17. Trzy dziury funkcjonalne, zamknięte 2026-10-22

Wcześniejsze testy pomijały trzy rzeczy, które nie są kwestią ilości danych, a
logiki. Każda sprawdzona na małym sklepie z kompletem encji.

### Źródło z więcej niż jednym językiem

Do źródła dodany drugi język (`pl`) wraz z wierszami we wszystkich 30 tabelach
tłumaczeń (653 wiersze), z nazwami oznaczonymi przedrostkiem, żeby było widać,
co gdzie trafia. Dwie ścieżki:

- **cel ma ten język**: do nowego sklepu trafiły oba (po 20 wierszy
  `product_lang` na język, nazwy „Hummingbird CHANGED" i „PL Hummingbird
  CHANGED"). Kategorie 9 i 7, bo kategoria główna i „Strona główna" są w mapie
  oznaczone jako **połączone** — należą do nowego sklepu i moduł ich nie
  nadpisuje, co jest zachowaniem zamierzonym;
- **cel nie ma tego języka**: przeniosły się wyłącznie wiersze angielskie, a w
  dzienniku stanęło ostrzeżenie: *„Language 'pl' of the source is not installed
  in the target — its translations are not migrated. Install it in
  International > Translations"*. Żadnego błędu, żadnych wierszy z nieistniejącym
  numerem języka.

### Mapowanie statusów zamówień i stref przewoźników

Dotąd te dwie opcje szły we wszystkich testach puste. Przebieg z mapą statusów
`1→5, 6→7, 8→2, 10→11` i stref `1→3, 2→5`:

| zamówienie | status w źródle | status w celu |
|---|---|---|
| 1 | 6 | **7** (Refunded) |
| 2 | 1 | **5** (Delivered) |
| 3 | 8 | **2** (Payment accepted) |
| 4 | 1 | **5** |
| 5 | 10 | **11** (Remote payment accepted) |

Historia zamówień również przemapowana (statusy 2, 5, 7, 11). Przewoźnicy
dostali strefy 3 „Asia" i 5 „Oceania", cennik dostaw także — zgodnie z mapą.

### Źródło multistore

Źródło przestawione na multistore, drugi sklep dostał **inne** dane dla tych
samych produktów (cena 999,99 i nazwy z przedrostkiem „SKLEP2"). Migracja wzięła
dane sklepu 1: cena 99,99, nazwa bez przedrostka, 20 wierszy `product_shop`
tylko dla sklepu docelowego. Czyli zachowanie jest poprawne i powtarzalne —
moduł czyta tabele per-sklep posortowane po `id_shop` i bierze pierwszy wiersz.

Czego brakowało: **powiedzenia o tym użytkownikowi.** Test połączenia pokazuje
teraz ostrzeżenie: *„The source is a multistore installation with 2 shops. Only
the data of shop »PrestaShop« (id 1) will be migrated — the other shops are
ignored."* Liczba sklepów źródła jest też zapisywana do raportów błędów.

**Do decyzji.** Wybór sklepu źródłowego w kreatorze byłby lepszy od komunikatu,
ale to nowe pole w interfejsie i nowe tłumaczenia, więc czeka na zgodę.

### Encje, które wcześniej migrowały się „na pusto"

Źródło miało po zero-jeden wierszy w opiniach, listach życzeń, korektach,
ruchach magazynowych i wątkach klientów, więc te kroki nic nie robiły.
Po dołożeniu danych wszystko przeniosło się co do wiersza: opinie 12, oceny 12,
listy życzeń 3 wraz z 9 pozycjami, korekty 3 z 3 pozycjami, ruchy magazynowe 10,
wątki 3 z 3 wiadomościami, załączniki 20.

### Skala po wszystkich poprawkach, z plikami

Przebieg na 2020 produktach, 4023 zdjęciach z prawdziwymi plikami i 200
załącznikach, przez panel, z limitem 30 s: **813 partii, 510 s, zero błędów,
najdłuższe żądanie 1,59 s.**

---

## A18. Tłumaczenia: brakujące frazy i ciche niedopasowanie kontekstów

**Zadanie.** Dorobić tłumaczenia do fraz dodanych w 1.6.0 i 1.7.0.

**Co się okazało po drodze.** Brakujące frazy były czterema, ale sprawdzanie „czy
tekst jest w pliku" nie wystarcza. PrestaShop szuka tłumaczenia pod kluczem
`md5(fraza)` poprzedzonym **kontekstem pliku, który o nie prosi**: nazwą
szablonu dla `.tpl`, nazwą klasy dla PHP. Tłumaczenie zapisane pod innym
kontekstem jest dla sklepu niewidoczne, a plik wygląda kompletnie.

Znalezione tym kryterium:

1. **Cztery frazy bez tłumaczenia** — dwa komunikaty o regułach przepisywania
   adresów, ostrzeżenie o źródle multistore i podpowiedź adresu starego sklepu
   (ta ostatnia z 1.4.4, nieprzetłumaczona w pięciu językach). Dopisane we
   wszystkich sześciu.
2. **Opis telemetrii z 1.5.0 nie tłumaczył się w pięciu językach.** Fraza
   pochodzi z `options.tpl`, więc sklep szuka jej pod kontekstem `options`, a we
   wspólnym `_contexts.php` wpisu nie było — klucz lądował pod kontekstem
   kontrolera. Polski plik miał własną mapę z poprawnym wpisem, więc po polsku
   działało, co ukrywało problem.
3. **Siedem fraz używanych z dwóch miejsc** (np. „Customers" w kontrolerze i w
   szablonie zakresu, „Migration fully completed!" w menedżerze migracji) miało w
   mapie tylko jeden kontekst, więc z drugiego miejsca sklep pokazywał
   angielski. Dotyczyło **wszystkich sześciu języków**, czyli 42 wystąpień.

**Działanie.** `_contexts.php` jest teraz generowany z kodu: każda fraza ma
wszystkie konteksty, z których jest używana (345 fraz). Polski plik przestał
trzymać własną kopię mapy i korzysta ze wspólnej — to ta podwójna mapa
spowodowała rozjechanie się jednego pliku z pozostałymi.

**Weryfikacja.** Dwa testy. Pierwszy liczy frazy, których sklep nie znajdzie,
budując klucze dokładnie tak jak PrestaShop: **0 w każdym z sześciu języków**
(wcześniej 42). Drugi pyta o tłumaczenia **żywy sklep** przez
`Translate::getModuleTranslation()` z językiem ustawionym na polski — wszystkie
sprawdzone frazy wracają po polsku, w tym jedna z kontekstu szablonu.
