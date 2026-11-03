# Contrats et transitions — proposition de revue FTP-35

Version 0.3, 3 novembre 2026. Source métier : cahier V3, chapitres 6–8, 11–20 et 23. Les routes ci-dessous sont des propositions, pas des endpoints déjà implémentés. Aucun choix technique ouvert n’est présenté comme approuvé.

## Frontières et conventions

| Module | Responsabilité | Contrat partagé |
|---|---|---|
| Identité | Authentification, approbation, restrictions, rôles | Identifiant utilisateur/client, permissions effectives ; restriction par motif |
| Catalogue | Produit canonique, références, conditionnements, prix autorisés | Identifiant produit/conditionnement ; référence brute/normalisée ; DTO public distinct du DTO interne |
| Vente | Panier, commande, appels, offre et accord | Identifiant commande/ligne/version ; instantanés historiques |
| Logistique | Stock par dépôt, réservations, préparation, départ et retour | Identifiants dépôt/stock/réservation/ligne BL ; opérations atomiques |
| Finance | Affectations BL/facture, règlements, avoirs, créances | Identifiant écriture et document d’origine ; aucune double créance |
| Audit / notification | Événements durables et remise après commit | Identifiant requête/opération/objet ; aucun secret dans l’événement |

Proposition : `/api/v1`, JSON UTF-8, identifiants techniques UUID opaques et codes métier en chaînes distinctes. Ne jamais convertir une référence en nombre. Les montants et quantités décimales circulent comme chaînes ; la devise est explicite, sans supposer MAD/HT/TTC tant que D03/D08 ne sont pas approuvés. Dates ISO 8601 avec décalage ; stockage UTC proposé, fuseau métier D18 à valider.

Authentification React/Laravel : proposition session sécurisée avec protection CSRF si même domaine ; décision de déploiement/authentification à confirmer. Ne pas concevoir de jeton persistant navigateur sans décision explicite. Réévaluer activité, approbation, restrictions et droits à chaque requête, y compris après retrait de rôle.

Listes : `page`, `per_page` borné à 100 (proposition), tri autorisé et filtres en liste blanche. Réponse `data`, `meta.page`, `meta.per_page`, `meta.total`. Le client ne choisit jamais un propriétaire à la place du serveur. Un document hors périmètre retourne 404 selon la politique proposée pour limiter les fuites interclients ; 403 pour une action interdite sur un objet visible.

## Écritures et erreurs

Sur les commandes critiques, en-tête `Idempotency-Key` obligatoire. Unicité proposée `(acteur, type_opération, clé)` ; empreinte de la requête canonique conservée. Même clé + même payload = même résultat après recontrôle d’accès ; même clé + payload différent = 409. Durée de rétention et mécanisme de purge à décider, sans permettre une répétition tardive d’un effet financier ou de stock.

Proposition de concurrence optimiste : version entière dans chaque agrégat et `expected_version` obligatoire. Une version périmée produit 409 avec demande de relecture ; jamais d’écrasement silencieux. Pour le stock : transaction avec verrouillage des stocks dans un ordre stable `(entrepôt, produit)`, puis réservations/lignes concernées ; relecture et contrôles sous verrou. Reprises d’interblocage bornées (nombre à convenir), jamais un retry aveugle d’un effet déjà confirmé.

| HTTP | Code métier proposé | Traitement |
|---|---|---|
| 401 | UNAUTHENTICATED | Connexion requise |
| 403 | FORBIDDEN / ACCOUNT_RESTRICTED | Droit, approbation ou restriction ; pas de données privées dans le message |
| 404 | NOT_FOUND | Objet absent ou hors périmètre |
| 409 | STALE_VERSION / STATE_CONFLICT / INSUFFICIENT_STOCK / IDEMPOTENCY_CONFLICT | Relire, afficher le conflit, ne pas rejouer automatiquement un choix métier |
| 422 | VALIDATION_FAILED | Erreurs par champ, saisies non sensibles conservées |
| 429 | RATE_LIMITED | Attendre selon Retry-After |
| 500/503 | INTERNAL_ERROR / TEMPORARILY_UNAVAILABLE | Message neutre et request_id, pas de SQL ni de secret |

```json
{"error":{"code":"STALE_VERSION","message":"La commande a changé. Rechargez-la.","fields":{},"request_id":"req-exemple"}}
```

Succès critique : écritures métier et audit de réussite atomiques ; notifications préparées durablement et remises après commit. Échec/refus : événement sans secret enregistré après rollback. La panne mail ne transforme pas un succès métier en échec de commande.

## Routes minimales proposées et permissions

