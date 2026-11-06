# FTP

Environnement de développement Laravel 13 avec **deux images et deux services
Docker** : `ftp` contient le code, PHP 8.4, les dépendances Composer (développement
inclus) et les assets Vite compilés ; `mysql` utilise l'image officielle MySQL 8.4.
Le serveur MySQL tourne dans son propre conteneur. Seuls Docker Desktop avec
Compose, Git et OpenSSL (fourni sur macOS) sont nécessaires sur le Mac.

## Premier lancement

```sh
git clone https://github.com/khalidelyounssi/FTP.git
cd FTP
cp .env.example .env
sh docker/prepare-env.sh
docker compose config --quiet
docker compose up -d --build
docker compose ps
```

Ouvrir <http://localhost:8090>. Sur un volume vide, l'image officielle MySQL crée
la base `ftp` et son compte applicatif. Compose attend que MySQL accepte une
connexion de ce compte avant de lancer FTP. Laravel vérifie à son tour la
connexion, exécute les migrations, puis lance HTTP. L'état `healthy` de FTP
vérifie la base et le point de santé Laravel `/up`.

Le script `prepare-env.sh` crée `.env` depuis l'exemple si nécessaire et génère
`APP_KEY` seulement si elle est vide ou absente. Il conserve une clé existante et
n'affiche aucune valeur sensible. Ne pas recopier `.env.example` sur un `.env`
déjà configuré. Les identifiants de `.env.example` sont des exemples locaux ; les
personnaliser dans `.env` avant la première initialisation si nécessaire.
`DB_USERNAME` et `DB_PASSWORD` sont utilisés à la fois par Laravel et par
l'initialisation MySQL. `MYSQL_ROOT_PASSWORD` est le mot de passe d'administration.

`.env` est ignoré par Git et exclu du contexte Docker. Compose transmet ses
variables à l'exécution via `env_file` ; aucune clé ni aucun mot de passe local
n'est fourni au build. La clé reste dans le `.env` du Mac après reconstruction.

## Commandes courantes

```sh
# Logs de FTP et de MySQL
docker compose logs -f --tail=100

# Logs de MySQL uniquement
docker compose logs -f --tail=100 mysql

# Logs applicatifs Laravel
docker compose exec ftp tail -f storage/logs/laravel.log

# État des migrations avec la connexion configurée dans Laravel
docker compose exec --user www-data ftp php artisan migrate:status

# Client MySQL : saisir DB_PASSWORD à l'invite
docker compose exec mysql sh -c 'exec mysql -u "$MYSQL_USER" -p "$MYSQL_DATABASE"'

# Arrêt propre et redémarrage du même conteneur
docker compose stop
docker compose start

# Redémarrage immédiat, base conservée
docker compose restart

# Arrêt et suppression du conteneur et du réseau, base conservée
docker compose down

# Relancer, ou reconstruire après une modification du code ou des assets
docker compose up -d --build
```

Le code et `vendor` sont intégrés dans l'image. Après modification du dépôt,
relancer la dernière commande pour reconstruire ; aucun montage local ne masque
les dépendances ou les assets de l'image. `composer install` lit `composer.lock`
et `npm ci` lit `package-lock.json`. Node 24 sert uniquement pendant le build.
Les images sources sont épinglées par leur digest multiarchitecture ; Docker
sélectionne nativement ARM64 sur Apple Silicon et AMD64 sur Mac Intel, sans
forcer une émulation.

## Données persistantes

Le volume nommé `ftp_mysql-data` est monté dans `/var/lib/mysql` du conteneur
`mysql`. Il survit à `restart`, `stop` / `start`, `down` et aux reconstructions.
Un volume existant n'est pas réinitialisé ; les variables d'initialisation ne
recréent pas ses comptes ni leurs mots de passe. Garder les identifiants existants
ou les modifier explicitement dans MySQL avant de mettre `.env` à jour.

Le volume MySQL de l'ancienne image combinée est réutilisé directement. Si un
compte hérité n'autorise que `localhost` ou `127.0.0.1`, il doit aussi autoriser
les connexions du conteneur FTP sur le réseau Docker, par exemple avec un compte
applicatif d'hôte `%` limité à la base `ftp`.

L'ancien volume SQLite `ftp_app-data` et le fichier SQLite d'origine du dépôt
restent conservés et ne sont pas utilisés par cette configuration. Leurs données
ne sont pas importées automatiquement dans MySQL.

