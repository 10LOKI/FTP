# Correspondance relationnelle — proposition FTP-34

Version 0.3, source V3 chap.22. Les 62 classes correspondent aux deux diagrammes ; aucun nom/table/type proposé ne vaut migration approuvée.

## Règles communes

- PK technique `id` UUID proposée (stockage CHAR(36) pour lisibilité initiale ; choix définitif D15). Pivots sans id : PK composite explicite.
- FK non nullable par défaut ; exceptions précisées dans le tableau. Dates d’étapes futures et données optionnelles restent nulles jusqu’à leur réalisation. Référentiels obligatoires à confirmer avec le métier.
- Montants/prix proposés DECIMAL(20,4), quantités DECIMAL(20,6), devise CHAR(3). Échelle, bornes, arrondis et unités D03/D07/D08 à approuver avant migration ; jamais FLOAT/DOUBLE pour l’argent.
- Références, codes clients, SKU, codes-barres, numéros document : VARCHAR dimensionné aux données réelles ; zéros initiaux conservés. Pas de normalisation destructive.
- FK indexées ; RESTRICT pour suppression de pièces historiques, utilisateurs, produits et documents liés. Désactivation/archivage plutôt que cascade destructive.
- `created_at`, `updated_at` UTC proposés sur les entités modifiables ; auteur/date métier sur événements. Écritures/audit historiques sans UPDATE destructeur.
- Contraintes simples en base ; invariants de sommes et changements d’état sous transaction/verrou. Une contrainte CHECK seule ne garantit pas la concurrence.
- JSON d’instantanés autorisé pour les valeurs historiques validées, pas pour remplacer une FK relationnelle critique. Audit objet polymorphe : intégrité contrôlée par service et conservation de l’identifiant historique.

