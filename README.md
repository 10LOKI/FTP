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
