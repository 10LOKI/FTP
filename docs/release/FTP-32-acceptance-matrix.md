# FTP-32 — Matrice de qualification

Cette matrice relie les exigences vérifiables de FTP-32 aux preuves automatisées présentes dans le dépôt. Une ligne n'est qualifiée que si la commande indiquée réussit sur le commit livré. Les éléments dépendant de fonctionnalités non fusionnées restent des bloqueurs de mise en production.

## Matrice REC

| Domaine | Exigences couvertes | Preuves automatisées principales | Environnement requis |
| --- | --- | --- | --- |
| Catalogue, références et confidentialité | REC01–12 | `PublicCatalogueTest`, `ReferenceSearchTest`, `ProductReferenceManagementTest`, `CustomerCommerceSecurityTest` | SQLite + parcours navigateur |
| Comptes, rôles et permissions | REC13–26 | `CustomerRegistrationTest`, `CustomerApprovalDecisionTest`, `EffectivePermissionsTest`, `RolePermissionChangeTest`, `AccessAuthorizationTest` | SQLite + MySQL pour les courses |
| Prix, promotions et panier | REC27–39 | `CustomerPricingTest`, `PromotionManagementTest`, `PromotionConcurrencyTest`, `CartPricingIntegrationTest`, `CustomerOrderTest` | SQLite + MySQL concurrence |
| Audit et notifications durables | REC34–36, REC39 | `AuditTrailTest`, `AuditConcurrencyTest`, `DurableNotificationTest` | SQLite + MySQL concurrence |
| Stock par dépôt | REC40–49 | `WarehouseStockTest`, `WarehouseStockConcurrencyTest` | MySQL obligatoire pour verrouillage |
| Préparation, scan et départ BL | REC50–59 | `DeliveryPreparationTest`, `DeliveryPreparationConcurrencyTest` | MySQL + recette scanner/clavier |
| Retours fournisseur et avoirs | REC60–63 | `SupplierReturnTest` | SQLite + MySQL |
| Exploitation, reprise et sécurité | REC64–69 | suite complète, `docker/backup-database.sh`, `docker/restore-database.sh`, runbook FTP-32 | environnement isolé MySQL |

## Commandes de preuve

```sh
vendor/bin/pint --dirty --format agent
FTP_TEST_PRICING_TIMEZONE=UTC DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpunit
docker compose up -d --build --wait
docker compose exec -T -e DB_DATABASE=ftp32_verification \
  ftp vendor/bin/phpunit --configuration phpunit.mysql.xml \
  tests/Feature/WarehouseStockConcurrencyTest.php \
  tests/Feature/DeliveryPreparationConcurrencyTest.php \
  tests/Feature/PromotionConcurrencyTest.php \
  tests/Feature/RolePermissionConcurrencyTest.php \
  tests/Feature/AuditConcurrencyTest.php
npm run build
```

Les identifiants de la base de test sont fournis par l'environnement local et ne sont jamais inscrits dans cette matrice. La base `ftp_test` doit être isolée de la base de développement.

## Bloqueurs de mise en production

- FTP-28 (paiements, soldes et blocage des impayés) et FTP-29 (retours clients) ne sont pas encore livrés ; leurs REC ne peuvent pas être déclarées intégrées.
- Les preuves FTP-122 du parcours complet réception/réservation/BL dépendent encore de FTP-23, FTP-25 et FTP-27.
- Les cinq modèles PDF demandés ne peuvent être qualifiés qu'après livraison de tous les documents sources ; seuls les modèles actuellement implémentés sont testables.
- La recette matérielle douchette, mobile et clavier ainsi que la formation des utilisateurs exigent un environnement et des participants réels. Un build ou une fixture ne les remplace pas.
- Les notifications de commande restent désactivées tant que le canal et le contenu ne sont pas approuvés.

Ces bloqueurs interdisent le go-live et le passage de FTP-32 à Done. Ils n'empêchent pas de maintenir les tests, sauvegardes et guides à jour.

## Résultats constatés — 10 novembre 2026

| Vérification | Résultat |
| --- | --- |
| Pint | réussi |
| Suite complète dans l'image Docker, SQLite mémoire | 673 tests, 3828 assertions, 13 scénarios MySQL ignorés comme prévu |
| Courses MySQL stock, BL, promotions, audit et commande | 11 tests, 115 assertions, réussis sur `ftp32_verification` |
| Course MySQL permissions | 2 tests, 26 assertions, réussis sur `ftp13_verification` |
| Build Vite | réussi, 29 modules transformés |
| Sauvegarde MySQL et checksum SHA-256 | réussis |
| Restauration dans `ftp_restore_test` | réussie, 31 migrations retrouvées puis base isolée supprimée |

Le PHP installé directement sur le Mac a correctement refusé le calcul commercial avec HTTP 503 car sa base tzdata ne connaît pas encore la transition Casablanca de novembre 2026. Ce résultat fail-closed n'est pas une validation du runtime local : la preuve de livraison est celle de l'image Docker épinglée, dont la suite complète réussit.