| Classe | Table proposée | PK | FK / nullabilité | Unicités et contraintes spécifiques |
|---|---|---|---|---|
| Utilisateur | `users` | id UUID | Aucune FK métier obligatoire identifiée | UNIQUE(email) ; unicité normalisée à valider ; secret haché seulement |
| Rôle | `roles` | id UUID | Aucune FK métier obligatoire identifiée | UNIQUE(code), protection système et dernière capacité administrative sous transaction |
| Permission | `permissions` | id UUID | Aucune FK métier obligatoire identifiée | UNIQUE(code), code système immuable |
| AffectationRôle | `user_roles` | PK composite (user_id,role_id) | user_id → users ; role_id → roles ; auteur_id → users | PK composite (user_id,role_id) |
| RôlePermission | `role_permissions` | PK composite (role_id,permission_id) | role_id → roles ; permission_id → permissions | PK composite (role_id,permission_id) |
| ProfilClient | `customer_profiles` | id UUID | user_id → users (unique) | UNIQUE(user_id), UNIQUE(code_client), code jamais réattribué |
| RestrictionAccès | `access_restrictions` | id UUID | user_id → users ; auteur_id/levée_par → users | Index FK et filtres ; états/valeurs validés côté service |
| DemandeRéactivation | `reactivation_requests` | id UUID | user_id → users ; décideur_id → users nullable tant que non décidé | Index FK et filtres ; états/valeurs validés côté service |
| RemiseAccès | `access_deliveries` | id UUID | user_id → users | Index FK et filtres ; états/valeurs validés côté service |
| ÉvénementAudit | `audit_events` | id UUID | acteur_id → users nullable (système/visiteur) ; objet polymorphe | Append-only ; pas de secret ; lecture/export audités |
| Notification | `notifications` | id UUID | destinataire_id → users ; objet polymorphe | clé événement/destinataire/canal unique ; reprise après commit |
| Produit | `products` | id UUID | brand_id → part_brands ; nullabilité à valider | UNIQUE(SKU) ; coût interne distinct du prix |
| RéférenceProduit | `product_references` | id UUID | product_id → products ; brand_id → part_brands nullable | UNIQUE(product_id,type,code_normalisé,source_normalisée) proposé après D07 ; référence NON globalement unique |
| CodeBarres | `product_barcodes` | id UUID | product_id → products ; packaging_id → product_packagings | Code texte ; unicité et portée packaging à valider D07 |
| Conditionnement | `product_packagings` | id UUID | product_id → products | Index FK et filtres ; états/valeurs validés côté service |
| Catégorie | `categories` | id UUID | parent_id → categories nullable | Index FK et filtres ; états/valeurs validés côté service |
| MarquePièce | `part_brands` | id UUID | Aucune FK métier obligatoire identifiée | Index FK et filtres ; états/valeurs validés côté service |
| CompatibilitéCamion | `truck_compatibilities` | id UUID | Aucune FK métier obligatoire identifiée | Index FK et filtres ; états/valeurs validés côté service |
| ProduitCatégorie | `product_categories` | PK composite (product_id,category_id) | product_id → products ; category_id → categories | PK composite (product_id,category_id) |
| ProduitCompatibilité | `product_compatibilities` | PK composite (product_id,compatibility_id) | product_id → products ; compatibility_id → truck_compatibilities | PK composite (product_id,compatibility_id) |
| Promotion | `promotions` | id UUID | product_id → products ; auteur_id → users | Index FK et filtres ; états/valeurs validés côté service |
| ImageProduit | `product_images` | id UUID | product_id → products | Index FK et filtres ; états/valeurs validés côté service |
| Panier | `carts` | id UUID | customer_id → customer_profiles | Index FK et filtres ; états/valeurs validés côté service |
| LignePanier | `cart_lines` | id UUID | cart_id → carts ; product_id → products ; packaging_id → product_packagings | UNIQUE(cart_id,product_id,packaging_id) ; quantité > 0 |
| Commande | `orders` | id UUID | customer_id → customer_profiles | UNIQUE(numéro), version >= 1 ; idempotence acteur/opération/clé |
| LigneCommande | `order_lines` | id UUID | order_id → orders ; product_id → products ; packaging_id → product_packagings | Index FK et filtres ; états/valeurs validés côté service |
| AppelCommercial | `commercial_calls` | id UUID | order_id → orders ; agent_id → users | Index FK et filtres ; états/valeurs validés côté service |
| HistoriqueCommande | `order_history` | id UUID | order_id → orders ; auteur_id → users | Index FK et filtres ; états/valeurs validés côté service |
| Devis | `quotes` | id UUID | order_id → orders | UNIQUE(numéro) |
| VersionDevis | `quote_versions` | id UUID | quote_id → quotes ; auteur_id → users | UNIQUE(quote_id,version) ; aucune réécriture de l’offre acceptée |
| LigneDevis | `quote_lines` | id UUID | quote_version_id → quote_versions ; order_line_id → order_lines | Index FK et filtres ; états/valeurs validés côté service |
| AccordClient | `customer_agreements` | id UUID | order_id → orders ; quote_version_id → quote_versions nullable si BL direct ; agent_id → users | Index FK et filtres ; états/valeurs validés côté service |
| Entrepôt | `warehouses` | id UUID | Aucune FK métier obligatoire identifiée | Index FK et filtres ; états/valeurs validés côté service |
| Emplacement | `locations` | id UUID | warehouse_id → warehouses | Index FK et filtres ; états/valeurs validés côté service |
| ProduitEmplacement | `product_locations` | PK composite (product_id,location_id) | product_id → products ; location_id → locations | PK composite (product_id,location_id) |
| Stock | `stocks` | id UUID | product_id → products ; warehouse_id → warehouses | UNIQUE(product_id,warehouse_id) ; physique >= 0 ; réservé actif <= physique vendable sous verrou |
| Réservation | `reservations` | id UUID | stock_id → stocks ; order_line_id → order_lines | quantité > 0 ; opération unique ; transition conditionnelle depuis ACTIVE |
| MouvementStock | `stock_movements` | id UUID | stock_id → stocks ; auteur_id → users nullable si système ; transfer_id → stock_transfers nullable | operation_id unique par effet ; écritures signées justifiées, pas de suppression historique |
| TransfertStock | `stock_transfers` | id UUID | dépôt_source_id/dépôt_destination_id → warehouses | source != destination ; effets source/destination équilibrés et liés |
| Livraison / BL | `deliveries` | id UUID | order_id → orders ; warehouse_id → warehouses ; auteur_id → users | UNIQUE(numéro_BL), validation départ unique |
| LigneLivraison | `delivery_lines` | id UUID | delivery_id → deliveries ; order_line_id → order_lines | Index FK et filtres ; états/valeurs validés côté service |
| AllocationRéservationLivraison | `delivery_reservation_allocations` | id UUID | delivery_line_id → delivery_lines ; reservation_id → reservations | UNIQUE(delivery_line_id,reservation_id) ; somme consommée <= réservé |
| ScanPréparation | `preparation_scans` | id UUID | delivery_line_id → delivery_lines ; barcode_id → product_barcodes ; opérateur_id → users | Index FK et filtres ; états/valeurs validés côté service |
| RetourClient | `customer_returns` | id UUID | delivery_id → deliveries ; auteur_id → users | Index FK et filtres ; états/valeurs validés côté service |
| LigneRetourClient | `customer_return_lines` | id UUID | return_id → customer_returns ; delivery_line_id → delivery_lines ; movement_id → stock_movements nullable | Index FK et filtres ; états/valeurs validés côté service |
| Facture | `invoices` | id UUID | customer_id → customer_profiles | UNIQUE(numéro à émission) ; même client pour tous BL affectés |
| LigneFacture | `invoice_lines` | id UUID | invoice_id → invoices | Index FK et filtres ; états/valeurs validés côté service |
| AllocationFacturation | `invoice_allocations` | id UUID | invoice_line_id → invoice_lines ; delivery_line_id → delivery_lines | quantité > 0 ; somme allocations <= expédié net retours non facturés, sous verrou |
| Règlement | `payments` | id UUID | Aucune FK métier obligatoire identifiée | montant > 0 ; operation_id unique ; correction liée et auditée |
| AffectationRèglement | `payment_allocations` | id UUID | payment_id → payments ; entry_id → account_entries | montant > 0 ; somme <= règlement et créance/dette ouverte sous verrou |
| ÉcritureCompte | `account_entries` | id UUID | customer_id → customer_profiles XOR supplier_id → suppliers (un seul non nul) ; origine typée liée | origine et validation obligatoires ; BL→facture transfère la justification, jamais la dette une seconde fois |
| Échéance | `due_dates` | id UUID | entry_id → account_entries | Index FK et filtres ; états/valeurs validés côté service |
| AvoirClient | `credit_notes` | id UUID | invoice_id → invoices ; return_id → customer_returns nullable | UNIQUE(numéro), état et écriture validés une fois |
| LigneAvoirClient | `credit_note_lines` | id UUID | credit_id → credit_notes ; invoice_line_id → invoice_lines ; return_line_id → customer_return_lines nullable | quantité et valeur cumulées <= admissible sur facture/retours, prix historiques |
| AffectationAvoir | `credit_allocations` | id UUID | credit_id → credit_notes ; entry_id → account_entries | montant > 0 ; plafonds avoir et solde ouverts sous verrou |
| Remboursement | `refunds` | id UUID | credit_id → credit_notes nullable ; entry_id → account_entries | montant > 0 ; trésorerie distincte, opération unique |
| Fournisseur | `suppliers` | id UUID | Aucune FK métier obligatoire identifiée | Index FK et filtres ; états/valeurs validés côté service |
| AchatRéférence | `purchases` | id UUID | supplier_id → suppliers | Index FK et filtres ; états/valeurs validés côté service |
| LigneAchat | `purchase_lines` | id UUID | purchase_id → purchases ; product_id → products | Index FK et filtres ; états/valeurs validés côté service |
| RetourFournisseur | `supplier_returns` | id UUID | supplier_id → suppliers ; auteur_id → users | Index FK et filtres ; états/valeurs validés côté service |
| LigneRetourFournisseur | `supplier_return_lines` | id UUID | supplier_return_id → supplier_returns ; purchase_line_id → purchase_lines ; movement_id → stock_movements nullable avant départ | Index FK et filtres ; états/valeurs validés côté service |
| AvoirFournisseur | `supplier_credits` | id UUID | supplier_return_id → supplier_returns nullable ; entry_id → account_entries | pièce reçue ou ajustement explicitement validé requis ; effet financier unique |