| Méthode / route | Permission / portée | Contrôle clé |
|---|---|---|
| POST /customer-registrations | Public | Types client seuls ; pas de rôle injecté |
| POST /customers/{id}/approval-decisions | CUSTOMER_APPROVE | Compte en attente, code unique, auteur et motif |
| POST /users | USER_CREATE + USER_ASSIGN_ROLE si affectation | Secret généré uniquement ; envoi au titulaire |
| POST /users/{id}/access-reissues | USER_RESET_ACCESS | Ancien secret invalidé ; même compte |
| GET /roles/{id}/permission-change-preview | ROLE_MANAGE | Ajouts/retraits, comptes concernés, version |
| PUT /roles/{id}/permissions | ROLE_MANAGE | Confirmation sur version, protection dernier administrateur |
| DELETE /roles/{id} | ROLE_DELETE | Non protégé et non attribué, même compte suspendu |
| GET /products?reference=… | Public ou client | Publication et visibilité D02 ; résultats dédupliqués |
| POST/PATCH /products et /products/{id}/publication | PRODUCT_CREATE / PRODUCT_UPDATE / PRODUCT_PUBLISH | Références et tarifs avec leurs droits distincts |
| PUT /me/cart/lines/{product_id} | CART_MANAGE_OWN | Conditionnement, qté positive, aucun prix accepté du navigateur |
| POST /me/orders | ORDER_CREATE_OWN | Adresses, panier serveur, état compte, idempotence |
| GET /me/orders et /me/orders/{id} | ORDER_VIEW_OWN | Propriétaire serveur |
| POST /orders/{id}/take-charge et /calls | ORDER_REVIEW | Version et transition valides |
| POST /orders/{id}/quotes et /quotes/{id}/versions | QUOTE_MANAGE | Nouvelle offre si révision acceptée |
| POST /orders/{id}/agreements | QUOTE_MANAGE et habilitation commerciale convenue | Offre exacte, agent/contact/date ; pas d’acceptation en ligne implicite |
| POST /orders/{id}/confirmations | ORDER_CONFIRM | Accord/version, client actif, crédit, réservation atomique |
| POST /reservations/{id}/cancellations | RESERVATION_CANCEL | Active, avant sortie, annulation/révision liée |
| POST /backorder-allocations | Habilitation à confirmer D17 | Même ligne et client, stock réel ; priorité validée |
| POST /deliveries/{id}/scans et /preparation-validations | DELIVERY_MANAGE | Bon article/unité/quantité, opérateur/date |
| POST /deliveries/{id}/dispatches | DELIVERY_MANAGE | Réservation valide, sortie unique du dépôt |
| POST /invoices | INVOICE_CREATE | BL du même client, quantités encore facturables verrouillées |
| POST /payments | PAYMENT_RECORD | Montant/devise, affectation, correction liée et idempotence |
| POST /customer-returns | RETURN_MANAGE | Livré admissible, contrôle physique |
| POST /credit-notes | CREDIT_NOTE_MANAGE | Facture et prix historiques ; pas de remboursement implicite |
| POST /supplier-returns | SUPPLIER_RETURN_MANAGE | Achat/coût d’origine, sortie et acceptation financière séparées |
| POST /customers/{id}/credit-unblocks | ADMIN_CREDIT_UNBLOCK + habilitation protégée | Motif et situation de dette ; autres restrictions maintenues |
| POST /documents/{id}/cancellations | ADMIN_DOCUMENT_CANCEL + habilitation protégée | Dépendances, confirmation, conservation et compensation |
| GET /documents/{id}/download | DOCUMENT_VIEW_OWN ou DOCUMENT_VIEW_ALL | Propriété/périmètre contrôlés avant accès privé |
| GET /audit-events et /audit-events/export | AUDIT_READ / AUDIT_EXPORT séparés | Lecture/export eux-mêmes audités ; aucune route d’effacement |

## Exemples de contrats

Les valeurs sont fictives ; les identifiants abrégés sont des exemples lisibles à remplacer par des UUID dans les fixtures validées.

```json
POST /me/orders
Idempotency-Key: checkout-exemple-1
{"cart_version":4,"billing_address":{"city":"Casablanca","country":"MA","address":"Adresse exemple"},"shipping_address":{"city":"Casablanca","country":"MA","address":"Adresse exemple"},"contact_phone":"EXEMPLE","notes":"Confirmer les références par téléphone"}
```

```json
201
{"data":{"id":"order-example","number":"FORMAT_A_VALIDER_D03","state":"SOUMISE","version":1,"lines":[{"id":"line-example","product_id":"product-example","sku":"0343012","quantity_requested":"15"}],"message":"Demande reçue ; vérification et confirmation humaines nécessaires."},"request_id":"req-exemple"}
```

```json
POST /orders/order-example/confirmations
Idempotency-Key: confirm-exemple-1
{"expected_version":3,"agreement_id":"agreement-example","allocations":[{"order_line_id":"line-example","warehouse_id":"warehouse-example","quantity":"10"}],"accepted_backorders":[{"order_line_id":"line-example","quantity":"5"}]}
```

