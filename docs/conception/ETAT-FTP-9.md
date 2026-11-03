# FTP-9 — état du checkpoint de conception

Vérification du 3 novembre 2026 contre FTP-9, FTP-33, FTP-34 et FTP-35 dans Jira.

## Livré dans ce checkpoint

- Deux diagrammes modifiables dans le fichier Draw.io : UC01–UC16 et diagramme de classes global.
- Acteurs, frontière système, permissions, attributs, opérations proposées, associations et cardinalités.
- Scénarios source, contraintes métier et décisions ouvertes extraits du cahier des charges V3.
- Identifiants textuels, décimaux/devise, instantanés historiques et séparation stock/réservations/finance documentés.
- Documents sources et sauvegardes locaux exclus de Git et du contexte Docker.

## Vérifications réellement effectuées

- XML lisible : exactement deux pages ; UC01–UC16 présents.
- Identifiants de cellules uniques, extrémités des associations valides et formes dans les limites de page.
- Contrôle Git des espaces et exclusions des fichiers locaux.
- Pas de modification du code applicatif : aucun nouveau test d’exécution ni rebuild nécessaire pour ce checkpoint documentaire.

Ces contrôles structurels ne prouvent pas la lisibilité du rendu Draw.io ni la validation métier des cardinalités.

## À terminer avant clôture

| Élément | État / preuve attendue |
|---|---|
| Revue visuelle des deux diagrammes | Vérifier dans Draw.io les chevauchements, chemins des liens et lisibilité à l’export ; ajuster si nécessaire. |
| Exports lisibles | Ajouter un SVG ou PDF pour chacun des deux diagrammes, après revue du rendu. |
| Scénarios FTP-33 | Formaliser, pour chaque UC, préconditions, chemin normal, variantes, postconditions et permissions ; la transcription source reste une base de travail. |
| Correspondance relationnelle FTP-34 | Tableau classe → table, PK/FK, index/unicités, nullabilité et contraintes. Fixer la précision des montants et les affectations financières après décisions métier. |
| Revue équipe | Ayoub, Mehdi et Khalid : enregistrer date, version/commit relu, remarques et accord. Aucun accord n’est présumé. |
| Écrans FTP-35 | Croquis accueil/recherche/produit, panier/commande et principaux écrans internes. |
| Contrats et transitions FTP-35 | Limites de modules API, exemples requête/réponse/erreur, identifiants partagés et transitions d’état avec gardes. |
| Registre D01–D23 | Pour chaque décision : responsable nommé, état approuvé/en attente, valeur retenue, date et preuve. Ne pas approuver automatiquement les paramètres ouverts. |
| Baseline technique et capacité | Consigner choix PHP/Laravel/React/Node/base, langues, devise/taxes, délais, disponibilité des trois personnes et exclusions de périmètre. |

FTP-9 reste « In Progress » au moment de cette vérification. Ce checkpoint ne justifie pas de passer FTP-9, FTP-33, FTP-34 ou FTP-35 à Done. Aucune modification Jira ou notification à l’équipe n’est réalisée.

Sources Jira : [FTP-9](https://elyounssiox-java.atlassian.net/browse/FTP-9), [FTP-33](https://elyounssiox-java.atlassian.net/browse/FTP-33), [FTP-34](https://elyounssiox-java.atlassian.net/browse/FTP-34), [FTP-35](https://elyounssiox-java.atlassian.net/browse/FTP-35).