## Attributs métier par table

Les attributs suivants reprennent le diagramme ; les colonnes de FK du tableau priment sur les libellés abrégés.

### Utilisateur — `users`

id : UUID [proposition] ; email : texte unique ; password_hash : texte ; actif : bool / last_login_at ; changement_obligatoire : bool

### Rôle — `roles`

id / code unique / libellé ; protégé : bool ; version : entier

### Permission — `permissions`

id / code stable unique ; module / libellé ; protégée : bool

### AffectationRôle — `user_roles`

user_id FK / role_id FK ; auteur_id FK / date ; unique(user_id, role_id)

### RôlePermission — `role_permissions`

role_id FK / permission_id FK ; unique(role_id, permission_id)

### ProfilClient — `customer_profiles`

id / user_id FK unique ; code_client : texte unique non réattribué ; type : PARTICULIER | ENTREPRISE ; coordonnées / société / remarques_privées ; approbation / mode_paiement / délai

### RestrictionAccès — `access_restrictions`

id / user_id FK ; motif : inactivité | impayé ; date / état / auteur / motif_levée

### DemandeRéactivation — `reactivation_requests`

id / user_id FK ; date / état / motif ; décideur_id FK / décision_at

### RemiseAccès — `access_deliveries`

id / user_id FK ; expiration_secret / hachage_temporaire ; état_envoi / tentatives ; aucun secret en clair conservé durablement

