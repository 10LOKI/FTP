# FTP-32 — Guide d'exploitation et de reprise

## Préparation d'une livraison

1. Partir d'un commit identifié et vérifier que le dépôt ne contient aucun secret.
2. Exécuter Pint, la suite SQLite, les tests de concurrence MySQL et le build Vite indiqués dans la matrice de qualification.
3. Vérifier les parcours avec les rôles réels. Aucun compte de test, Gate permissive ou fixture ne constitue une preuve d'autorisation en production.
4. Produire une sauvegarde et vérifier son checksum avant toute migration.
5. Bloquer la livraison si un test échoue ou si un bloqueur de la matrice reste applicable.

## Sauvegarde

Depuis la racine du dépôt, avec les services Compose sains :

```sh
sh docker/backup-database.sh
```

Le script utilise une transaction cohérente MySQL, n'affiche aucun mot de passe, crée un fichier SQL en permission `600` et son checksum SHA-256 dans `storage/backups`. Copier les deux fichiers vers le stockage de sauvegarde approuvé. Ils contiennent des données privées et ne doivent jamais être ajoutés à Git.

## Test de restauration isolé

Ne jamais tester une restauration sur la base de développement ou de production. Démarrer un environnement Compose isolé avec un projet, des ports, un volume et une base dédiés, puis y copier le fichier SQL et son checksum.

```sh
sh docker/restore-database.sh --confirm-replace storage/backups/ftp-YYYYMMDDTHHMMSSZ.sql ftp_restore_test
```

Le script refuse l'exécution sans confirmation explicite, vérifie le checksum, restaure la base configurée et affiche l'état des migrations. Après restauration :

1. vérifier le nombre de lignes des tables métier critiques par rapport à la source ;
2. exécuter les smoke tests de catalogue, authentification, stock, BL, audit et documents ;
3. confirmer qu'aucun worker ou email réel n'est activé dans l'environnement isolé ;
4. consigner l'identifiant du commit, l'heure, le fichier, le checksum et les résultats.

## Rollback

Si la migration échoue, arrêter les écritures, conserver les logs et ne pas relancer aveuglément. Revenir à l'image applicative du commit précédent et restaurer la sauvegarde pré-migration dans un nouvel environnement isolé. Valider les smoke tests avant de rediriger le trafic. Une migration destructive ou non réversible exige une procédure spécifique revue avant livraison.

## Sécurité et transfert

- Les exports, PDF, HTML, JSON et logs destinés aux clients ne doivent contenir ni coût/EUR interne, ni secret, ni mot de passe.
- Les mots de passe restent des hash non réversibles ; aucun rôle ne reçoit leur valeur en clair.
- Les changements de permission et les opérations stock/finance restent contrôlés côté serveur et audités sans secret.
- Le responsable d'exploitation reçoit la matrice, ce runbook, les variables requises sans leurs valeurs, les contacts d'escalade et une démonstration de restauration.
