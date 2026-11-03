# UC01–UC16 — scénarios structurés pour revue FTP-33

Version 0.3. Source : cahier V3 pp.28–31 et exigences citées. Ces scénarios formalisent le texte fourni ; les paramètres ouverts D01–D23 ne sont pas approuvés par cette rédaction. Préconditions et permissions sont revérifiées au moment de l’écriture, pas uniquement à l’ouverture de l’écran.

## UC01 — Demander un compte client

- **Acteur / permission :** visiteur ; accès public, aucun rôle interne sélectionnable.
- **Préconditions :** formulaire disponible ; type Particulier ou Entreprise ; champs requis selon CLI-01/02, identifiants entreprise D10 à préciser.
- **Déroulement :** choisir type, saisir coordonnées et mot de passe personnel, envoyer ; serveur valide formats/unicité, crée compte et profil EN_ATTENTE, enregistre l’audit.
- **Variantes :** doublon e-mail ou saisie invalide → erreurs de champs sans compte supplémentaire ; rôle/approbation injectés → refus ou champs ignorés sans droit acquis.
- **Postconditions :** demande identifiable ; commandes bloquées ; secret uniquement haché.
- **Trace :** CLI-01–05/07, SEC-02, REC07–08/46 ; D10/D21.

## UC02 — Approuver un client

- **Acteur / permission :** responsable disposant de CUSTOMER_APPROVE.
- **Préconditions :** demande visible et en attente, version courante, responsable actif et autorisé.
- **Déroulement :** examiner la demande, accepter ou refuser avec motif requis ; décision et auteur/date persistés ; code unique attribué/repris selon D21 ; notification après commit.
- **Variantes :** déjà traitée ou version modifiée → conflit ; envoi e-mail échoué → décision conservée et remise reprenable, aucun second compte.
- **Postconditions :** APPROUVE ou REFUSE ; accès reste conditionné à l’activation et aux restrictions indépendantes ; aucun mot de passe personnel renvoyé.
- **Trace :** CLI-03/04/07, NOT-01, REC46/49 ; D21.

## UC03 — Créer un compte et transmettre son accès

- **Acteur / permission :** USER_CREATE ; USER_ASSIGN_ROLE requis séparément pour les affectations ; USER_RESET_ACCESS pour relance.
- **Préconditions :** e-mail valide et unique, rôles attribuables et responsable autorisé ; habilitation administrative préservée.
- **Déroulement :** saisir e-mail/rôles sans mot de passe ; générer secret imprévisible, stocker hachage/échéance/changement obligatoire ; envoyer au titulaire après validation ; première connexion limitée au changement et déconnexion ; remplacement réussi invalide le secret temporaire.
- **Variantes :** secret injecté non accepté ; envoi échoué suivi sans dupliquer le compte ; secret expiré/remplacé refusé ; route métier directe refusée avant changement.
- **Postconditions :** compte unique et remise suivie ; accès métier uniquement si actif et droits requis, approbation client toujours applicable.
- **Trace :** USR-01–11, SEC-03, REC38–42 ; D16.

## UC04 — Modifier les permissions associées au rôle

- **Acteur / permission :** ROLE_MANAGE ; autres opérations de catalogue permission selon leur droit propre.
- **Préconditions :** rôle visible, version courante, protections système respectées.
- **Déroulement :** préparer ajouts/retraits, afficher confirmation avec comptes concernés, confirmer ; serveur revérifie droits/version/dernier administrateur ; commit atomique et audit avant/après ; droits effectifs actualisés.
- **Variantes :** fermer/annuler → aucun effet ; conflit de version ou perte dernière capacité administrative → refus sans écriture partielle.
- **Postconditions :** nouvelle composition effective dès les requêtes suivantes, union des rôles conservée.
- **Trace :** ACL-01–09, REC09–16 ; D11.

## UC05 — Supprimer un rôle ordinaire

- **Acteur / permission :** ROLE_DELETE.
- **Préconditions :** rôle ordinaire non protégé, aucune affectation même à un compte suspendu ; confirmation explicite.
- **Déroulement :** demander suppression, afficher dépendances, confirmer ; vérifier sous transaction puis supprimer le rôle disponible et tracer l’auteur.
- **Variantes :** protégé/attribué → refus avec dépendance ; réaffecter les comptes par opération autorisée, puis effectuer une nouvelle demande ; concurrence → recontrôle au commit.
- **Postconditions :** aucun compte ni historique métier supprimé ; événement d’audit conservé.
- **Trace :** ACL-07–09, REC11–16 ; D11.

## UC06 — Publier une pièce et ses références croisées