### ÉvénementAudit — `audit_events`

id / acteur_id nullable ; objet_type / objet_id / requête_id ; résultat / date / avant_après_filtrés ; aucun secret / immuable

### Notification — `notifications`

id / destinataire_id FK ; objet / événement / clé_unique ; état / tentatives / prochain_essai

### Produit — `products`

id / SKU : texte unique ; désignation / publication ; code_SH : texte / poids / unité ; prix_base : decimal / devise ; coût_achat : decimal privé

### RéférenceProduit — `product_references`

id / product_id FK ; type : ORIGINALE | CROISEE ; code_brut / code_normalisé : texte ; source / marque

### CodeBarres — `product_barcodes`

id / product_id FK ; code : texte ; packaging_id FK

### Conditionnement — `product_packagings`

id / product_id FK ; libellé / unité_vente ; multiplicateur : decimal positif

### Catégorie — `categories`

id / parent_id nullable ; libellé

### MarquePièce — `part_brands`

id / nom

### CompatibilitéCamion — `truck_compatibilities`

id / marque_camion ; modèle / moteur

### ProduitCatégorie — `product_categories`

product_id FK / category_id FK ; unique(produit, catégorie)

### ProduitCompatibilité — `product_compatibilities`

product_id FK / compatibility_id FK ; unique(produit, compatibilité)

### Promotion — `promotions`

id / product_id FK ; début / fin / état ; prix_ou_remise / priorité ; auteur_id FK

### ImageProduit — `product_images`

id / product_id FK ; chemin / ordre / description

### Panier — `carts`

id / customer_id FK ; version

### LignePanier — `cart_lines`

id / cart_id FK / product_id FK ; packaging_id FK / quantité positive ; unique(panier, produit, conditionnement)

### Commande — `orders`

id / customer_id FK / numéro unique ; état / version / idempotency_key ; adresses et conditions historiques ; parcours : devis | BL direct

### LigneCommande — `order_lines`

id / order_id FK / product_id FK ; packaging_id FK / qté_demandée ; qté_confirmée / qté_annulée ; SKU/libellé/prix/remise historiques ; devise / unité historiques

### AppelCommercial — `commercial_calls`

id / order_id FK / agent_id FK ; date / mode_contact / résultat ; notes autorisées

### HistoriqueCommande — `order_history`

id / order_id FK / auteur_id FK ; ancien_état / nouvel_état ; date / motif / version

### Devis — `quotes`

id / order_id FK ; numéro : texte unique

### VersionDevis — `quote_versions`

id / quote_id FK / version unique ; état / date / validité / auteur_id FK ; identités et conditions historiques ; totaux : decimal / devise

### LigneDevis — `quote_lines`

id / quote_version_id FK ; order_line_id FK ; produit/qté/prix/remise historiques ; montants : decimal / devise

### AccordClient — `customer_agreements`

id / order_id FK ; quote_version_id nullable FK ; agent_id FK / date / mode_contact ; offre_directe_snapshot si sans devis ; éligibilité et motif BL direct

### Entrepôt — `warehouses`

id / code unique / adresse

### Emplacement — `locations`

id / warehouse_id FK ; rayon / niveau / code

### ProduitEmplacement — `product_locations`

product_id FK / location_id FK ; unique(produit, emplacement)

### Stock — `stocks`

id / product_id FK / warehouse_id FK ; physique_vendable / quantité_contrôle ; seuil_minimum / version ; unique(produit, entrepôt)

### Réservation — `reservations`

id / stock_id FK / order_line_id FK ; quantité / état / expiration ; operation_id unique ; ACTIVE | LIBEREE | CONSOMMEE | EXPIREE

### MouvementStock — `stock_movements`

id / stock_id FK / auteur_id FK ; variation_physique / variation_réservée ; cause / date / operation_id unique ; transfer_id nullable FK

