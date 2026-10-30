<p align="center">
  <img src="../assets/icon-256.png" alt="PrestaShift" title="PrestaShift" width="128" height="128">
</p>

<h1 align="center">PrestaShift</h1>

<p align="center">
  <strong>Module de migration PrestaShop</strong>
  <br>
  <strong>Simple · Élégant · Efficace</strong>
  <br>
  <a href="https://github.com/GajewskiMarcin/prestashift/releases">Télécharger</a> ·
  <a href="#">Page produit</a>
</p>

<p align="center">
  <a href="../README.md">English</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md">Português</a> ·
  <a href="README.it.md">Italiano</a> ·
</p>

> **Un seul module :** Outil professionnel de migration de données pour PrestaShop. Migrez les données de votre boutique entre PrestaShop 1.7, 8 et 9 en toute simplicité.

<p align="center">
  <img src="https://img.shields.io/badge/PrestaShop-1.7%2B-DF0067?logo=prestashop&logoColor=white" alt="PrestaShop 1.7+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL 5.7+">
  <img src="https://img.shields.io/badge/MariaDB-10.3%2B-003545?logo=mariadb&logoColor=white" alt="MariaDB 10.3+">
  <img src="https://img.shields.io/badge/license-AFL--3.0-orange.svg" alt="Licence libre académique 3.0 (AFL-3.0)">
</p>

# Le projet PrestaShift est un module de migration de données pour PrestaShop.

**En résumé :** il permet de migrer les données d'une boutique PrestaShop ancienne (1.7+) vers une boutique plus récente (8 ou 9), par lots, avec reprise, journalisation et aperçu préalable.

Architecture en deux parties :