- **Acteur / permission :** PRODUCT_CREATE/UPDATE/PUBLISH, REFERENCE_MANAGE et droits de structure/tarif distincts selon l’action.
- **Préconditions :** produit identifiable par SKU, données et visuels autorisés, règles de référence/code-barres D07 convenues pour publication.
- **Déroulement :** saisir produit canonique, références originales/croisées vérifiées, conditionnements, codes-barres, photos, marque, compatibilités, SH et poids ; renseigner prix/coût selon droits ; contrôler doublons et publier.
- **Variantes :** référence partagée par deux produits → conserver deux identités/stock distincts ; référence répétée même association → pas de doublon ; publication invalide → erreurs et brouillon conservé.
- **Postconditions :** produit publié trouvable via toutes ses correspondances autorisées, zéros initiaux conservés, aucun stock par référence.
- **Trace :** CAT, REF-01–08, REC01–06/43–45/50 ; D02/D07/D08.

## UC07 — Rechercher et soumettre une commande

- **Acteur / permission :** client approuvé, CART_MANAGE_OWN et ORDER_CREATE_OWN ; ORDER_VIEW_OWN pour consultation.
- **Préconditions :** compte actif sans blocage commercial ; produits publiés, unités/quantités valides, adresses renseignées.
- **Déroulement :** rechercher référence, choisir produit/conditionnement, ajouter et fusionner lignes équivalentes, vérifier récapitulatif, soumettre ; serveur revalide compte et prix, crée une commande SOUMISE numérotée avec snapshots et audit ; confirme la vérification humaine à venir.
- **Variantes :** double clic/reprise identique → même numéro ; produit non commandable/saisie invalide → panier conservé ; absence de stock → aucun réservé fictif et parcours reliquat selon règles convenues.
- **Postconditions :** une commande appartenant au client, historique privé ; panier vidé uniquement après succès ; aucun stock réservé par simple panier.
- **Trace :** PAN-01–06, CMD-05, REC17/19/20/31/44 ; D08/D17.

## UC08 — Appeler, proposer et confirmer

- **Acteur / permission :** ORDER_REVIEW, QUOTE_MANAGE, ORDER_CONFIRM selon les actions.
- **Préconditions :** commande/version visible, client éligible, offre chiffrée et accord traçable ; BL direct seulement si D23 satisfait.
- **Déroulement :** prendre en charge, appeler et noter résultat ; établir offre/version ou parcours direct autorisé ; enregistrer accord exact ; réserver sous verrou le disponible des dépôts et conserver le manque accepté en reliquat ; tracer transition/audit puis notifier.
- **Variantes :** prix/produit/conditions modifiés → nouvelle offre et nouvel accord ; devis refusé/expiré → pas de confirmation ; erreur/interblocage → rollback puis reprise bornée ; stock manquant → répartition explicitement acceptée, pas de stock négatif.
- **Postconditions :** même commande confirmée, quantités réservées/reliquats distinctes ; aucune réservation à partir du devis seul.
- **Trace :** CMD-01–06, DEV-01–05, TRX-02/03, REC21–23/53/54/69 ; D03/D05/D17/D23.

## UC09 — Annuler une réservation active

- **Acteur / permission :** RESERVATION_CANCEL dans le périmètre retenu ; éventuelle annulation client reste D06.
- **Préconditions :** réservation ACTIVE, quantités non sorties ; annulation ou révision liée de la commande ; motif fourni.
- **Déroulement :** recontrôler réservation/commande/stock sous verrou ; libérer le réservé et adapter les états dans la même transaction avec mouvements et audit ; rendre le résultat unique.
- **Variantes :** déjà traitée → aucun second mouvement ; expédition concurrente gagnante → conflit et parcours retour ; version périmée → relecture.
- **Postconditions :** disponible recalculé, physique inchangé ; aucune commande prête sans réservation cohérente.
- **Trace :** STK-05/06, TRX-01–05, REC25/26/69 ; D05/D06.

## UC10 — Préparer et expédier

- **Acteur / permission :** magasinier avec DELIVERY_MANAGE ; autres droits stock séparés.
- **Préconditions :** dépôt identifié, lignes réservées actives, commande préparables, opérateur authentifié.
- **Déroulement :** ouvrir le lot, scanner article/unité, vérifier quantités et écarts, valider préparation avec auteur/heure ; au départ réel, valider BL et consommer réservations/physique dans une transaction unique.
- **Variantes :** code inconnu/mauvais produit/excès → refus de ligne ; annulation concurrente → conflit ; préparation répétée → aucun double BL ; échec de livraison → pas de retour automatique en stock.
- **Postconditions :** BL propre à ce lot, mouvements du dépôt concerné ; livré/restant sur même commande, retour contrôlé distinct.
- **Trace :** LIV-01–08, STK-01–07, REC55/56/59 ; D01/D09/D22.

## UC11 — Facturer et enregistrer un paiement

