# LOT 0 — Responsabilités et propriété des données

Ce tableau définit les autorités uniques. Une projection ou un instantané historique n’est pas une deuxième autorité modifiable.

| Service | Autorité métier et collections SQL envisagées | Frontière |
| --- | --- | --- |
| Auth | `users`, `companies`, `company_memberships`, `roles`, `permissions`, associations rôle/permission et adhésion/rôle, invitations, sessions, audit d’accès | Répertoire d’entreprise minimal (`id`, libellé administratif, état, version d’autorisation). Pas de clients, stock ou comptabilité. |
| Clients | `clients`, `vehicles`, projection `company_subjects`, associations et références externes, journal d’archivage | Coordonnées courantes des clients et caractéristiques des véhicules. Ni profils utilisateurs ni dossiers complets des services consommateurs. |
| Appointments | `appointments`, `company_calendars`, associations locales validées, références externes | Réservation, annulation, calendrier et règles de disponibilité. Pas de diagnostic, réparation ou consommation de pièces. |
| Interventions | `interventions`, prestations/catégories, versions de tarifs, temps saisis, lignes de pièces demandées/consommées, références de commandes de stock | Exécution des travaux, diagnostics, réparations, prestations, temps et pièces effectivement consommées. Le mouvement physique et son coût officiel appartiennent à Inventory. |
| Inventory | Produits/catégories, fournisseurs, emplacements, achats/lignes, réceptions/lignes, retours, réservations/lignes, mouvements et couches/agrégats de valorisation | Source unique du stock disponible/réservé/consommé et de son coût. Un achat n’est pas une facture fournisseur comptable. |
| Billing | Soumissions/lignes/approbations/révisions, bons de travail/lignes/autorisations, factures/lignes, séquences, paiements clients, avoirs, remboursements, profil commercial et audit | Chaîne commerciale complète. Le bon de travail porte engagement/autorisation ; l’intervention porte exécution. Émission d’une facture ne consomme jamais une deuxième fois le stock. |
| Accounting | Comptes, écritures/lignes, périodes, dépenses/factures fournisseurs/lignes, règlements fournisseurs, trésorerie, versions de politique fiscale, rapports, kilométrage et justificatifs | Source unique des écritures comptables et des dépenses. Consomme des faits commerciaux/de stock sans modifier les documents émis ni recalculer leurs totaux figés. |

Les noms SQL nouveaux sont une convention cible, pas des migrations créées. Les tableaux de bord React agrègent des APIs/projections de lecture ; ils ne deviennent pas une autorité financière.

## Catalogues et paramètres

| Donnée | Propriétaire | Consommateurs et règle |
| --- | --- | --- |
| Identité utilisateur, adhésion, rôles | Auth | Réponses minimales, vérifiées en ligne. Pas de table `users` dans les bases métier. |
| Libellé/état administratif d’entreprise | Auth | Projections locales `company_subjects`, jamais déduites d’un domaine de courriel. |
| Raison sociale, coordonnées commerciales, logo, conditions et préfixes de documents | Billing | React affiche via Settings ; les documents émis gardent un instantané de la version utilisée. |
| Identifiants fiscaux, inscription, politiques/taux à dates d’effet et règles de déclaration | Accounting | Billing obtient une version fiscale valide pour le document ; Accounting conserve les paramètres et rapports fiscaux. Les choix fiscaux doivent être approuvés par le métier ; aucune règle légale nouvelle n’est inventée. |
| Catalogue de prestations, catégories, libellés FR/EN et tarification de main-d’œuvre | Interventions | Appointments et Billing utilisent les identifiants et des libellés/prix appliqués figés ; une désactivation future ne réécrit pas l’historique. |
| Produits, fournisseurs, prix de référence, compatibilités, emplacements | Inventory | Billing/Interventions lisent les champs nécessaires. Coûts/valorisation exigent une permission distincte ; un tarif commercial dérogatoire exige permission et motif. |
| Horaire, pauses, délai et politique de réservation | Appointments | Calendrier commun d’entreprise initial. Ressources multiples seulement après décision explicite. |
| Préférences personnelles d’affichage/langue | React, éventuellement profil Auth plus tard | Peuvent rester locales ; aucune préférence ne vaut autorisation ou propriété métier. |
| Justificatifs comptables | Accounting | Métadonnées SQL, fichier privé via Filesystem Laravel ; pas de blob/base64 enfoui dans JSON. |

La page Settings existante reste une façade React : chaque section appelle son service propriétaire. Il n’y a pas de huitième service Settings. Les tarifs appliqués à un dossier, l’identité commerciale et la fiscalité d’une facture émise sont des instantanés historiques versionnés, différents des valeurs courantes.

Pour éviter un blocage artificiel, le noyau de politiques fiscales Accounting peut être livré avant ses rapports complets, et le catalogue Interventions avant l’exécution complète des réparations.

## Références externes et SQL + JSON

- Toute table professionnelle possède `company_id`. Les enfants portent des clés étrangères locales composites `(company_id, parent_id)` ; les parents exposent un index unique `(company_id, id)`. Une FK sur le seul UUID n’exprime pas l’isolation d’entreprise.
- Une référence externe conserve l’UUID et l’entreprise en SQL, avec une projection locale validée. Aucun Eloquent `belongsTo` vers une table étrangère, aucun accès SQL partagé.
- Les documents JSON complexes portent `schemaVersion`. Les relations, montants, numéros, statuts, versions et dates de recherche ne sont pas cachés dans JSON. Un instantané n’embarque pas une deuxième relation modifiable : les références restent dans les colonnes SQL.
- Montants en cents entiers bornés au contrat JS sûr ; quantités en unités entières à échelle explicite (milli-unités pour le stock existant). Les taux fiscaux utilisent un ratio entier et une règle d’arrondi versionnée. Aucun flottant dans les calculs financiers.
- MariaDB 10.6 : JSON est LONGTEXT validé, pas le JSON binaire MySQL. Colonnes générées/index JSON uniquement après justification d’une recherche.
- Les UUID existants sont conservés. Les identifiants de catalogues/emplacements/comptes actuellement non UUID ne sont pas régénérés sans table de correspondance approuvée.

## Dépendances et absence de cycle obligatoire

Auth fournit l’identité à tous. Clients fournit les associations à Appointments, Interventions et Billing. Appointments fournit les références de réservation à Interventions/Billing. Inventory reçoit des commandes de stock d’Interventions/Billing. Billing produit des faits commerciaux ; Inventory produit des faits de valorisation ; Accounting les comptabilise. Les politiques fiscales Accounting et les catalogues Interventions sont des référentiels versionnés qui peuvent être livrés avant les workflows complets.

Un service consommateur n’exige pas une lecture distante pour chaque affichage historique : il conserve l’instantané validé nécessaire. En revanche, un cache métier ne remplace jamais une autorisation Auth fraîche.