### TransfertStock — `stock_transfers`

id / dépôt_source_id FK ; dépôt_destination_id FK ; état / operation_id unique

### Livraison / BL — `deliveries`

id / order_id FK / warehouse_id FK ; numéro_BL unique / état ; départ_at / réception_at / auteur_id FK

### LigneLivraison — `delivery_lines`

id / delivery_id FK / order_line_id FK ; qté_préparée / expédiée / livrée ; historique prix/conditions

### AllocationRéservationLivraison — `delivery_reservation_allocations`

delivery_line_id FK / reservation_id FK ; quantité / opération unique

### ScanPréparation — `preparation_scans`

id / delivery_line_id FK ; barcode_id FK / opérateur_id FK ; date / quantité / résultat

### RetourClient — `customer_returns`

id / delivery_id FK ; auteur_id FK / date / motif / état

### LigneRetourClient — `customer_return_lines`

id / return_id FK / delivery_line_id FK ; quantité_reçue / contrôlée ; décision_réintégration ; movement_id nullable FK

### Facture — `invoices`

id / customer_id FK / numéro unique ; état / date_émission / version ; identités/conditions historiques ; totaux decimal / devise

### LigneFacture — `invoice_lines`

id / invoice_id FK ; produit/qté/prix/remise historiques ; taxes / montants decimal / devise

### AllocationFacturation — `invoice_allocations`

id / invoice_line_id FK ; delivery_line_id FK / quantité ; operation_id unique

### Règlement — `payments`

id / date / mode / référence ; montant decimal / devise ; operation_id unique / correction_liée

### AffectationRèglement — `payment_allocations`

id / payment_id FK / entry_id FK ; montant decimal / devise

### ÉcritureCompte — `account_entries`

id / customer_id ou supplier_id FK ; origine_document / origine_écriture ; débit/crédit decimal / devise ; date / validation / opération unique

### Échéance — `due_dates`

id / entry_id FK / date_exigibilité ; origine_conditions_snapshot ; rappels / montant_ouvert calculé

### AvoirClient — `credit_notes`

id / invoice_id FK / return_id nullable FK ; numéro / état / auteur / date ; montant decimal / devise

### LigneAvoirClient — `credit_note_lines`

id / credit_id FK / invoice_line_id FK ; return_line_id nullable FK ; quantité/prix/remise historiques ; montant decimal / devise

### AffectationAvoir — `credit_allocations`

id / credit_id FK / entry_id FK ; montant decimal / devise

### Remboursement — `refunds`

id / credit_id nullable FK ; entry_id FK / référence / date ; montant decimal / devise ; operation_id unique

### Fournisseur — `suppliers`

id / code / coordonnées ; solde calculé depuis écritures

### AchatRéférence — `purchases`

id / supplier_id FK / pièce_origine ; date / devise / auteur_id FK

### LigneAchat — `purchase_lines`

id / purchase_id FK / product_id FK ; quantité / coût_net_historique decimal

### RetourFournisseur — `supplier_returns`

id / supplier_id FK / auteur_id FK ; numéro / motif / date / départ ; réception / acceptation_financière

### LigneRetourFournisseur — `supplier_return_lines`

id / supplier_return_id FK ; purchase_line_id FK / quantité ; coût_net_historique / movement_id FK

### AvoirFournisseur — `supplier_credits`

id / supplier_return_id nullable FK ; pièce_reçue ou ajustement_validé ; montant decimal / devise / date ; entry_id FK / operation_id unique

## Compléments techniques transversaux proposés

Une table `idempotency_operations` (hors classes métier) conserve acteur, type d’opération, clé, empreinte, état, résultat et lien d’objet, avec unicité de portée. La file durable de notifications s’appuie sur `notifications` ; le transport de queue reste à choisir. Les contraintes uniques doivent gérer les NULL explicitement, notamment source de référence : utiliser une clé canonique non nulle approuvée plutôt qu’une unicité SQL contournable par NULL.

## Points bloquant une migration définitive

Décider portée d’un panier actif, cycle des identifiants, normalisation référence/barcode (D07), précision et règles d’arrondi (D03/D08), unités, origine des échéances et modèle de répartition financière (D04/D20). Le propriétaire client est toujours déduit de la commande/ligne, pas stocké dans une deuxième FK modifiable sans contrainte de cohérence. Vérifier achat/retour fournisseur appartenant au même fournisseur, livraison/ligne appartenant à la même commande et lignes de BL facturées au même client.