Le serveur refuse toute répartition supérieure à la ligne acceptée ou au disponible et exige l’accord explicite sur le reliquat. Une réservation validée ne diminue pas le physique ; l’expédition est une opération distincte. La réponse expose réservé/reliquat/état/version, mais jamais le coût d’achat au client.

Exemple produit public : `id`, `sku`, `designation`, références autorisées et images. `purchase_cost`, notes internes et emplacements ne sont jamais sérialisés dans ce DTO. Les prix/disponibilités publics attendent D02 ; le DTO client est construit par liste blanche après approbation.

## Transitions et gardes (table, sans troisième diagramme)

| Objet | Départ → arrivée | Garde / effet |
|---|---|---|
| Approbation client | EN_ATTENTE → APPROUVE / REFUSE | CUSTOMER_APPROVE, auteur/date/motif ; aucune levée des autres restrictions |
| Accès initial | Secret temporaire valide → changement obligatoire → accès métier conditionnel | Secret remplacé, sessions initiales invalidées ; compte actif et droits requis |
| Inactivité | Actif → suspendu pour inactivité → demande restreinte → réactivation administrative | Calcul quatre mois D19 ; dernier administrateur protégé ; impayé indépendant |
| Commande | SOUMISE → EN_VERIFICATION | Prise en charge autorisée et version courante |
| Commande | EN_VERIFICATION → ATTENTE_ACCORD | Offre identifiable ; devis ou parcours direct avec éligibilité D23 |
| Commande | ATTENTE_ACCORD → CONFIRMEE | Accord exact, compte éligible, réservation et reliquat acceptés dans une transaction |
| Commande | CONFIRMEE → EN_PREPARATION | Quantités à préparer réellement réservées |
| Commande | EN_PREPARATION → PARTIELLEMENT_LIVREE / EXPEDIEE | État agrégé calculé depuis les lignes et BL ; expédié et reçu distincts. Préciser la priorité d’affichage de l’état partiel en revue. |
| Commande | EXPEDIEE → LIVREE | Réception confirmée ; pas d’effacement si retour ultérieur |
| Commande | État pré-sortie autorisé → ANNULEE | ORDER_CANCEL, motif, compensation des réservations atomique ; états exacts D06 |
| Devis version | BROUILLON → ENVOYE → ACCEPTE / REFUSE / EXPIRE | Offre chiffrée historique ; validité D03 ; révision = nouvelle version, nouvel accord |
| Réservation | ACTIVE → LIBEREE | RESERVATION_CANCEL + annulation/révision liée ; réservé diminue, physique inchangé |
| Réservation | ACTIVE → CONSOMMEE | Départ réel lié au BL ; physique et réservé diminuent une fois |
| Réservation | ACTIVE → EXPIREE | Durée et traitement commande D05 avant activation de la tâche |
| Livraison | A_PREPARER → PREPAREE → EXPEDIEE → LIVREE / ECHEC | Scan et préparation distincts du départ ; échec sans retour automatique en stock |
| Facture | BROUILLON → EMISE | Numéro unique, allocations lignes BL dans même transaction, snapshots |
| Document émis | Émis → correction/annulation tracée | Habilitation protégée, dépendances et compensations ; procédure D03/D06/D20 |
| Paiement (état calculé) | NON_REGLE / PARTIELLEMENT_REGLE / REGLE | Somme des écritures valides ; indépendant de la livraison |
| Retour client | Reçu → contrôlé → réintégrable ou hors disponible | Plafond livré net ; avoir financier distinct, facture d’origine requise pour avoir |
| Retour fournisseur | Brouillon → départ → réception → décision financière | Avoir reçu/ajustement validé seul modifie la dette, pas le simple départ |
| Impayé | Échéance dépassée non soldée → blocage → déblocage administratif motivé | Paiement ne débloque pas seul ; consultation des propres documents maintenue |

Les états du cahier sont une base ; aucun ensemble d’enums n’est figé avant revue. Les commandes partiellement livrées se terminent sur la même commande après réception/allocation ou annulation autorisée du restant.

## Scénarios de validation de contrats à prévoir à l’implémentation

Isolation interclients, droit retiré avec session ouverte, compte suspendu ou impayé, secret temporaire et route métier, version périmée, clé rejouée/payload différent, dernière unité confirmée simultanément, annulation/expédition en course, même BL facturé simultanément, notification après commit en panne, interblocage et reprise bornée. Critères associés : REC07–REC17, REC19–REC23, REC25–REC26, REC31–REC36, REC38–REC42, REC53–REC69. Ce document ne prétend pas que ces tests existent déjà.
