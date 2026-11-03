# Écrans et revue de conception — FTP-35

Version 0.3 du 3 novembre 2026. Croquis fonctionnels pour revue ; ils ne constituent ni une interface implémentée, ni une identité graphique approuvée. Source : cahier V3 chap. 3, 11–16 et 21. Deux diagrammes UML seulement sont maintenus ; les croquis ci-dessous sont des écrans, pas des diagrammes supplémentaires.

## Croquis principaux

### E01 — Accueil et recherche publique

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Identité à fournir | Catalogue | Contact | Connexion | Créer compte  │
│ Rechercher une référence [ CR F 200028________________ ] [Rechercher] │
├─────────────────────────────────────────────────────────────────────┤
│ Activité / services                     Visuel fourni ou approuvé    │
│ Catégories de pièces                    Marques / compatibilités     │
│ Produits publiés mis en avant           Comment commander ?          │
├─────────────────────────────────────────────────────────────────────┤
│ Coordonnées / horaires / adresse / informations contractuelles       │
└─────────────────────────────────────────────────────────────────────┘
```

Recherche par référence originale et croisée ; pas de recherche par nom exigée. Exact avant partiel si ce dernier est validé. Une même pièce apparaît une fois, plusieurs produits partageant un code restent séparés. État vide : aucun résultat et accès contact sans création automatique. Prix/disponibilité/références externes selon D02 ; aucune information interne.

### E02 — Résultats et produit

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Référence [_____________] [Chercher]  Catégorie / Marque / Camion     │
│ Résultats paginés : correspondance exacte indiquée par produit       │
├─────────────────────────────────────────────────────────────────────┤
│ Photos       Désignation / SKU / référence trouvée / type            │
│              Références autorisées / marque / compatibilités         │
│              Code SH / poids + unité / caractéristiques              │
│              Prix et disponibilité si autorisés                      │
│              Conditionnement [___] Quantité [___] [Ajouter]          │
└─────────────────────────────────────────────────────────────────────┘
```

Visiteur : bouton d’achat vers connexion/demande de compte. Client approuvé : ajout contrôlé ; zéro disponible ne supprime pas le produit publié, reliquat explicite selon règles convenues. Achat net, marge, emplacement et notes internes absents de l’API et de l’affichage.

### E03 — Inscription et accès

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Demande de compte : ( ) Particulier  ( ) Entreprise                   │
│ Contact / téléphone / e-mail / mot de passe choisi                   │
│ Adresse / ville / pays                                              │
│ Entreprise : raison sociale / responsable / identifiants validés    │
│ [Envoyer la demande] → EN_ATTENTE, aucun accès aux commandes         │
└─────────────────────────────────────────────────────────────────────┘
```

Connexion client par code unique après approbation ; récupération via e-mail, aucun renvoi du mot de passe choisi. Compte créé par administration : parcours séparé « nouveau mot de passe obligatoire » et déconnexion uniquement avant achèvement. Suspension d’inactivité : demande de réactivation restreinte, aucune réactivation automatique. Impayé : documents personnels accessibles pour régularisation, nouvelles ventes bloquées.

### E04 — Panier et soumission

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Mon panier : produit / référence / unité / quantité / prix autorisé   │
│ [Modifier quantité] [Retirer]   Totaux serveur / frais à confirmer    │
│ Avertissement : le panier ne réserve pas le stock                    │
├─────────────────────────────────────────────────────────────────────┤
│ Livraison : adresse / ville / pays     Facturation : [identique ?]    │
│ Contact téléphonique / instructions                                 │
│ Récapitulatif et données à confirmer [Soumettre ma demande]          │
│ Résultat : n° commande SOUMISE — vérification humaine attendue       │
└─────────────────────────────────────────────────────────────────────┘
```

Double clic/reprise réseau retourne la même commande ; échec conserve le panier. Une modification du prix serveur est explicitée avant engagement selon la politique convenue. Après soumission, accès au détail et à l’historique privés.

### E05 — Commercial : commande et accord

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Commandes : numéro / client / période / état [Filtrer]               │
│ N°... | état + couleur | version | [Prendre en charge]               │
├─────────────────────────────────────────────────────────────────────┤
│ Coordonnées / restrictions / historique / appels                     │
│ Lignes : demandé / disponible dépôt / proposé / réservé / reliquat   │
│ Appel : résultat / note / date / auteur [Enregistrer]                 │
│ [Créer devis] ou [Parcours BL direct si éligible]                     │
│ Accord : offre/version exacte / contact / date / agent               │
│ [Confirmer la version acceptée] [Annuler selon droits]               │
└─────────────────────────────────────────────────────────────────────┘
```

Remplacement jamais silencieux ; nouvelle proposition et accord requis. Conflit de version : bannière « recharger la commande », aucune confirmation sur l’ancien écran. Le récapitulatif sépare explicitement réservé réel et manque accepté.

### E06 — Préparation et BL

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Dépôt [___] Commande [___] BL [___] | état / version                 │
│ Scanner [________________]   Article / unité / attendu / scanné      │
│ Erreur : code inconnu, mauvais article ou dépassement sur la ligne   │
│ Opérateur / date / écarts justifiés [Valider préparation]             │
│ Récapitulatif lot / dépôt [Confirmer départ physique]                │
│ Historique BL et restant à fournir sur la même commande              │
└─────────────────────────────────────────────────────────────────────┘
```

