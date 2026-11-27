# LOT 0 — Architecture d’entreprise et contrats ViCarTech

Statut : documents de conception. Les frontières et la propriété d’entreprise ci-dessous appliquent les décisions explicites de l’utilisateur. Les profils de rôles, délais et modalités de transition sont des propositions de détail à valider avant leur implémentation. Aucun contrat v2 n’est actuellement exposé par ce lot. Le lot 1 n’est pas autorisé par ce document.

## Décisions retenues

- Sept applications Laravel indépendantes : Auth, Clients, Appointments, Interventions, Inventory, Billing et Accounting. Aucune huitième application Company/Settings n’est nécessaire.
- Les utilisateurs sont des identités personnelles ; une entreprise est le propriétaire des données professionnelles. Une personne peut avoir plusieurs adhésions d’entreprise, avec des permissions différentes dans chacune.
- Auth possède le répertoire minimal des entreprises, les identités, adhésions, rôles et permissions. Il ne possède pas les fiches métier ou les paramètres fiscaux/commerciaux.
- Une base MariaDB propre par service, avec compte SQL propre. Pas de jointure, clé étrangère ou connexion SQL vers un autre service. Les entreprises ont des lignes distinctes dans chaque base métier ; pas de base par entreprise au démarrage.
- Chaque ressource professionnelle et chaque opération porte un `company_id` SQL. Les identifiants utilisateurs de création/exécution servent à l’audit, jamais à déterminer la propriété de l’entreprise.
- Cookies HttpOnly et Sanctum conservés ; pas de JWT navigateur ni de secret dans localStorage. Chaque opération protégée vérifie en ligne son identité, son entreprise et ses permissions auprès d’Auth, puis applique la Policy du service métier.
- Une identité technique n’accorde pas les droits d’un utilisateur. Les commandes interactives interservices exigent les deux preuves. Une panne Auth refuse les opérations protégées avec 503 ; aucun cache d’autorisation positive ne permet de contourner une révocation.
- Transactions locales ; commandes idempotentes et reprise contrôlée pour les échanges entre services. Outbox/inbox pour les faits métier qui doivent survivre à une panne, sans imposer immédiatement un broker ou une transaction distribuée.
- SQL + JSON : relations, recherches, statuts, versions, dates et montants en SQL ; objets facultatifs et instantanés explicitement versionnés en JSON. Aucun identifiant relationnel stocké uniquement dans JSON.

## Documents normatifs du lot

| Document | Contenu |
| --- | --- |
| [Frontières et paramètres](lot-0-service-boundaries.md) | Responsabilités, catalogues, paramètres, référentiels et dépendances |
| [Entreprises et autorisations](lot-0-tenancy-and-authorization.md) | Adhésions, contexte de requête, schémas SQL et garde-fous |
| [Catalogue des permissions](lot-0-permissions.json) | Permissions explicites et propositions de rôles, sans wildcard |
| [Conventions API](lot-0-api-conventions.md) | Versionnement, idempotence, concurrence, archivage, erreurs et signature interne |
| [API publique v2](lot-0-public-v2.openapi.json) | Surface cible Gateway pour les sept services, schémas d’entrée/sortie et permissions |
| [API interne v2](lot-0-internal-v2.openapi.json) | Validation Auth et références interservices liées à l’entreprise |
| [Échanges et workflows](lot-0-workflows-and-events.md) | Commandes de stock, faits comptables, reprise et compensations |
| [Schéma des événements](lot-0-events.schema.json) | Enveloppe et payloads fermés des faits durables initiaux |
| [Affectation des données](lot-0-company-assignment.schema.json) | Schéma du manifeste futur, sans affectation réelle inventée |
| [Parité React](lot-0-react-contract-mapping.md) | Champs et fonctionnalités à conserver avant chaque remplacement de repository |
| [Transition et acceptation](lot-0-transition-and-acceptance.md) | Impacts sur le code existant, React, imports et tests du lot suivant |
| [Validation documentaire](lot-0-validation.md) | Contrôles réalisés, résultats et limites |

Les OpenAPI v2 sont des contrats cibles : ils ne décrivent pas des endpoints déjà déployés. Les contrats/routes v1, notamment [la relation Clients/Appointments v1](client-appointment-contract-v1.openapi.json), restent inchangés.

## Écarts constatés dans l’existant

`AuthenticatedActor` ne contient que `subject`. Les Policies Clients/Appointments comparent ce sujet à `user_id`. `PermissionService` vérifie l’existence de l’utilisateur et la permission autorisée pour l’appelant technique ; il ne possède pas de RBAC d’entreprise. Les projections `owner_subjects`, les références de rendez-vous et les horaires sont également liés à l’utilisateur. Ils devront évoluer ensemble ; ajouter seulement `company_id` dans React ou retirer la condition `user_id` serait insuffisant et dangereux.

Les quatre services squelettes ne disposent encore que de la santé. Leur schéma d’entreprise doit précéder leur métier. Les clients/véhicules ont des verrous de fusion JSON mais pas de version optimiste ; les rendez-vous possèdent déjà `version`, avec conflit 409 en v1. Les conventions v2 ne changent pas ce comportement actuel.

## Validations métier encore requises

1. Entreprise(s) des deux comptes réels, adhésions, rôles initiaux et affectation de chaque cohorte de données. Ne pas supposer que les deux comptes appartiennent à la même entreprise.
2. Matrice proposée des rôles, notamment pouvoirs financiers de l’administrateur et séparation approbation/remboursement/clôture. Le contrôle d’un second approbateur est un workflow à choisir, pas une conséquence automatique d’un nom de rôle.
3. Calendrier commun initial et résolution des horaires ou rendez-vous concurrents issus de plusieurs utilisateurs. Les techniciens/baies comme ressources de capacité restent une extension, sans schéma multi-établissement imposé maintenant.
4. Propriété proposée des paramètres transversaux : identité commerciale dans Billing, fiscalité dans Accounting et tarification des prestations dans Interventions.
5. Valeurs proposées d’exploitation : rétention d’idempotence, délais réseau et conservation des preuves. Aucune durée légale de rétention n’est affirmée ici.

## Limites d’autorisation

Ce lot ne modifie que des documents/contrats. Aucune migration, aucun backfill ou changement d’ACL, aucune action sur A/B, aucune réactivation des deux clients et six véhicules archivés, aucun routage et aucun commit/push. Les validations restantes n’empêchent pas de relire ces documents ; elles bloquent les affectations de données et l’activation du nouveau modèle.
