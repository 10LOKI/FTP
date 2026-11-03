# FTP

Environnement de développement Laravel 13 : une seule image finale et un seul
conteneur réunissent PHP 8.4, les dépendances Composer (développement inclus), les
assets Vite compilés et le serveur **MySQL 8.4**. Seuls Docker Desktop avec Compose,
Git et OpenSSL (fourni sur macOS) sont nécessaires sur le Mac.

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

Ouvrir <http://localhost:8090>. Le premier démarrage initialise MySQL, crée la base
`ftp` et le compte applicatif avec `DB_USERNAME` / `DB_PASSWORD`, attend une vraie
connexion de ce compte, puis exécute les migrations avant de lancer HTTP.
L'état `healthy` vérifie à la fois MySQL et le point de santé Laravel `/up`.

Le script `prepare-env.sh` crée `.env` depuis l'exemple si nécessaire et génère
`APP_KEY` seulement si elle est vide ou absente. Il conserve une clé existante et
n'affiche aucune valeur sensible. Ne pas recopier `.env.example` sur un `.env`
déjà configuré. Les mots de passe de l'exemple sont des identifiants locaux ; ils
peuvent être personnalisés dans `.env` **avant le premier démarrage**.
`MYSQL_ROOT_PASSWORD` est le mot de passe d'administration, distinct du compte
applicatif. Le même couple `DB_USERNAME` / `DB_PASSWORD` sert à créer ce compte et
à connecter Laravel.

`.env` est ignoré par Git et exclu du contexte Docker. Compose transmet ses
variables à l'exécution via `env_file` ; aucune clé ni aucun mot de passe local
n'est fourni au build. La clé reste dans le `.env` du Mac après reconstruction.

## Commandes courantes

```sh
# Logs de démarrage, du serveur HTTP et de MySQL
docker compose logs -f --tail=100 ftp

# Logs applicatifs Laravel
docker compose exec ftp tail -f storage/logs/laravel.log

# État des migrations (connexion MySQL de Laravel)
docker compose exec --user www-data ftp php artisan migrate:status

# Client MySQL dans le conteneur : saisir DB_PASSWORD à l'invite
docker compose exec ftp sh -c 'exec mysql --protocol=TCP -h 127.0.0.1 -u "$DB_USERNAME" -p ftp'

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

## Données MySQL

Le volume nommé `ftp_mysql-data` est monté dans `/var/lib/mysql`. Il survit à
`restart`, `stop` / `start`, `down` et aux reconstructions. Un volume existant
n'est pas réinitialisé et ses utilisateurs ne sont pas recréés : conserver les
mêmes identifiants dans `.env`, ou les modifier explicitement dans MySQL avant de
mettre `.env` à jour. Si des variables changent, les appliquer avec
`docker compose up -d --force-recreate` ; `restart` conserve l'ancien environnement.

**`docker compose down -v` supprime la base : ne pas l'utiliser pour arrêter le
projet.** Les anciens volumes Sail et les fichiers SQLite locaux ne sont ni
importés, ni supprimés par cette configuration.

MySQL écoute uniquement sur `127.0.0.1:3306` à l'intérieur du conteneur ; seul le
port HTTP `127.0.0.1:8090` est publié sur le Mac. Le script de démarrage intercepte
les signaux d'arrêt, termine Laravel et ses workers puis attend l'arrêt de MySQL.
Compose lui accorde 90 secondes pour terminer.

## Vérifications

```sh
docker compose config --quiet
docker compose ps
curl --fail --silent --output /dev/null --write-out '%{http_code}\n' http://localhost:8090
docker compose exec --user www-data ftp php artisan migrate:status
docker compose exec ftp php -v
docker compose exec ftp mysqld --version
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

Pour constater la persistance sans toucher aux tables métier, créer une table de
contrôle dans le client MySQL ci-dessus, puis quitter avec `exit` :

```sql
CREATE TABLE IF NOT EXISTS docker_persistence_check (id INT PRIMARY KEY, value VARCHAR(64));
INSERT INTO docker_persistence_check VALUES (1, 'ftp-persistence-ok')
ON DUPLICATE KEY UPDATE value = 'ftp-persistence-ok';
```

Exécuter `docker compose restart`, attendre l'état `healthy`, rouvrir le client et
vérifier la valeur, puis supprimer uniquement cette table de contrôle :

```sql
SELECT * FROM docker_persistence_check;
DROP TABLE docker_persistence_check;
```