Si des variables changent, les appliquer avec
`docker compose up -d --force-recreate` ; `restart` conserve l'ancien environnement.

**`docker compose down -v` supprime la base : ne pas l'utiliser pour arrêter le
projet.**

Seul le port HTTP `127.0.0.1:8090` est publié sur le Mac. Compose arrête FTP avant
MySQL. Le script FTP termine Laravel et ses workers ; le conteneur officiel MySQL
termine son serveur. Chaque service dispose de 90 secondes pour s'arrêter.

## Connexion entre les conteneurs

Laravel utilise le pilote PHP `pdo_mysql` et le nom du service Docker `mysql` :

```dotenv
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=ftp
DB_USERNAME=ftp
DB_PASSWORD=ftp_local_password
```

`mysql:3306` est accessible sur le réseau privé Compose. Le port MySQL n'est pas
publié sur le Mac : un éventuel serveur MySQL déjà installé sur le Mac peut garder
son port. Aucun serveur ou client MySQL n'est installé dans l'image FTP.

## Vérifications

```sh
docker compose config --quiet
docker compose ps
curl --fail --silent --output /dev/null --write-out '%{http_code}\n' http://localhost:8090
docker compose exec --user www-data ftp php artisan migrate:status
docker compose exec ftp php -v
docker compose exec mysql mysql --version
docker compose exec ftp uname -m
```

Les tests existants peuvent être exécutés sans utiliser la base de développement.
Les variables de test sont explicites car celles du conteneur priment sur les
valeurs par défaut de `phpunit.xml` :

```sh
docker compose exec -T \
  -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= \
  -e SESSION_DRIVER=array -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  -e MAIL_MAILER=array ftp vendor/bin/phpunit
```

Pour vérifier que l'image FTP n'embarque aucun serveur ou client MySQL :

```sh
docker compose exec ftp sh -c '! command -v mysqld && ! command -v mysql && ! command -v mysqladmin'
```

## Site public et catalogue — FTP-15

Les pages `/`, `/contact`, `/catalogue` et `/catalogue/{id}` exposent le site public.
La liste React utilise la véritable API pour filtrer et paginer, avec chargement,
erreurs, reprise manuelle et navigation précédent/suivant du navigateur. Le
catalogue reste consultable sans JavaScript ; les formulaires de compte utilisent
React. Les erreurs sont reliées aux champs et le lien « Aller au contenu » facilite
la navigation au clavier.

`/connexion` utilise le backend FTP-11 : code client et mot de passe ouvrent une
session uniquement si le compte est approuvé. `/demande-compte` transmet les
coordonnées à l'inscription existante, sans droit interne ni approbation implicite.
Le panier et la commande restent le périmètre FTP-19 et des tickets de vente.

Le prix de vente est réservé aux clients authentifiés dont le profil persistant
est `APPROUVE`. Cette règle a été confirmée par Khalid le 4 novembre 2026. Elle
est réévaluée à chaque requête : un compte suspendu perd immédiatement la
visibilité du prix dans les réponses suivantes. Les coûts d'achat, barcodes de
logistique et champs internes ne font pas partie du catalogue public. Les
brouillons et archives retournent 404, même pour un client approuvé.

### Configuration de l'entreprise

Renseigner les variables `COMPANY_*` de `.env.example` dans le `.env` local :
identité, coordonnées, horaires, présentation, services, visuels approuvés et
lien vers les informations contractuelles. Les champs non fournis restent absents.
Aucun délai de réponse, contenu de service, logo ou coordonnées officiels n'est
inventé. Les marques et familles de l'accueil proviennent des produits publiés.
Après modification, reconstruire avec `docker compose up -d --build --wait`.

### Exemples Postman

Envoyer `Accept: application/json`. Les filtres sont combinables ; les valeurs
ci-dessous correspondent uniquement au jeu de démonstration facultatif.

```http
GET /api/v1/catalogue/products?category=Filtres&per_page=1&page=1
GET /api/v1/catalogue/products?part_brand=Marque%20d%C3%A9mo%20A
GET /api/v1/catalogue/products?vehicle_make=Camion%20d%C3%A9mo&vehicle_model=Mod%C3%A8le%20A
GET /api/v1/catalogue/products?reference=CROSS-DEMO-001
GET /api/v1/catalogue/products/{id}
```

La liste contient `data`, `total`, `current_page`, `last_page` et les liens de
pagination. `per_page` accepte 1 à 100. Une valeur invalide retourne 422 avec les
erreurs par champ ; une référence inconnue retourne une liste vide. Les références
originales et croisées vérifiées sont recherchées selon la règle FTP-17 ci-dessous.

