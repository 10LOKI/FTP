# Scénarios et décisions — extraits source

Transcription du cahier V3 fourni par l’utilisateur ; les sauts de ligne proviennent du PDF. Source UC : pages 28–31 ; décisions : pages 35–36. Les paragraphes UC donnent acteur, déroulement, variantes et résultat. Les préconditions communes sont authentification/état/permissions/périmètre selon le cas ; UC01 reste public.

## UC01–UC16


### UC01 Demander un compte client
Acteur : visiteur. Il choisit Particulier ou Entreprise, complète le formulaire et l’envoie. Le système
valide les données et crée un compte EN_ATTENTE sans accès aux commandes. Variante : e-mail
déjà utilisé ou données invalides, le formulaire est rejeté sans compte supplémentaire. Résultat :
demande identifiable et événement d’audit.

### UC02 Approuver un client
Acteur : responsable disposant de CUSTOMER_APPROVE. Il vérifie la demande, accepte ou refuse
avec le motif nécessaire. À l’acceptation, le code client unique est attribué ou repris, et l’e-mail
d’approbation transmet cet identifiant sans renvoyer le mot de passe choisi. Variante : demande déjà
traitée, conflit explicite. Résultat : accès conditionné à l’approbation et aux autres motifs de blocage.

### UC03 Créer un compte et transmettre son accès
Acteur : administrateur autorisé. Il renseigne l’e-mail et attribue les rôles sans saisir de mot de passe.
Le système génère un secret temporaire, enregistre son hachage et prépare l’e-mail d’accès après
validation de la création. À la première connexion, l’utilisateur doit définir un nouveau mot de passe
avant tout accès métier. Variante : échec d’envoi, le compte reste unique et la remise est suivie puis
relancée avec les droits requis.

### UC04 Modifier les permissions associées au rôle
Acteur  :  responsable  disposant  de  ROLE_MANAGE.  Il  sélectionne  les  permissions,  ouvre  la
confirmation et contrôle les ajouts, retraits et comptes concernés. Confirmer enregistre la version
proposée ; Annuler  ne change rien. Variante : modification concurrente ou perte de la  dernière
capacité administrative, l’écriture est refusée. Résultat : droits effectifs actualisés et audit avant après.

### UC05 Supprimer un rôle ordinaire
Acteur : responsable disposant de ROLE_DELETE. Il demande la suppression. Le système refuse si
le rôle est protégé ou attribué, et indique la dépendance bloquante. Après réaffectation de tous les
comptes, une nouvelle demande peut aboutir. Résultat : disparition du rôle disponible pour affectation,
avec conservation de son histoire d’audit.

### UC06 Publier une pièce et ses références croisées
Acteur : gestionnaire de catalogue autorisé. Il crée le produit et ses références vérifiées, les codes-
barres,  photos, marque,  véhicules  compatibles,  code  SH  et  poids  net.  Il  renseigne  les  données
tarifaires et internes selon ses droits. Le serveur contrôle les doublons et la publication. Variante :
plusieurs produits distincts partagent une référence croisée, ils restent séparés sans fusion de stock.
25 Cas pratiques pour le commerce et la traçabilité

### UC07 Rechercher et soumettre une commande
Acteur  :  client  approuvé.  Il  recherche  une  référence,  sélectionne  le  produit,  définit  la  quantité,
complète les adresses et soumet son panier. Le système revalide le compte et les lignes, puis crée
une seule commande SOUMISE. Variante : double clic, le numéro existant est retourné ; produit non
commandable, le panier reste corrigeable.

### UC08 Appeler proposer et confirmer
Acteur : agent commercial habilité. Il enregistre l’appel et l’accord sur le devis ou sur le parcours BL
direct autorisé. Il confirme la version acceptée. Le système réserve le disponible réel et conserve le
reste accepté en reliquat par client et ligne. Variante : erreur technique, rollback complet ; manque de
stock, répartition explicite avant accord. Résultat : même commande confirmée avec quantités et
documents traçables.

