<p align="center">
  <a href="../AUDYT.md">English</a> ·
  <a href="AUDYT.fr.md">Français</a> ·
  <a href="AUDYT.de.md">Deutsch</a> ·
  <a href="AUDYT.es.md">Español</a> ·
  <a href="AUDYT.pt.md">Português</a> ·
  <a href="AUDYT.it.md">Italiano</a> ·
</p>

# Audit PrestaShift

Chaque point suit la même structure : **ce qu'indique la documentation PrestaShop** (avec l'adresse de la source), **ce que fait le module** (fichier et ligne), **verdict** et **action**. Les points qui ne reposent ni sur la documentation ni sur le code du cœur sont signalés comme des hypothèses et ne sont pas corrigés tant qu'ils n'ont pas été confirmés.

Sources : documentation technique PrestaShop (`github.com/prestashop/docs`, branche 9.x), récupérée via context7, ainsi que le code du cœur dans les conteneurs de test (1.7.8.9, 8.2.4, 9.0.3, 9.1.0).

État : audit en cours, commencé le 2026-10-21.

---

## A1. Stock enregistré par l'API du cœur

**Documentation.** Hook `actionUpdateQuantity`
(`modules/concepts/hooks/list-of-hooks/actionUpdateQuantity.md`) : type `action`,
emplacement `front office`, description : « Quantity is updated only when a customer
effectively places their order ». FAQ sur les stocks (`faq/stock.md`) : « When you edit
the virtual quantity of a Product you are going to edit the
`StockAvailable::quantity` field directly in your database. When you modify your
stock through a `StockMovement` you apply a delta to your existing stock. »

**Ce que faisait le module.** `ProductMigrationStep::importStock()` et
`ProductAttributeMigrationStep::importStock()` appelaient
`StockAvailable::setQuantity()` et `setProductOutOfStock()` pour chaque
enregistrement. Cet appel, avec les arguments par défaut, enregistre un mouvement
de stock (`StockManager::saveMovement()`) et déclenche `actionUpdateQuantity`.

**Verdict.** Trois défauts distincts :

1. Un mouvement de stock pour chaque produit et déclinaison migrés. Confirmé
par mesure : dans la boutique de destination, 4 439 produits et 4 439 mouvements
datés de la migration, contre zéro mouvement dans la boutique source. L'import
définit un stock absolu ; conformément à la FAQ, il convient donc d'enregistrer
le champ directement, et non une variation.

2. Le hook `actionUpdateQuantity` était déclenché une fois par produit : le code
des modules tiers s'exécutait donc pendant l'import, contrairement à la description
du hook (« le client a passé une commande »). Dans la boutique de test, le module
feedforge a provoqué une erreur fatale à ce moment-là, ce qui aurait interrompu
la migration chez le client.

3. Coût : 147 ms par produit (mesuré sur PS 9.2, 100 produits), contre 0,22 ms
avec une seule écriture groupée par lot. Cela représentait la majeure partie
du temps d'exécution du lot.

**Action.** Le nouveau fichier `src/Service/StockWriter.php` écrit dans
`ps_stock_available` avec un seul `INSERT ... ON DUPLICATE KEY UPDATE` par lot,
en utilisant l'ensemble des champs indiqués par la documentation du webservice
pour cette entité (`id_product`,
`id_product_attribute`, `id_shop`, `id_shop_group`, `quantity`,
`depends_on_stock`, `out_of_stock`), sans mouvements ni hooks. Intégré dans
les deux étapes. Pour vérification : `verify_stock.php` compare le stock de
chaque produit et déclinaison avec celui de la source à l'aide de la table de
correspondance des ID.

---

## A2. Produits marqués comme indexés alors qu'ils ne le sont pas

**Documentation.** Guide de migration (`basics/keeping-up-to-date/migration.md`) :
« Imported products are not immediately available for search to your customers.
You must refresh the search index to make them available. Go to Shop parameters >
Search > Indexing and click on »Add missing product to the index« ».
Description de la tâche cron d'indexation (`development/products-lifecycle/search-index.md`) :
le paramètre `full=1` reconstruit l'intégralité de l'index, `full=0` ou absent —
« it will index only missing products ». La commande
`prestashop:search:index --full` est décrite comme longue à exécuter sur les
grands catalogues.

