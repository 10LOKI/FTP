# FTP-9 — dossier v0.3 prêt pour revue

Date : 3 novembre 2026. Références : FTP-9, FTP-33, FTP-34, FTP-35 et cahier V3. Le dossier ne vaut pas approbation de la conception.

## Couverture préparée

| Critère | Livrable | État |
|---|---|---|
| Deux diagrammes modifiables | FTP-9-conception-v0.1.drawio, contenu v0.3 | Exactement deux pages : UC01–UC16 et classes |
| Exports lisibles | use-case.svg et classes.svg | Exportés via diagrams.net ; aperçus généraux et détails accès/finance inspectés |
| Scénarios structurés | SCENARIOS-UC.md | 16 cas : préconditions, déroulement, variantes, postconditions et permissions |
| Tables/PK/FK/contraintes | MAPPING-DONNEES.md | 62 correspondances proposées ; décisions de précision et de règles métier en attente |
| Écrans principaux | ECRANS-ET-REVUE.md | Neuf croquis fonctionnels ; identité graphique et déclinaisons finales à valider |
| Modules/API/transitions | CONTRATS-ET-TRANSITIONS.md | Routes, DTO, erreurs, concurrence/idempotence et transitions proposés |
| D01–D23 | DECISIONS-D01-D23.md | 23 entrées ; responsables à désigner, aucune approbation inventée |
| Baseline/capacité/exclusions | ECRANS-ET-REVUE.md et DECISIONS-D01-D23.md | Baseline observée/exclusions documentées ; capacité réelle à confirmer |
| Revue collective | Feuille dans ECRANS-ET-REVUE.md | Ayoub, Mehdi et Khalid : en attente d’avis réel |

## Vérifications effectuées

- XML : deux pages, identifiants uniques et extrémités des associations valides.
- Export SVG par le moteur officiel diagrams.net dans un navigateur sans interface ; aucun envoi du cahier source.
- Inspection des aperçus généraux et des détails accès/finance ; correction des retours à la ligne et du routage qui traversait les classes. Grand canevas vectoriel nécessitant un zoom, sans promesse d’impression A4 lisible.
- Couverture documentaire : 16 scénarios, 62 correspondances classe/table, 23 décisions ; liens locaux et exclusions Git vérifiés.
- Documentation seulement : aucun nouveau test d’exécution ni rebuild applicatif requis. Les routes proposées ne sont pas présentées comme implémentées ou testées.

## Reste pour clôturer réellement

1. Ayoub, Mehdi et Khalid ouvrent les diagrammes et le dossier, enregistrent remarques et accord sur un commit identifié ; corriger les réserves éventuelles.
2. L’entreprise/l’équipe désigne les responsables D01–D23 et confirme les paramètres ouverts : devise/taxes, délais, références, priorité des reliquats, habilitation d’allocation et affectations financières notamment. Les contraintes déjà imposées restent obligatoires.
3. Confirmer baseline technique partagée, disponibilité réelle de chacun et périmètre du sprint. React et les routes API ne sont pas implémentés par ce checkpoint.
4. Consigner la version acceptée, puis décider de la clôture Jira et de l’intégration vers develop selon la procédure d’équipe.

FTP-9 reste en cours. Aucun ticket passé à Done, aucune approbation présumée et aucun message envoyé à l’équipe.

Sources : [FTP-9](https://elyounssiox-java.atlassian.net/browse/FTP-9), [FTP-33](https://elyounssiox-java.atlassian.net/browse/FTP-33), [FTP-34](https://elyounssiox-java.atlassian.net/browse/FTP-34), [FTP-35](https://elyounssiox-java.atlassian.net/browse/FTP-35).