- **Acteur / permission :** INVOICE_CREATE, PAYMENT_RECORD et droits documentaires selon périmètre.
- **Préconditions :** BL valides du même client, quantités encore facturables ; conditions financières D03/D04 définies.
- **Déroulement :** filtrer/sélectionner BL, prévisualiser, verrouiller affectations, émettre facture numérotée et snapshots ; conserver dates réelles ; enregistrer règlement/affectations et recalculer reste dû/échéances.
- **Variantes :** clients différents ou même quantité déjà facturée → refus ; sélection concurrente → une seule affectation ; correction → motif, lien et habilitation protégée pour document émis.
- **Postconditions :** facture sans deuxième sortie de stock ni deuxième créance BL/facture ; paiement indépendant de la livraison ; état de règlement calculé.
- **Trace :** FAC-01–06, PAY-01–04, REC61/62/66/68 ; D03/D04/D08/D20.

## UC12 — Enregistrer un retour physique

- **Acteur / permission :** RETURN_MANAGE ; CREDIT_NOTE_MANAGE requis séparément pour avoir.
- **Préconditions :** livraison/ligne d’origine retrouvées, quantité admissible nette des retours antérieurs, articles réellement reçus.
- **Déroulement :** enregistrer retour, contrôler quantité/état, décider réintégrabilité ; mouvement physique réel si admissible ; si vente facturée, avoir lié à facture et prix historiques, affectation financière unique.
- **Variantes :** excès → refus sans effet ; endommagé → hors disponible ; BL non facturé → reste facturable réduit sans avoir fictif ; vente déjà payée → crédit, remboursement éventuel distinct.
- **Postconditions :** stock et créance évoluent par écritures séparées et justifiées, plafonds préservés.
- **Trace :** AVO-01/02/05/06, REC63/64/68 ; D20.

## UC13 — Consulter le journal des opérations

- **Acteur / permission :** AUDIT_READ ; AUDIT_EXPORT pour export séparé.
- **Préconditions :** compte autorisé, filtres et périmètre admis.
- **Déroulement :** filtrer période/auteur/action/objet/résultat, consulter données expurgées ; tracer lecture ; exporter uniquement si droit présent et tracer export.
- **Variantes :** export sans droit → refus ; objet hors périmètre → aucune donnée ; tentative de modification/effacement → refus pour tous rôles.
- **Postconditions :** historique lisible et attribuable, sans secret, sans modification des événements d’origine.
- **Trace :** AUD-01–06, REC32–35 ; D12.

## UC14 — Reprendre un reliquat à réception

- **Acteur / permission :** commercial habilité ; permission exacte d’allocation à convenir, sans inventer un code système existant.
- **Préconditions :** réception effectivement disponible, reliquat ouvert même client/commande/ligne, priorité D17 validée, compte éligible.
- **Déroulement :** retrouver reliquat, vérifier conditions et accord, verrouiller stock/lignes, affecter disponible à ligne d’origine, créer réservation réelle ; préparer un nouveau BL sur la même commande.
- **Variantes :** autre agent gagne les dernières unités → conflit sans double attribution ; reliquat annulé ou conditions modifiées → nouvelle validation commerciale ; disponible insuffisant → allocation limitée autorisée.
- **Postconditions :** cumul fourni plafonné au confirmé net ; exemple 10 + 5 = 15 sur même commande ; physique jamais négatif.
- **Trace :** STK-10/11, TRX, REC59/60 ; D05/D17.

## UC15 — Retourner une pièce au fournisseur

- **Acteur / permission :** SUPPLIER_RETURN_MANAGE ; acteur magasin habilité pour la sortie, droits achat/fournisseur selon action.
- **Préconditions :** fournisseur et ligne d’achat d’origine, coût net historique, quantité réellement disponible et motif.
- **Déroulement :** préparer bon, contrôler et constater départ physique unique, suivre réception et décision financière ; saisir avoir reçu ou ajustement validé lié ; écrire effet financier une seule fois.
- **Variantes :** quantité insuffisante/retour répété → refus ou même résultat idempotent ; refus financier → dette non annulée, état visible ; absence de pièce d’origine → clarification requise, pas de coût de vente substitué.
- **Postconditions :** retour et acceptation traçables ; dette ajustée seulement sur preuve financière, pas au simple envoi.
- **Trace :** AVO-03–05, REC65 ; D08/D20.

## UC16 — Suivre une échéance et lever un blocage

- **Acteur / permission :** facturation avec BALANCE_VIEW et droits nécessaires ; ADMIN_CREDIT_UNBLOCK et habilitation protégée pour lever l’impayé.
- **Préconditions :** écritures/affectations rapprochées et origine du délai D04 convenue ; état des restrictions visible au seul périmètre autorisé.
- **Déroulement :** afficher reste dû unique et rappel avant échéance ; après dépassement non soldé, bloquer nouvelles ventes côté serveur ; administrateur consulte régularisation/exception, motive et trace la levée.
- **Variantes :** règlement partiel/avoir affecté → recalcul du reste, pas de double rappel BL/facture ; paiement total → pas de déblocage automatique ; suspension d’inactivité distincte → reste active.
- **Postconditions :** levée du seul motif traité ; documents personnels restent accessibles pour régularisation selon accès restreint.
- **Trace :** PAY-03–05, REC66/67/49 ; D04/D19/D23.