**Ce que faisait le module.** `ProductMigrationStep` écrivait
`'indexed' => 1` dans `ps_product` et `ps_product_shop`, et le contrôleur des
tâches finales exécutait `UPDATE ps_product SET indexed = 1` sans condition.
Dans le même temps, la reconstruction intégrée de l'index était ignorée pour les
catalogues de plus de 2 000 produits.

**Verdict.** Pour un catalogue dépassant le seuil, rien n'était indexé,
alors que tous les produits avaient `indexed = 1`. La méthode recommandée par la
documentation (« Add missing products to the index ») ne détectait alors aucun
produit manquant : l'utilisateur cliquait, recevait une confirmation, mais le
moteur de recherche ne renvoyait toujours aucun résultat. Confirmé par mesure :
3 471 produits, 3 475 avec `indexed = 1`, et 681 lignes dans
`ps_search_index`, correspondant uniquement aux produits de la boutique d'origine.

**Action.** Les produits migrés reçoivent `indexed = 0`, et le
`UPDATE` inconditionnel des tâches finales a été supprimé : `Search::indexation()`
définit le drapeau pour les produits qu'elle indexe réellement.

**À décider.** Le message affiché lorsque l'indexation est ignorée indique
actuellement « rebuild it in Shop Parameters > Search ». Après cette correction,
l'option plus rapide et conforme à la documentation est « Add missing products to
the index » ; le message devrait donc être modifié en conséquence. Mais il s'agit
d'un changement de texte visible par l'utilisateur, ainsi que de six fichiers de
traduction : il attend une validation.

---

## A3. Commandes : boutique et statut — hypothèse rejetée

**Soupçon.** `OrderMigrationStep::importOrder()` renseigne dans le tableau
`'id_shop' => $data['id_shop']` et `'current_state' => $data['current_state']`,
c'est-à-dire les valeurs de l'ancienne boutique. Si cela restait en l'état, les
commandes pointeraient vers une boutique et un statut inexistants.

**Vérification.** La ligne passe par `IdMapper::row('orders', ...)`, et la
correspondance des colonnes (`IdMapper.php:200`) traduit `id_shop` via le
dictionnaire `shop` (`SchemaHelper::getTargetShopId()`), `id_shop_group` via
`shop_group`, et `current_state` via `order_state`, c'est-à-dire à l'aide de
la table de correspondance des statuts définie dans l'assistant. Le dictionnaire
`shop` est défini dans `IdMapper.php:1078`.

**Verdict.** Le fonctionnement est correct, il n'y a pas d'erreur ici.
Ce point reste dans l'audit pour consigner qu'il a été vérifié.

**À vérifier à grande échelle.** Après la migration, vérifier que
`orders.current_state` correspond à la dernière entrée de `ps_order_history` —
cela nécessite une exécution portant sur un volume important de commandes et sera
intégré à la mesure à grande échelle.

---

## A4. Images : formats et génération des miniatures

**Soupçon 1.** Le module récupère depuis l'ancienne boutique uniquement
`img/p/<chemin>/<id>.jpg` ; une boutique configurée en PNG perdrait donc ses images.

**Vérification.** Dans le cœur, `ImageManager.php:154` : « Desired file type **inside**
the image. If jpg and $forceType is false, format inside will be decided by
PS_IMAGE_QUALITY ». Le nom du fichier sur le disque reste `<id>.jpg`, quel que
soit le réglage ; cela est également confirmé par le contenu du répertoire
`img/p` dans la boutique source (408 fichiers, tous en `.jpg`). **Hypothèse rejetée.**

**Soupçon 2.** PS 9 possède un réglage distinct `PS_IMAGE_FORMAT`
(`src/Core/Image/ImageFormatConfiguration.php`) pour webp et avif, et le module
ne génère que des miniatures `.jpg` : une boutique diffusant du webp afficherait
donc des emplacements vides.

**Vérification.** `src/Adapter/Image/ImageRetriever::checkOrGenerateImageType()`
vérifie `file_exists()` et génère à la volée la miniature manquante, dans le
format demandé. Le même comportement existe dans 1.7.8 (`ImageRetriever.php:182`)
et 8.2 (`ImageRetriever.php:324`). **Hypothèse rejetée** pour toutes les
versions cibles.