Pour vérifier la visibilité authentifiée, initialiser la session avec
`GET /sanctum/csrf-cookie`, conserver ses cookies et envoyer le cookie `XSRF-TOKEN`
décodé comme en-tête `X-XSRF-TOKEN` sur `POST /api/v1/login` :

```json
{"identifier":"CODE_D_UN_CLIENT_APPROUVE","password":"MOT_DE_PASSE_DU_COMPTE_DE_TEST"}
```

Rejouer ensuite les GET avec les cookies de session. Le prix de vente est présent
uniquement pour le client approuvé et le coût d'achat reste absent. Tester aussi
une connexion refusée, un profil en attente, un profil suspendu après connexion
et `POST /api/v1/logout`. L'approbation réelle requiert `CUSTOMER_APPROVE` ; le
seeder public ne crée aucun compte ni permission et ne remplace pas cette intégration.

### Produits de démonstration facultatifs

Sur la base locale de développement uniquement :

```sh
docker compose exec --user www-data ftp php artisan db:seed --class=PublicCatalogueDemoSeeder --no-interaction
```

Ce seeder crée quatre produits identifiables par `FTP15-DEMO-*` : deux publiés,
un brouillon et une archive, avec références et compatibilités fictives. Il ne
remplace aucun produit existant et peut être rejoué. Ces données servent à la
vérification et ne constituent pas un catalogue ni un contenu commercial approuvé.

## Recherche par références — FTP-17

Règle confirmée par Khalid le 4 novembre 2026 : ignorer la casse et les espaces
de présentation (y compris espaces insécables et tabulations), en conservant
les points, tirets, autres symboles et zéros initiaux. Le texte saisi reste stocké
et affiché sans réécriture. Par exemple `crf200028` retrouve `CR F 200028`,
`03434301000` retrouve `03 434 3010 00`, mais `566830H195` ne retrouve pas
`566830.H195`. Aucun rapprochement partiel, par désignation ou par ressemblance
n'est effectué ; les correspondances entre codes différents sont explicites.

Les valeurs normalisées et leurs clés de recherche sont persistées et indexées.
Les clés SHA-256 rendent les comparaisons indépendantes des collations SQL qui
pourraient ignorer des caractères significatifs. La migration renseigne les clés
des données existantes sans modifier les codes bruts, décisions de vérification
ou identités, et sans supprimer de doublons historiques. Les modèles Product et
ProductCrossReference maintiennent les clés lors des écritures ; utiliser ces
modèles et leurs factories, pas des écritures SQL brutes dépourvues de clés.
Un changement futur de normalisation demande une nouvelle migration de clés.

Le même endpoint public accepte `reference`, les filtres existants et la pagination.
Une égalité littérale passe avant les variantes normalisées, puis les produits
sont ordonnés par ID. Chaque produit apparaît une fois, même si plusieurs de ses
associations correspondent. Une référence partagée retourne tous les produits
publiés autorisés ; une référence croisée non vérifiée ne donne pas de résultat.
Chaque résultat comporte un objet `matched_reference` :

```json
{"type":"CROISEE","reference":"566830.H195","brand":"Source démo","match":"exact"}
```

`type` vaut ORIGINALE ou CROISEE ; `match` vaut exact ou normalized. React et le
fallback HTML affichent le type, le code et la source correspondants, puis un lien
vers le produit canonique. Les refus de publication et de prix restent appliqués.
Une recherche inconnue reste sans écriture et propose le contact. Un texte ne
contenant que des espaces retourne 422 avec une erreur sur `reference`.

### Gestion protégée des références

L'éditeur React est à `/gestion/produits/{id}/references`. Il utilise la session
existante, CSRF et la capacité serveur `REFERENCE_MANAGE` ; un client suspendu
reste refusé. Cette capacité doit être fournie par le véritable module de
permissions : aucune gate permissive, compte spécial ou identifiant publié
n'est ajouté par FTP-17. Les tests autorisés utilisent des permissions de test ;
ils ne valident pas le raccordement réel des rôles FTP-13.

```http
GET    /api/v1/products/{id}/references
POST   /api/v1/products/{id}/references
PUT    /api/v1/products/{id}/references/{referenceId}
PATCH  /api/v1/products/{id}/references/{referenceId}/verification
DELETE /api/v1/products/{id}/references/{referenceId}
PUT    /api/v1/products/{id}/original-reference
```