Préparation, impression et départ distincts. Le départ atomique consomme le réservé et sort le physique une seule fois. Course avec annulation : conflit explicite. Incident/retour sans réintégration automatique.

### E07 — Facturation et paiements

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Client [___] Période [___] État facturation [___]                     │
│ [ ] BL : n° / date / montant / quantité encore facturable             │
│ Sélection même client [Prévisualiser facture] [Émettre]              │
├─────────────────────────────────────────────────────────────────────┤
│ Pièces et échéances / créance unique / paiements / avoirs / reste dû  │
│ Règlement : montant/devise/mode/référence/date [Enregistrer]          │
│ Retour contrôlé → avoir au prix historique ; remboursement distinct │
└─────────────────────────────────────────────────────────────────────┘
```

Double sélection concurrente verrouillée au serveur. Un BL ne devient pas une deuxième créance en étant facturé. Annulation documentaire et déblocage impayé sur écran protégé séparé avec dépendances, effets, motif et confirmation.

### E08 — Administration : rôle et confirmation

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Rôle / permissions par module / version                             │
│ [Prévisualiser changements]                                         │
│ ┌ Confirmer : rôle ... / version ... ──────────────────────────────┐ │
│ │ Ajouts ...   Retraits ...   Nombre de comptes affectés ...        │ │
│ │ Avertissement capacité de gestion [Annuler] [Confirmer]           │ │
│ └─────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

Annuler/fermer sans effet ; focus clavier dans la fenêtre puis retour au déclencheur. Nouvelle version concurrente : recharger le récapitulatif. Dernière capacité administrative et rôles/permissions protégés vérifiés au serveur.

### E09 — Audit

Filtres période/acteur/action/objet/résultat ; tableau paginé, détail avant/après filtré. Export visible selon AUDIT_EXPORT et recontrôlé au serveur. Consultation et export audités ; aucune commande de modification/effacement pour aucun rôle.

## Autres écrans à décliner depuis ces patrons

Profil/adresses, promotions actives, historique commandes/reliquats/documents privés ; approbations et remise d’accès ; édition produit/référence/code-barres/compatibilité/promotion ; stocks/seuils/réceptions/transferts ; retours clients/fournisseurs, avoirs et soldes ; échéances/rappels et déblocage protégé. Ces listes constituent le périmètre à maquetter en détail ensuite, pas des écrans livrés en React.

## Responsive et accessibilité

Sur mobile, filtres repliables, cartes lignes au lieu d’un tableau large, action principale explicite et détails financiers lisibles. Texte d’état toujours présent en plus de la couleur. Étiquettes de champs, erreurs associées, focus visible, navigation clavier, confirmation accessible et saisies non sensibles conservées après erreur. Les actions masquées ne remplacent jamais les contrôles API.

## Baseline et exclusions

Le dépôt de référence `upstream/develop` à `adac4fe` fournit Laravel 13 et Docker PHP 8.4/MySQL 8.4 ; le test local a observé PHP 8.4.27 et MySQL 8.4.13. Node 24 est utilisé au build. Les versions exactes et lockfiles du dépôt sont la référence exécutable ; leur approbation collective est à consigner. React est demandé par Jira mais pas encore livré par ces documents. Version React, outil de routage et approche d’authentification restent à convenir avec l’équipe.

Langue des présents documents : français ; langue produit/documents D13 non approuvée. Devise, taxes, arrondis et délais : D03/D04/D08, sans valeur inventée.

Exclusions V3 : banque en ligne, transporteurs intégrés, synchronisation comptable, cycle complet d’achats et plusieurs acheteurs par entreprise. Les données minimales achat/fournisseur pour retours et soldes restent incluses. Capacité réelle : disponibilité de chaque personne à renseigner ; les points Jira ne représentent pas des heures validées.

## Feuille de revue collective

| Relecteur | Version / commit | Date | Remarques et preuves | Avis |
|---|---|---|---|---|
| Ayoub Ouharda | À renseigner | — | Revue visuelle et métier à effectuer | En attente |
| Mehdi Karbitou | À renseigner | — | Contrats identité/catalogue et cohérence globale | En attente |
| Khalid El younssi | À renseigner | — | Logistique, finance et baseline partagée | En attente |

Les domaines proposés pour orienter la revue ne modifient aucune affectation Jira. Chaque relecteur doit revoir l’ensemble de la conception. Aucun message n’est envoyé automatiquement et aucun accord n’est présumé.