**Conclusion inverse de celle attendue.** Puisque le cœur génère lui-même
les miniatures manquantes, générer tous les formats pour chaque image pendant la
migration représente un travail largement inutile. Pour 100 000 images et cinq
formats, cela représente un demi-million d'opérations de mise à l'échelle pendant
que l'utilisateur attend. PrestaShop 9.1 dispose de son propre outil :
`php bin/console prestashop:thumbnails:regenerate products`
(documentation `development/components/console/prestashop-thumbnails-regenerate.md`),
et le panneau d'administration possède un bouton de régénération.

**Action (accord du 2026-10-21).** La génération des miniatures a été
supprimée ; le module enregistre uniquement l'original. Cette décision repose
sur trois éléments :

1. Le cœur génère la miniature manquante lors de sa première utilisation, dans
le front office (`ImageRetriever::checkOrGenerateImageType()`, même code dans
1.7.8, 8.2 et 9.1) et dans le panneau d'administration
(`ImageManager::thumbnail()`, qui redimensionne l'original vers `img/tmp`,
après avoir vérifié avec `file_exists()` que l'original existe).

2. Un commentaire dans notre code affirmait que les miniatures étaient nécessaires
pour que le panneau d'administration affiche l'image. C'est faux : le panneau
les calcule à partir de l'original.

3. Le module de référence fait la même chose : `EDImport::copyImg()` exécute
`copy($tmpfile, $path . '.jpg')` et ne génère aucune dimension.

Coût supprimé : cinq opérations de mise à l'échelle par image, soit un demi-million
pour un catalogue de 100 000 images. Ceux qui veulent que tout soit prêt
immédiatement disposent de « Regenerate thumbnails » dans le panneau
d'administration ou, à partir de 9.1, de
`php bin/console prestashop:thumbnails:regenerate products`.

---

## A5. Les mots de passe clients dans l'ancien format ne fonctionneront pas dans la nouvelle boutique

**Documentation et code du cœur.** Modifications de la version 8.0
(`modules/core-updates/8.0.md`) : « `Customer::$passwd` to be
`Validate::isHashedPassword()` ». Modifications de la version 8.1 : « Introduced
`PrestaShop\PrestaShop\Core\Security\Hashing` class to hash a password from a
plain text and a salt with md5 ». L'implémentation de la vérification
(`src/Core/Crypto/Hashing.php`) : `checkHash($passwd, $hash, staticSalt =
_COOKIE_KEY_)\` utilise deux méthodes — \`bcrypt\` avec
\`password_verify(passwd, hash)\` et \`md5\` avec \`md5(staticSalt . $passwd) ===

$hash`.

**Verdict.** Les mots de passe bcrypt sont transférés sans problème, car
leur vérification n'utilise aucun élément propre à la boutique. Les mots de passe
dans l'ancien format md5 sont salés avec la clé `COOKIE_KEY` de la
boutique qui effectue la vérification ; après la migration, ils ne seront donc
plus valides : la nouvelle boutique possède une autre clé. Cette clé se trouve
dans `app/config/parameters.php`, et non dans la base de données ; le connecteur
qui lit la base n'y a donc pas accès et aucune migration ne peut la transférer.
Cela concerne les comptes créés sous 1.5 et 1.6 dont les propriétaires ne se sont
pas reconnectés depuis — ils peuvent être très nombreux dans l'ancienne boutique.
Le module copiait le mot de passe sans signaler ce problème.

**Action (A5).** `CustomerMigrationStep::reportOldPasswords()` détecte les
mots de passe au format md5 (32 caractères hexadécimaux) et inscrit dans le journal
de migration un avertissement indiquant le nombre de ces comptes dans le lot,
ainsi que l'information selon laquelle ces clients devront utiliser la fonction
« mot de passe oublié ». Leurs comptes, commandes et adresses sont, eux, migrés
normalement. Il est impossible de corriger cela autrement qu'en transférant la
clé de la boutique, ce qui compromettrait les comptes déjà présents dans la
nouvelle boutique.

---

## A6. Le connecteur acceptait davantage que la « lecture seule »

**Ce qu'il faisait.** Dans `psconnector/api.php`, l'action `query`
vérifiait la requête à l'aide d'une liste noire de premiers mots
(`INSERT`, `UPDATE`, `DELETE`, `DROP`, …) et ne la rejetait que si la requête
commençait par l'un de ces mots.

**Verdict.** Une faille, et pas une petite. Tout ce qui commençait par
`SELECT` passait au travers de ce filtre :

- `SELECT ... INTO OUTFILE '/chemin/fichier.php'` — avec le privilège MySQL
`FILE`, accordé par de nombreux hébergements mutualisés, cette instruction
enregistre n'importe quel fichier à un emplacement où l'utilisateur de la base
a le droit d'écrire, y compris un fichier PHP dans le répertoire de la boutique.
Il s'agit d'une exécution de code à distance sur l'ancienne boutique.

- `SELECT LOAD_FILE('/etc/passwd')` — lecture de n'importe quel fichier
accessible à MySQL.

- `SELECT SLEEP(30)`, `BENCHMARK(...)` — occupation du processus PHP pendant
une durée arbitraire.

- Un commentaire placé avant la requête (`/* */` ou `--`) masquait le véritable
premier mot.

La fuite du jeton du connecteur avait donc une valeur bien supérieure à un
simple accès en lecture aux données.

**Action.** La liste noire a été remplacée par une liste blanche, car le
module n'envoie à la source que des requêtes `SELECT` (vérifié : `SHOW COLUMNS`
dans `SchemaHelper` est exécuté sur la boutique de destination, et non via le
connecteur). Désormais : les commentaires sont retirés avant la vérification,
la requête doit commencer par `SELECT`, une seule instruction est autorisée par
requête, et `INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE`, `BENCHMARK`,
`SLEEP`, `GET_LOCK` et `RELEASE_LOCK` sont rejetés.