POST accepte `{"reference":"0343012","brand":"Source connue"}` ; la source
est facultative. Une nouvelle association reste à vérifier. Une répétition
équivalente pour le même produit et la même source retourne l'association
existante (200 au lieu de 201), sans modifier son texte ni répéter l'audit.

PUT d'une référence croisée ajoute `"version":1` aux champs ci-dessus. PATCH de
vérification et DELETE exigent eux aussi `{"version":1}` : c'est une évolution
du PATCH de vérification FTP-16, dont les clients doivent désormais transmettre
la version reçue par GET. Une version périmée retourne 409. Modifier le code ou
sa source incrémente sa version et retire sa vérification ; une vérification
répétée de la même version conserve sa date et ne répète pas l'audit.

PUT de la référence originale accepte
`{"reference":"CR F 200028","expected_reference":"ANCIEN CODE"}`. Un ancien
texte périmé retourne 409. Les changements de références via l'ancienne mise à
jour produit ou `/logistics` demandent également REFERENCE_MANAGE ; les seules
permissions descriptives ne permettent pas de contourner ce contrôle.

Une association d'un autre produit retourne 404. Les champs injectés, notamment
verified_at, product_id ou une clé calculée, sont refusés. Ajouts, modifications,
vérifications et suppressions sont audités dans la même transaction, avec auteur,
sans prix ni secret. Une erreur d'audit annule l'écriture. La suppression demande
une confirmation nommant le code dans l'interface et conserve le produit.

### Exemples du cahier et limites d'intégration

Le jeu local facultatif crée les exemples du moyeu, du manchon et une collision
`PARTAGE-FTP17` entre deux produits publiés, plus un brouillon et une archive :

```sh
docker compose exec --user www-data ftp php artisan db:seed --class=ReferenceSearchDemoSeeder --no-interaction
```

```http
GET /api/v1/catalogue/products?reference=CR%20F%20200028
GET /api/v1/catalogue/products?reference=0343012
GET /api/v1/catalogue/products?reference=03%20434%203010%2000
GET /api/v1/catalogue/products?reference=200028
GET /api/v1/catalogue/products?reference=566830.H195
GET /api/v1/catalogue/products?reference=SET%201372
GET /api/v1/catalogue/products?reference=PARTAGE-FTP17&per_page=1
```

Les cinq produits `FTP17-DEMO-*` et leurs références sont fictifs. Aucun compte,
droit ou stock n'est créé. La recherche ne filtre pas selon le stock et retrouve
aussi un produit sans inventaire. Le module de stock FTP-24 et la politique de
visibilité des disponibilités ne sont pas encore intégrés : l'affichage réel
d'un disponible zéro et le scénario REC44 restent à vérifier avec cette source.
Ne pas interpréter l'absence de stock renseigné comme une quantité zéro ou une
disponibilité garantie. Le raccordement réel de REFERENCE_MANAGE reste également
nécessaire avant une recette complète de la gestion des références.

## Promotions et prix clients — FTP-18

Décisions confirmées par Khalid le 4 novembre 2026 : MAD actuellement, meilleur
prix entre promotion et remise client **sans cumul**, fuseau Africa/Casablanca,
fenêtre `[début, fin[`, aucun chevauchement de promotions activées pour le même
produit et offre arrondie strictement inférieure au tarif normal. HT/TTC est un
choix configurable, initialement non précisé ; aucun taux, calcul de TVA ni
conversion de devise n'est ajouté. Les prix restent saisis par les personnes
habilitées. Les calculs de remise utilisent les unités mineures entières, deux
décimales et l'arrondi au plus proche avec demi-unité vers le haut ; aucun calcul
monétaire PHP en flottants. Exemple : 100.00, offre 80.00, remise client 10 % →
80.00 ; avec remise client 30 % → 70.00. Une remise de 100 % peut donner 0.00.

### Contrat de prix v1

Le catalogue, les recherches, les détails et la liste des promotions consomment
le même service `CustomerPricing`. Pour un client réellement authentifié et
approuvé, `sale_price` représente désormais le **prix effectif**, en chaîne
décimale ; la colonne catalogue n'est jamais réécrite par une lecture. Le nouvel
objet `pricing` donne aussi le tarif de base, la provenance et la validité :