### UC09 Annuler une réservation active
Acteur : utilisateur autorisé selon le périmètre retenu. Il demande l’annulation avec motif. Le système
contrôle que les produits ne sont pas sortis, libère les quantités, change les états et conserve les
mouvements.  Variante  :  annulation  déjà  traitée,  aucun  mouvement  supplémentaire.  Résultat  :
disponible recalculé sans augmentation du physique.

### UC10 Préparer et expédier
Acteur : magasinier habilité. Il ouvre les lignes réservées du dépôt, scanne les codes-barres, vérifie les
quantités et valide la préparation avec son identité et l’heure. Au départ réel, il valide le BL du lot ; la
réservation est consommée et le stock sorti une fois. Variante : mauvais code, écart ou annulation
concurrente, sortie refusée. Le reliquat reste ouvert sur la commande.

### UC11 Facturer et enregistrer un paiement
Acteur : chargé de facturation habilité. Il filtre les BL d’un client, sélectionne un ou plusieurs BL non
encore facturés et émet la facture. Le système affecte les quantités, conserve les dates et évite toute
double  facturation  ou  seconde  dette.  Il  enregistre  les  règlements  et  calcule  les  restes  dus  et
échéances. Une correction d’un document émis suit la procédure protégée et conserve l’original.

### UC12 Enregistrer un retour physique
Acteur : gestionnaire de retour habilité. Il rattache les articles reçus à la livraison, contrôle les quantités
et leur état, puis valide la réintégration admissible. Si la vente est facturée, l’agent disposant de
CREDIT_NOTE_MANAGE  établit  l’avoir  lié  selon  les  montants  historiques.  Le  solde  et  le  stock
évoluent par écritures distinctes. Variante : dépassement de quantité, refus sans effet.

### UC13 Consulter le journal des opérations
Acteur : lecteur disposant de AUDIT_READ. Il filtre les événements par commande, auteur, période et
résultat. Le système ne retourne que le périmètre autorisé et trace cette consultation. Variante : export
demandé sans AUDIT_EXPORT, refus. Résultat : opérations attribuables consultables sans possibilité
de modifier le journal.
Les variantes non fixées concernent notamment les modalités d’annulation par le client, la priorité
d’affectation des reliquats et le point de départ des délais. Elles sont regroupées au chapitre 29 ; les
livraisons partielles et le regroupement de BL sont inclus dans le périmètre.

### UC14 Reprendre un reliquat à réception
Acteur : commercial habilité. À l’arrivée de 5 unités, il retrouve le reliquat de 5 du client, vérifie ses
conditions et affecte le disponible à la ligne d’origine. Le système réserve ces unités puis permet un
second BL. Une affectation concurrente aux mêmes unités est refusée ; le cumul livré reste plafonné
au commandé.

### UC15 Retourner une pièce au fournisseur
Acteur : agent habilité aux retours fournisseurs. Il choisit l’achat d’origine, motive le retour et établit le
bon. Le magasin constate la sortie réelle. L’agent suit l’acceptation et l’avoir fournisseur reçu ou la
correction validée. Le solde fournisseur est ajusté une fois ; un retour simplement envoyé reste en
attente financière.

### UC16 Suivre une échéance et lever un blocage
Acteur  :  facturation  puis  administrateur  habilité.  Le  rappel  affiche  les  BL  et  restes  dus  avant
l’échéance.  Après  dépassement  non  soldé,  le  compte  est  bloqué  pour  de  nouvelles  ventes.
L’administrateur contrôle la régularisation ou motive une exception avant déblocage. Une suspension
distincte pour inactivité reste active tant qu’elle n’est pas levée.


## D01–D23 — aucune validation enregistrée

