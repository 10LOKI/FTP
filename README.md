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