* Source (ancienne boutique) → module PSConnector (lecture seule, API sécurisée par jeton)
* Cible (nouvelle boutique) → module PrestaShift (moteur de migrati

## Fonctionnalités

- **Pipeline de migration en 39 étapes** — produits, catégories, clients, commandes, images, transporteurs, CMS, et bien plus
- **Deux modes de connexion** — Bridge Connector (fonctionne entre serveurs) ou Base de données directe (plus rapide, même serveur)
- **Adaptation aux versions** — détection automatique des versions source/cible avec avertissements de compatibilité
- **Transformations PS 1.7 → 8/9** — `redirect_type`, `id_type_redirected` convertis automatiquement
- **Synchronisation incrémentale** — migre uniquement les modifications depuis la dernière synchronisation
- **Traitement par lots** — tailles de lot configurables avec pause/reprise
- **Transfert d'images** — télécharge les images et génère toutes les tailles de miniatures
- **Support multiboutique** — choisir l'ID de la boutique cible
- **Vérifications pré-vol** — valide les limites PHP, l'espace disque, cURL avant de démarrer
- **Tâches post-migration** — reconstruction automatique de l'index de recherche, de l'arborescence des catégories, vidage du cache
- **Aperçu à blanc** — visualiser les nombres d'enregistrements avant la migration
- **Journalisation dans des fichiers** — journaux détaillés dans `var/logs/prestashift.log`
- **Carte de redirections** — génère un fichier de redirections 301 pour préserver le SEO
- **Mappage des statuts** — associer les statuts de commande entre la source et la cible
- **Langues de l'interface** — anglais, polonais, français, espagnol, portugais, italien, allemand
- **Configuration sélective** — migre les réglages sûrs de la boutique (nom, SEO, expédition, etc.)
- **Multi-langues** — anglais + polonais (traduisible via le back-office PrestaShop)

## Données migrées

| Domaine | Données |
|---------|---------|
| **Catalogue** | Produits, Catégories, Attributs, Caractéristiques, Stock, Packs, Produits virtuels, Champs de personnalisation, Étiquettes |
| **Tarification** | Prix spécifiques, Règles de prix catalogue |
| **Médias** | Images de produit (avec miniatures), Pièces jointes, Logos de fabricants |
| **Clients** | Clients, Groupes, Adresses, Listes de souhaits |
| **Commandes** | Commandes, Détails de commande, Historique, Paiements, Factures, Avoirs, Paniers |
| **Marques** | Fabricants, Fournisseurs, Liens Produit-Fournisseur |
| **Expédition** | Transporteurs, Plages, Zones de livraison, Frais |
| **Contenu** | Pages et catégories CMS, Métadonnées/SEO, Contacts, Magasins physiques |
| **Localisation** | Pays, États/régions, Zones, Devises, Langues, Règles fiscales |
| **Administration** | Employés, Profils, Règles de panier (avec conditions), Configuration de la boutique |
| **Stock** | Stock disponible, Mouvements de stock |

## Prérequis

- **Boutique cible :** PrestaShop 1.7+ (où PrestaShift est installé)
- **Boutique source :** PrestaShop 1.7+ (où PSConnector est installé)
- PHP 7.4+ avec l'extension cURL
- MySQL 5.7+ / MariaDB 10.3+

## Installation

### Sur la boutique CIBLE (nouvelle boutique) :
1. Téléversez le dossier `prestashift/` dans `/modules/`
2. Installez via le back-office → Modules → « PrestaShift Migration »

### Sur la boutique SOURCE (ancienne boutique) :
1. Téléversez le dossier `psconnector/` dans `/modules/`
2. Installez via le back-office → Modules → « PrestaShift Connector »
3. Copiez le jeton sécurisé généré depuis la page de configuration du module

### Lancer la migration :
1. Ouvrez PrestaShift sur la boutique cible
2. Saisissez l'URL de la boutique source + le jeton
3. Sélectionnez le périmètre de données
4. Configurez les options (taille des lots, nettoyage de la cible, etc.)
5. Lancez la migration

## Architecture

```
Boutique source (PS 1.7/8)          Boutique cible (PS 8/9)
┌─────────────────┐            ┌──────────────────┐
│   PSConnector    │◄──HTTP──► │   PrestaShift     │
│   (passerelle    │   Bridge  │   (moteur de      │
│    API en        │           │    migration)     │
│    lecture)      │           │                   │
└─────────────────┘            └──────────────────┘
```

PSConnector expose un point d'entrée API sécurisé et en lecture seule. PrestaShift s'y connecte via HTTP, récupère les données par lots, les transforme pour la compatibilité entre versions, puis les importe dans la base de données cible.

Alternative : connexion directe à la base de données (PDO) pour les migrations sur le même serveur — plus rapide, sans passerelle.

## Sécurité

- Authentification par jeton (hexadécimal de 64 caractères)
- Le connecteur est **en lecture seule** — les opérations d'écriture sont bloquées
- L'accès aux fichiers est restreint aux répertoires `img/`, `download/`, `upload/`
- Protection contre le parcours de chemin avec validation `realpath()`
- Comparaison de jeton à temps constant (`hash_equals`)

## Rapports d'erreur (opt-in)

Le module peut envoyer par e-mail un rapport d'échec à l'auteur, pour aider à diagnostiquer les problèmes de migration.
**Il est désactivé par défaut** — vous l'activez avec l'interrupteur *Send Error Reports to Developer*
dans l'étape Options.

Lorsqu'il est activé, une migration échouée envoie : le message d'erreur et la charge utile de débogage, vos versions de PrestaShop et
PHP, `memory_limit` / `max_execution_time`, l'URL de la boutique source, l'étape à laquelle la migration s'est arrêtée et les réglages utilisés (tailles des lots, délais, périmètre), ce que le dernier appel à la
boutique source a fait, les dernières lignes du journal de migration, ainsi que l'adresse e-mail de l'employé connecté. Rien n'est envoyé tant que l'interrupteur est désactivé, et aucune donnée client, commande ou catalogue n'est
jamais transmise. Voir [TelemetryService.php](prestashift/src/Service/TelemetryService.php) — c'est
le seul chemin de code qui envoie quoi que ce soit à l'auteur. L'autre connexion sortante du module
(`ConnectorClient`) communique uniquement avec l'URL de la boutique source que vous configurez vous-même.

## Support

- [Signaler un problème](https://github.com/GajewskiMarcin/prestashift/issues)
- [Discussions](https://github.com/GajewskiMarcin/prestashift/discussions)
- [Offrez-moi un café](https://buymeacoffee.com/marcingajewski)

## Auteur

Créé par [marcingajewski.pl](https://marcingajewski.pl)

## Licence

[Licence académique libre 3.0 (AFL-3.0)](LICENSE) — la licence standard des modules PrestaShop.

### Notice d'attribution

Ce qui suit est une **Notice d'attribution** au titre de la section 6 de l'AFL-3.0. La section 6 exige
que toute œuvre dérivée la conserve :

> PrestaShift a été créé par Marcin Gajewski — https://marcingajewski.pl
> Projet original : https://github.com/GajewskiMarcin/prestashift
> Soutenir le travail : https://buymeacoffee.com/marcingajewski
>
> Les œuvres dérivées et les forks doivent conserver cette notice, le crédit à l'auteur et le lien de soutien affichés
> sur l'écran final du module. « PrestaShift » est le nom du module original : si vous publiez une
> version modifiée, veuillez lui donner votre propre nom.

Les forks sont bienvenus — les corrections renvoyées sous forme de pull requests profitent à tous les utilisateurs du module.

Contributions go through pull requests to `main`.

### Remerciements

PrestaShift s'améliore avec ces personnes :

| Qui | Contribution |
|---|---|
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |

La même liste est dans le module : **Paramètres → A propos → Merci**.
