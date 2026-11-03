# Conception FTP — v0.3 pour revue

Source : Cahier_des_charges_Pieces_Camions_V3.pdf, version 3.0 du 01/11/2026, 38 pages. Tickets : FTP-9, FTP-33, FTP-34. Auteur de la première proposition : Ayoub / assistance Codex. Aucun choix ouvert n’est considéré approuvé.

## Ouvrir et modifier

Ouvrir https://app.diagrams.net/ puis « Ouvrir un diagramme existant » et choisir FTP-9-conception-v0.1.drawio. Le fichier contient exactement 2 onglets modifiables : « Use Case diagramme » (UC01–UC16 et acteurs) et « Diagramme de classes » (62 classes et 84 associations). Les cinq domaines sont réunis sur un seul grand canevas de classes ; utiliser le zoom pour lire les attributs et opérations. Exporter en SVG/PDF depuis Fichier > Exporter pour partager une image lisible.

## Lecture du modèle

Les exports vectoriels lisibles sont disponibles dans [use-case.svg](use-case.svg) et [classes.svg](classes.svg). Le diagramme de classes utilise un grand canevas : ouvrir le SVG dans le navigateur et zoomer pour lire les attributs. Les cardinalités sont placées aux deux extrémités de chaque association. Le fichier Draw.io conserve exactement deux pages ; son nom historique v0.1 est maintenu pour préserver les liens, son contenu porte la version v0.3.

## Dossier de revue

- [SCENARIOS-UC.md](SCENARIOS-UC.md) : 16 scénarios structurés, permissions et critères de recette.
- [MAPPING-DONNEES.md](MAPPING-DONNEES.md) : 62 correspondances classe/table, PK/FK, nullabilité, unicités et contraintes proposées.
- [CONTRATS-ET-TRANSITIONS.md](CONTRATS-ET-TRANSITIONS.md) : frontières de modules, conventions API, exemples, erreurs et transitions gardées.
- [ECRANS-ET-REVUE.md](ECRANS-ET-REVUE.md) : neuf croquis fonctionnels, responsive, baseline observée, exclusions et feuille de revue collective.
- [DECISIONS-D01-D23.md](DECISIONS-D01-D23.md) : registre des décisions, responsables à désigner, état et preuve ; disponibilité réelle de l’équipe à renseigner.
- [ETAT-FTP-9.md](ETAT-FTP-9.md) : bilan de couverture et validations restantes.

## Conventions de lecture

Les libellés français décrivent le métier ; les noms de tables anglais et UUID sont des propositions techniques, pas des migrations existantes. Les cardinalités UML sont écrites près des deux extrémités de chaque association ; les contraintes complémentaires figurent dans le mapping. Chaque classe métier apparaît une seule fois dans le diagramme global. Les lignes de panier, commande, devis, livraison, retour, facture, avoir et achat sont des classes distinctes. Les noms de tables restent à fixer avant les migrations. Une association sans flèche n’indique pas une dépendance de navigation.

Chaque opération interne exige les permissions effectives, le périmètre de données et les conditions métier ; le nom du rôle seul ne suffit pas. Les regroupements d’acteurs sont des profils habilités, pas des permissions automatiques. Les associations UC représentent la participation, pas une succession temporelle. Les UC complets ci-dessous décrivent les étapes internes ; aucune relation include/extend artificielle n’est ajoutée.

## Portée de cette première version

Couverture : UC01–UC16 exacts (p.28–31), modèle logique (p.26–27), contrôles et invariants principaux. Pas de preuve de revue équipe ni de validation métier. Les livrables de préparation FTP-33/34/35 sont présents en version de revue ; les approbations métier et de l’équipe ne sont pas encore enregistrées. Aucune migration n’est générée à partir de ce brouillon.

## Carte des cas et permissions

| Cas | Fonction | Acteur | Exigences / permissions |
|---|---|---|---|
| UC01 | Demander un compte client | Visiteur | CLI-01–05,07 / PUB-04 |
| UC02 | Approuver un client | Responsable habilité | CLI-03–04 / CUSTOMER_APPROVE |
| UC03 | Créer un compte et transmettre son accès | Administrateur habilité | USR-01–11 / USER_CREATE + USER_ASSIGN_ROLE |
| UC04 | Modifier les permissions du rôle | Responsable habilité | ACL-01–05 / ROLE_MANAGE |
| UC05 | Supprimer un rôle ordinaire | Responsable habilité | ROLE_DELETE / protections chap. 8 |
| UC06 | Publier une pièce et ses références croisées | Catalogue habilité | PRODUCT_CREATE / REFERENCE_MANAGE / PRODUCT_PUBLISH |
| UC07 | Rechercher et soumettre une commande | Client approuvé | CART_MANAGE_OWN / ORDER_CREATE_OWN |
| UC08 | Appeler, proposer et confirmer | Commercial habilité | ORDER_REVIEW / QUOTE_MANAGE / ORDER_CONFIRM |
| UC09 | Annuler une réservation active | Utilisateur habilité | RESERVATION_CANCEL / TRX-01 |
| UC10 | Préparer et expédier | Magasinier habilité | DELIVERY_MANAGE / STK / REC55–56 |
| UC11 | Facturer et enregistrer un paiement | Facturation habilitée | INVOICE_CREATE / PAYMENT_RECORD |
| UC12 | Enregistrer un retour physique | Retour / facturation habilités | RETURN_MANAGE / CREDIT_NOTE_MANAGE |
| UC13 | Consulter le journal des opérations | Lecteur d’audit habilité | AUDIT_READ / AUDIT_EXPORT séparé |
| UC14 | Reprendre un reliquat à réception | Commercial habilité | STK-10–11 / REC59–60 |
| UC15 | Retourner une pièce au fournisseur | Agent fournisseur + magasin habilités | SUPPLIER_RETURN_MANAGE / AVO-03–04 |
| UC16 | Suivre une échéance et lever un blocage | Facturation + administrateur habilités | PAY-03… / ADMIN_CREDIT_UNBLOCK |

## Contraintes à conserver

- Identifiants produits/références/codes-barres en texte ; montants decimal et devise explicite, précision à valider.
- Stock unique par produit et dépôt ; références alternatives sans stock indépendant.
- Accord et documents historiques conservés ; aucune réécriture silencieuse.
- Unicité d’opération, verrous/version et transaction sur réservation, annulation, sortie et facturation.
- Audit durable sans secrets ; échec/refus conservé même après rollback métier.
- Propriété client vérifiée pour commandes, documents, téléchargements et exports.
- Pas de seconde sortie de stock à la facturation ; pas de double créance BL/facture.

## Décisions métier

Toutes les décisions D01–D23 sont en attente de validation formelle dans cette version. Leurs contraintes déjà imposées restent applicables ; seuls les paramètres ouverts doivent être décidés. Responsables et dates de validation : à désigner avec l’équipe et l’entreprise. Voir les extraits source dans SCENARIOS-SOURCE.md.

## Prochain checkpoint de revue

1. Ayoub vérifie les diagrammes avec Mehdi (Khalid en remplacement).
2. Confirmer les cardinalités, le modèle détaillé d’affectation financière et les permissions d’UC14 selon D17.
3. Revoir les classes détaillées de retour/avoir/achat et écritures ; valider les clés, contraintes et précisions des montants.
4. Valider les décisions D01–D23 et compléter les écrans/contrats API sans transformer une proposition en règle approuvée.
5. Enregistrer les remarques et la preuve de revue avant commit/push de checkpoint.