```json
{
  "schema_version": 1,
  "policy_version": "ftp18-v1",
  "status": "ready",
  "product_id": "10",
  "currency": "MAD",
  "tax_basis": null,
  "base_unit_price": "100.00",
  "unit_price": "80.00",
  "applied_source": "promotion",
  "promotion": {"id":"UUID", "title":"Offre", "unit_price":"80.00", "version":1,
    "starts_at":"2026-11-05T08:00:00+00:00", "ends_at":"2026-11-05T09:00:00+00:00"},
  "customer_discount_percentage": "10.00",
  "calculated_at": "2026-11-05T08:30:00+00:00",
  "valid_until": "2026-11-05T09:00:00+00:00",
  "fingerprint": "SHA256_DES_CONDITIONS"
}
```

`applied_source` vaut catalogue, promotion ou customer_discount. Un tarif de base
manquant ou des offres concurrentes incohérentes donnent respectivement
`missing_base_price` ou `conflicting_promotions`, avec `unit_price: null` : ce
n'est pas un prix zéro. Brouillons, archives, coûts d'achat et données d'autres
clients restent exclus des réponses publiques. Une suspension/retrait
d'approbation masque immédiatement prix et conditions aux requêtes suivantes.

```http
GET /api/v1/me/promotions?page=1&per_page=20
GET /api/v1/me/products/10/price
GET /api/v1/me/products/10/price?previous_fingerprint=SHA256_PRECEDENT
```

Le dernier GET retourne `data`, `changed` et un message de revalidation. Le
fingerprint sert à détecter un changement, jamais à autoriser un prix fourni par
le navigateur. Le serveur recalcule toujours. Les listes incluent
`refresh_after_ms` ; React actualise aux bornes connues et au maximum toutes les
60 secondes, ainsi qu'au retour sur la page. Les fiches disposent aussi d'un
bouton d'actualisation ; après erreur, un ancien tarif n'est pas présenté comme
actuel. L'espace client `/promotions` et son API exigent une session approuvée.

**Panier et commandes :** FTP-19 et FTP-20 ne sont pas présents dans develop au
moment de cette livraison. Le propriétaire du panier doit remplacer sa lecture
directe du tarif catalogue par `CustomerPricing::quotes($products, $actor)`, ou
l'API personnelle, puis comparer les fingerprints avant poursuite. À l'accord,
le propriétaire de la commande doit relire/verrouiller les produits et conserver
le résultat monétaire et ses conditions comme instantané historique dans sa
transaction. Une commande acceptée doit utiliser cet instantané ensuite, sans
rappeler le tarif courant pour réécrire son prix. FTP-18 ne crée aucune fausse
commande acceptée ni ne modifie les tables de vente ; l'expiration/reprise d'un
panier réel et la conservation d'une vraie commande restent des validations
d'intégration à réaliser avec leurs modules.

### Gestion et permissions

L'éditeur React `/gestion/promotions` gère recherche du produit, tarif de base si
habilité, brouillons, activation/désactivation, dates et édition avec version.
`/gestion/remises-clients` gère les pourcentages individuels. Les capacités
serveur sont distinctes :

| Action | Capacité |
| --- | --- |
| Consulter et gérer les offres, lire leurs produits/tarifs de base | PROMOTION_MANAGE |
| Modifier un tarif catalogue | PRODUCT_PRICE_UPDATE |
| Consulter et modifier les remises clients | CUSTOMER_TERMS_MANAGE |
| Déclarer HT/TTC pour les montants saisis | SETTINGS_MANAGE |

Aucun rôle ou compte permissif n'est ajouté. Le module de permissions FTP-13 doit
fournir ces capacités réelles ; les tests positifs de gestion utilisent des Gates
de test et ne constituent pas une validation de ce raccordement. Le choix HT/TTC
décrit les montants saisis : il ne transforme pas automatiquement un ancien prix
HT en TTC. Le fingerprint change ; les modules de vente doivent conserver la base fiscale
d'origine avec leur instantané historique.

```http
GET  /api/v1/promotions?state=enabled&product_id=10&page=1&per_page=20
GET  /api/v1/promotions/products?search=SKU_OU_REFERENCE_OU_DESIGNATION&page=1
POST /api/v1/promotions
PUT  /api/v1/promotions/{uuid}
GET  /api/v1/promotions/settings
PUT  /api/v1/promotions/settings
GET  /api/v1/customer-discounts?search=CODE_OU_NOM&page=1
PUT  /api/v1/customers/{customerProfileUuid}/discount
```

POST exige `Accept: application/json`, la session/CSRF et un en-tête
`Idempotency-Key` UUID. Exemple de corps :