**Vérification.** `test_connector_guard.php` envoie 11 requêtes au
connecteur réel : trois lectures ordinaires (dont une avec JOIN et une avec
sous-requête) sont acceptées ; une écriture directe, une écriture dissimulée
derrière un commentaire de bloc et un commentaire de ligne, deux instructions
dans une même requête, l'écriture d'un fichier, la lecture d'un fichier,
`SLEEP` et `BENCHMARK` sont rejetés. Les 11 résultats sont conformes aux
attentes.

**Conséquence pour les utilisateurs.** Le connecteur a été mis à niveau
en version 1.3.0. La protection agit du côté de l'ancienne boutique : elle protège
donc les utilisateurs qui installent la nouvelle version du plugin sur la
boutique source ; le module fonctionne avec les deux versions.

---

## A7. Tâches exécutées une seule fois, dans une seule requête

**Soupçon.** Le module répartit le travail en lots, mais exécute quatre
opérations une seule fois, dans une seule requête HTTP : la reconstruction de
l'arborescence des catégories, la reconstruction de l'index de recherche, les
règles de prix catalogue et les requêtes de correction `integrity`. Avec un
catalogue important, chacune d'elles peut dépasser le délai maximal de la requête
(30 s chez le déclarant), auquel cas la migration échoue à la dernière étape.

**Mesure.** Boutique avec 50 123 produits et 3 122 catégories (base
distincte afin de ne pas perturber les autres tests) :

| opération | durée |
|---|---|
| `Category::regenerateEntireNtree()` | 0,13 s |
| `SpecificPriceRule::applyAllRules()` avec une seule règle | 1,48 s |
| requêtes de correction `integrity` (3 cas représentatifs) | moins d'une seconde |
| `Search::indexation(true)` | plus de 10 minutes |

**Verdict.** Les trois premières opérations ne posent pas de problème,
même avec 50 000 produits. La seule opération coûteuse est la reconstruction
complète de l'index de recherche — et le module l'ignore au-delà de 2 000 produits,
en la laissant aux outils de la boutique. Après la correction A2 (les produits
ont `indexed = 0`), cela fonctionne exactement comme le recommande la
documentation de migration PrestaShop. Ce point est donc clos sans modification
du code.

**Méthode.** Cette conclusion a nécessité trois secondes de mesure.
Auparavant, je prévoyais une exécution de deux heures sur 50 000 produits, qui
aurait fourni les mêmes deux chiffres. Principe pour la suite de l'audit : mesurer
le coût d'un lot et d'une opération, plutôt que de traiter des millions de lignes ;
aucun test de plus de dix minutes sans nécessité clairement établie.

---