29 Décisions métier à valider
Ces décisions doivent être prises avec l’entreprise avant de figer les maquettes et les transactions.
Les propositions indiquées permettent de préparer le développement sans les présenter comme des
choix déjà approuvés.
Décision Proposition ou question à trancher
D01 Sortie de stock
Le départ physique validé lié au BL est le déclencheur unique retenu dans ce périmètre.
Valider qui constate le départ et les modalités de preuve ; la facture différée ne sort pas le
stock une seconde fois.
D02 Visibilité des prix Proposition conservée : prix réservés aux comptes approuvés. Arrêter la visibilité publique
des disponibilités et références ; les coûts d’achat et remarques internes restent privés.
D03 Documents de
vente
Regroupement d’un ou plusieurs BL du même client inclus. Valider numérotation, taxes,
arrondis, mentions, présentation des BL et procédure administrative de correction des
documents émis.
D04 Conditions de
paiement
Un mode actif et un délai par client. Fixer le point de départ du délai, les jours comptés,
l’affectation des paiements et avoirs, l’anticipation des rappels et le traitement des
échéances de BL regroupés.
D05 Réservations Confirmation avec réservation du disponible et reliquat accepté. Fixer durée, expiration,
réaffectation et notifications en cas de réservation libérée.
D06 Annulation
Fixer les actions possibles par le client et leurs états. Après sortie réelle, retour contrôlé
obligatoire ; suppression et annulation des factures, avoirs et BL réservées à
l’administration habilitée.
D07 Catalogue Valider références et normalisation, collisions, compatibilités, codes-barres et
conditionnements, code SH, unités de poids et volume initial.
D08 Tarifs et coûts Fixer devise, base HT ou TTC, règles de remise et source du coût net d’achat. Le coût
fournisseur interne et le prix de vente restent distincts.
D09 Livraison Livraisons partielles et plusieurs BL par commande inclus. Fixer frais, zones,
transporteurs, preuves et entrepôts de départ.
D10 Comptes
entreprise
Le compte principal unique reste la base. Plusieurs acheteurs ou établissements exigent
une demande de périmètre distincte.
D11 Rôles
indispensables
Valider rôles et permissions protégés, dernier administrateur, habilitations de réactivation,
déblocage impayé et annulation de documents.
D12 Exploitation Fixer hébergement, domaine, volumes, disponibilité, sauvegardes, reprise et conservation
de l’audit ; valider le matériel de scan et son usage au dépôt.
D13 Identité et langues Fournir raison sociale, logo, visuels et coordonnées ; arrêter les langues de l’interface et
des documents.
D14 Intégrations Choisir la messagerie. Excel, synchronisation comptable, SMS, transporteurs et paiement
en ligne sont à confirmer séparément ; les rappels internes sont inclus.
D15 Technologie Choisir pile technique, base transactionnelle, stockage documentaire, index de recherche
et environnements.
D16 Accès temporaires
Fixer validité et politique de mot de passe. La génération automatique, l’envoi au titulaire
et le premier changement obligatoire des comptes créés par l’administration sont
maintenus.
D17 Reliquats Suivi par client inclus. Fixer priorité d’allocation à réception, accord sur délais et
commandes totalement en rupture, clôture ou annulation du restant.
D18 Promotions Fixer cumul avec remise client, chevauchement des offres, fuseau horaire et validation
des prix promotionnels.
Décision Proposition ou question à trancher
D19 Inactivité
Règle de quatre mois incluse. Préciser le périmètre clients ou employés, le calcul
calendaire, le point de départ sans première connexion et les administrateurs
destinataires des demandes.
D20 Retours et soldes Valider contrôle qualité, motif de retour, approbation d’avoir, preuve d’acceptation
fournisseur, affectations, remboursements et soldes initiaux à reprendre.
D21 Codes clients Confirmer les codes existants à reprendre, le format des nouveaux codes et le moment
d’attribution ; unicité et absence de réattribution obligatoires.
D22 Entrepôts et alertes Fournir dépôts, rayons, niveaux, stocks initiaux et seuils minimums ; arrêter le critère
d’alerte et les modalités de transfert.
D23 Client connu et
impayé
Fixer l’éligibilité au BL direct, les opérations bloquées après échéance et les conditions
d’une dérogation administrative, sans retirer le contrôle serveur ni l’audit.