```json
{"product_id":"10","title":"Offre novembre","kind":"fixed_price","value":"80.00",
 "starts_at":"2026-11-05T09:00","ends_at":"2026-11-05T10:00",
 "timezone":"Africa/Casablanca","state":"enabled"}
```

`kind` accepte fixed_price ou percentage ; `value` est une chaîne décimale.
`state` accepte draft, enabled ou disabled. Les dates entrantes sont des heures
locales dans le fuseau donné ; elles sont validées puis stockées en UTC. Les
heures inexistantes ou ambiguës lors d'un changement d'heure sont refusées, sans
choix silencieux. L'activation exige le fuseau métier approuvé. Les dates
sortantes sont ISO 8601 avec décalage ; starts_local/ends_local servent au
formulaire. Une nouvelle tentative avec même acteur/clé/contenu retrouve la
même offre (200 au lieu de 201) ; même clé avec un autre contenu → 409.

PUT reprend les champs sauf product_id et ajoute `expected_version` reçu dans
`version`. Version périmée → 409, saisie refusée → 422, absence de droit → 403,
session absente → 401. Le produit d'une offre est immuable ; désactiver conserve
la ligne et son audit, sans route de suppression. L'édition ne réécrit aucune
commande historique. Les produits sont verrouillés pour sérialiser les offres
concurrentes ; les reprises d'interblocage sont bornées à trois tentatives.

Une modification du prix de base qui invaliderait une offre activée non expirée
est refusée : corriger/désactiver d'abord cette offre. L'ancien PATCH
`/api/v1/products/{id}/price` accepte en plus `expected_sale_price` (chaîne ou
null), utilisé par l'interface pour détecter une modification concurrente. Les
créations, modifications d'offre, tarifs, paramètres et remises sont auditées
dans la même transaction ; une panne d'audit annule l'écriture. Les clés
techniques d'idempotence et le coût d'achat ne sont pas exposés dans les DTOs.

PUT settings accepte `{"tax_basis":"HT","expected_version":1}` (HT, TTC ou
null). MAD est fixé pour cette version, sans accepter une devise injectée. PUT
discount accepte `{"percentage":"12.50","expected_version":0}` : 0 signifie
qu'aucune règle n'existait ; ensuite transmettre la version lue. Un pourcentage
de 0 retire l'avantage aux prochains calculs. Aucun identifiant de client ni
montant final ne peut être injecté pour modifier une autre règle.

### Fixtures locales facultatives

Hors production, ces fixtures identifiables ne représentent aucune offre réelle :

```sh
docker compose exec -T --user www-data -e FTP_PRICING_DEMO=1 ftp php artisan db:seed --class=PromotionSeeder --no-interaction
```

Elles créent cinq produits FTP18-DEMO-* (offre en cours, future, expirée,
désactivée et produit non publié) et un auteur fictif sans permission interne.
Elles peuvent être rejouées sans écraser les produits/offres existants. Aucun
stock n'est créé. Pour tester le vrai login et le rendu client, ajouter
`-e FTP_PRICING_DEMO_CUSTOMER=1` : un client fictif approuvé et sa remise de 10 %
sont alors créés explicitement. Son mot de passe aléatoire reste uniquement dans
`storage/app/private/ftp18-demo-access.json` (mode 0600), jamais dans le dépôt ou
le README. Ce profil de fixture ne valide pas le processus administratif réel
d'approbation ni les droits de gestion. `FTP_PRICING_DEMO_SECONDS` borne la durée
de la nouvelle offre active (30 à 86400 secondes), sans réinitialiser une offre
existante lors d'une reprise.


Le moteur exige les règles IANA récentes de Casablanca (2026c ou ultérieures) :
une base ancienne est refusée avec 503 plutôt qu'un décalage silencieux. L'image
Docker vérifiée utilise timezonedb 2026.5 ; le PHP local de ce poste annonce
2025.2 et doit donc utiliser Docker pour ces tests et les calculs de promotions.
Aucune extension locale n'a été installée ni dépendance du projet modifiée.
Source : [notes IANA 2026c](https://data.iana.org/time-zones/tzdb-2026c/NEWS).

```sh
docker compose exec -T -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= -e SESSION_DRIVER=array -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array ftp vendor/bin/phpunit
```


### FTP-31 — Journal immuable et notifications durables

`GET /gestion/audit` fournit le journal React, les filtres et le suivi des envois. Les droits sont contrôlés côté serveur : `AUDIT_READ` pour lire les événements et les états d’envoi, `AUDIT_EXPORT` pour télécharger le journal. La capacité distincte `NOTIFICATION_RETRY` est le contrat de reprise à raccorder aux rôles FTP-13 ; reprendre un accès employé exige également `USER_RESET_ACCESS`. Aucun rôle n’est accordé par cette feature. Les tests positifs utilisent des Gates de fixture et ne valident pas encore le fournisseur réel FTP-13.

Toutes les routes ci-dessous utilisent la session authentifiée et retournent des JSON avec `Accept: application/json`. Une écriture doit fournir le cookie de session et le jeton CSRF comme les formulaires existants. Un visiteur reçoit 401 ; un compte sans capacité reçoit 403. L’interface masque les actions indisponibles mais les API les contrôlent indépendamment.

| Méthode et chemin | Contrat |
| --- | --- |
| `GET /api/v1/audit-events` | Pagination `per_page` 1–100 avec curseurs opaques `meta.next_cursor` / `meta.previous_cursor` à transmettre dans `cursor` ; filtres `month=2026-11`, `actor_id`, `action`, `object_type`, `object_id`, `result=SUCCESS/REFUSED/FAILED`, `from`/`to` ISO 8601 avec fuseau. Bornes mensuelles dans Africa/Casablanca, début inclus/fin exclue ; dates retournées en UTC. |
| `GET /api/v1/audit-events/export?month=2026-11` | Droit AUDIT_EXPORT séparé ; vrai fichier `.xlsx` contenant tous les événements correspondant au mois et aux filtres, sans limite cachée de pagination. `X-Audit-Export-Count` donne le nombre de lignes. Préparation en snapshot transactionnel ; cellules texte explicites, sans formules. Export au-delà de la capacité d’une feuille Excel refusé (413), jamais tronqué. |
| `GET /api/v1/notification-deliveries` | Droit AUDIT_READ ; pagination et filtres `kind=approval/employee/order`, `status=PENDING/RETRY/SENT/EXPIRED`. Réponse limitée aux identifiants techniques, états, dates, tentatives et codes d’erreur ; aucun destinataire, mot de passe, contenu de mail ou payload métier. |
| `POST /api/v1/notification-deliveries/{kind}/{uuid}/retry` | JSON `{"expected_attempts":2}` ; droit NOTIFICATION_RETRY (+USER_RESET_ACCESS pour employé), CSRF et limite 30/minute. Seul RETRY est repris. Inconnu 404, état/tentative périmé ou accès expiré 409, champs imposés par le client 422. Réponse 202 : reprise programmée, sans envoi dans la requête ni recréation de compte/commande. |

Décisions de Khalid du 6 novembre 2026 : **les événements restent conservés** ; export Excel mensuel déclenché depuis la page, téléchargement à vérifier dans le navigateur. Aucun effacement automatique ni commande d’édition/suppression du journal. Un export du mois en cours couvre les événements déjà enregistrés ; le mois peut être téléchargé à nouveau après sa clôture. Les fichiers sont préparés dans le stockage privé avec droits 0600 puis supprimés après transmission ; cela ne supprime aucun événement.

Le schéma d’événement v1 conserve les champs existants `id`, `actor_id`, `request_id`, `action`, `object_type`, `object_id`, `result`, `reason`, `changes`, `occurred_at`. Il ajoute `schema_version`, `actor_kind` (account/visitor/system), `role_context` (codes des rôles au moment de l’événement), `context` (origine ou méthode/route/identifiant HTTP). L’API signale `role_context_available=false` pour les événements historiques dont les rôles ne peuvent pas être reconstitués. Les champs avant/après sont filtrés par liste blanche, y compris en lecture des événements antérieurs ; mots de passe, jetons, cookies, corps de requête, remarques internes et URLs contenant potentiellement des secrets sont exclus. Le contexte HTTP est produit côté serveur, avec `X-Request-ID` ; les identifiants d’opérations existants restent conservés.

Contrat pour les autres features : `AuditWriter::record($event)` écrit un événement filtré. Le succès critique doit être écrit **dans la même transaction que la mutation métier**. `AuditWriter::transaction($operation, $failureMetadata)` fournit cette frontière aux traitements autonomes : il doit posséder la transaction extérieure, conserve le refus/échec après rollback et ne conserve pas le message brut de l’exception. Les services HTTP existants gardent leurs propres transactions et stratégies de reprise ; le middleware commun attribue les recherches/consultations/refus manquants et restaure après rollback les événements d’échec tamponnés, sans envelopper toutes les requêtes dans une transaction supplémentaire. Les mutations produit auparavant non auditées sont désormais atomiques avec leur événement. La couverture des futurs stocks, scans, factures et retours reste de la responsabilité de leurs features, à brancher à ce contrat.

L’approbation client et l’accès employé conservent leurs tables d’outbox et leurs commandes existantes. `notification_deliveries` fournit l’outbox typée pour `order.received`, liée à son événement déclencheur et unique par événement/destinataire/type/canal. Le hook de commande persiste l’intention dans la transaction et les replays ne créent pas d’autre intention. **L’envoi des commandes reste désactivé** (`ORDER_RECEIVED_NOTIFICATION_ENABLED=false`) jusqu’à validation du canal et du contenu ; les tests d’envoi l’activent seulement dans leur configuration isolée. La reprise HTTP ne permet pas de l’activer.

Les workers livrent seulement les intentions déjà commitées, sous verrou, et conservent les états/erreurs techniques sans message brut du transport. Une panne de mail conserve l’opération métier et programme un nouvel essai à cinq minutes. Un statut SENT ne peut pas être repris par l’API. Un arrêt entre l’acceptation SMTP et le commit peut produire un autre exemplaire du mail : le message de commande garde le même Message-ID, et aucune reprise ne rejoue la commande. Une acceptation du transport ne prouve pas la réception finale dans une boîte mail.

```sh
# À exécuter sur un environnement dont le transport mail est configuré et autorisé.
php artisan schedule:work
# Ou lancer ponctuellement le traitement souhaité :
php artisan customers:send-approval-notifications --limit=50
php artisan employees:send-access --limit=50
php artisan notifications:deliver --limit=50
```

La planification figure dans `routes/console.php` ; le conteneur web de développement ne lance pas seul le scheduler. Le transport `log` est refusé pour l’accès temporaire et les messages de commande ; les tests utilisent exclusivement le transport `array`. La recette SMTP réelle et le raccordement des capacités FTP-13 restent à qualifier avant de déclarer la story Done.

Les triggers SQLite/MySQL refusent UPDATE et DELETE, y compris via Query Builder ; Eloquent refuse également toute modification/suppression. La migration additive reprend sans effacement un DDL MySQL partiellement appliqué. Compose est explicitement un environnement de développement : `log_bin_trust_function_creators=1` permet d’installer ces triggers avec le propriétaire du schéma sans lui accorder SUPER. En exploitation, l’installation/archivage et les droits DDL relèvent d’un compte technique séparé ; les rôles applicatifs n’ont aucune API de modification du journal. Les protections ne prétendent pas empêcher un administrateur technique de supprimer une table.

Pour une démonstration locale facultative sans compte ni permission créée : `FTP_AUDIT_DEMO=1 php artisan db:seed --class=AuditDemoSeeder --no-interaction`. Ce seeder ajoute 25 événements fictifs, aucun message n’est envoyé. Ne pas confondre ces fixtures ou les Gates de tests avec la validation des rôles réels.

Le journal distingue l’ouverture de la console (`audit.page_view`), la lecture des données (`audit.read`), la préparation Excel (`audit.export`) et le contrôle HEAD (`audit.export_metadata`, sans génération de fichier). Les reprises emploient le même type d’objet que leur envoi : `approval_notification`, `employee_access_delivery`, `notification_delivery`. HEAD du panier/historique conserve un événement de lecture et utilise le budget de lecture.

Le journal utilise une pagination par curseur `(occurred_at, id)` : ses propres événements de lecture et les nouveaux événements ne décalent pas les lignes déjà parcourues. Appliquer les filtres repart de la première page ; un curseur invalide est refusé (422). Les listes d’envoi gardent leur pagination par page.

Vérification du 6 novembre 2026 : Pint et build réussis ; suite Docker SQLite complète, 475 tests / 2364 assertions, six sauts réservés à MySQL/pcntl. Les 82 tests ciblés sur MySQL 8.4 jetable passent (566 assertions), dont ces six cas de concurrence. Le `phpunit.xml` impose SQLite avec `force=true` : une simple variable d’environnement ne suffit pas pour exécuter MySQL. Utiliser une configuration de test distincte sans les entrées `server DB_*` forcées, uniquement contre une base de test jetable ; conserver les autres paramètres testing, mail/cache/session array. Ne pas lancer les migrations destructives des tests contre la base de développement.
