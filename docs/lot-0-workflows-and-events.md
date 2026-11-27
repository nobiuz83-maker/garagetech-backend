# LOT 0 — Échanges entre services et engagements métier

## Commandes et faits

Une commande demande une action et peut être refusée. Un événement décrit un fait déjà engagé localement ; il ne remplace jamais une décision d’autorisation utilisateur. `companyId` reste identique de bout en bout. L’initiateur choisit une seule intention/clé de commande et conserve les accusés/révisions externes dans sa base.

| Flux | Producteur → destinataire | Permissions utilisateur minimales et preuve |
| --- | --- | --- |
| Association rendez-vous | Appointments → Clients | `appointments.write`, `clients.read`, `vehicles.read`; certificat Appointments + preuve utilisateur. |
| Association intervention | Interventions → Clients/Appointment | `interventions.write`, lectures des ressources liées ; certificats et contexte d’entreprise. |
| Association document | Billing → Clients/Appointment/Interventions | Permission de commande commerciale et lectures requises ; types d’objets strictement bornés. |
| Réserver/consommer/libérer stock | Interventions ou Billing → Inventory | Permission métier de l’initiateur + permission de stock correspondante, références vérifiées de même entreprise. Le corps ne déclare pas son propre producteur. |
| Prestations et tarifs appliqués | Appointments/Billing → Interventions | Lectures de catalogue/tarification autorisées ; version et libellés/prix appliqués conservés. |
| Politique fiscale pour émission | Billing → Accounting | Permission de commande d’émission et capacité technique de lecture de la politique applicable ; données minimales. Pas besoin de donner au réceptionniste l’accès aux écritures comptables. |
| Faits commerciaux et de coût | Billing/Inventory → Accounting | Capacité système spécifique à chaque type d’événement, outbox engagée, entreprise et source vérifiées ; pas de session navigateur stockée. |

Auth autorise un ensemble de permissions demandé par un handler connu, pas par le navigateur. Les validateurs Clients/Inventory peuvent vérifier les permissions du workflow initiateur en plus de leurs permissions propres. La whitelist Auth borne les couples appelant/purpose/ensemble de permissions ; elle ne devient pas une introspection générale de tous les droits.

## Références et rendez-vous

1. Appointments vérifie `appointments.write` dans l’entreprise.
2. Clients vérifie le même acteur/entreprise, le triplet client/véhicule et les permissions nécessaires ; enregistre une référence permanente sous verrou client.
3. Appointments vérifie l’accusé exact puis valide calendrier/version et engage localement le rendez-vous.
4. Une réponse perdue ou un échec local peut laisser une référence conservatrice. Le retry idempotent réutilise la même identité de commande/dossier ; la réconciliation ne supprime pas l’historique automatiquement.

Les calendriers partagés passent d’un verrou par utilisateur à un verrou par calendrier/entreprise. Deux employés ne peuvent contourner un chevauchement en utilisant des identités différentes.

## Interventions et stock

Le bon de travail commercial autorisé référence les interventions qui exécutent les travaux. Billing ne devient pas propriétaire de `diagnosis`, réparations, temps et pièces réels. Les champs correspondants du document imprimé sont des instantanés provenant d’Interventions, avec révision/provenance explicites.

Inventory possède les mouvements. Interventions possède les demandes et le résultat d’exécution des pièces : produit, quantité, réservation/consommation et identifiant de mouvement. Un prix ou coût appliqué n’est jamais repris silencieusement du prix courant après engagement.

- Préparation : Interventions/Billing engage d’abord un `stockIntentId` immuable avec entreprise, action, révision autorisée et limites de lignes. Inventory récupère ces faits auprès du service source ; il ne fait pas confiance aux quantités ou au statut simplement déclarés dans son corps. Une annulation pendant un résultat distant inconnu devient `cancellationRequested` et exige réconciliation/compensation : elle ne fait pas disparaître l’intention autorisée.
- Exécution : l’employé actuel doit posséder la permission de stock ; il peut accomplir une intention déjà approuvée dans son entreprise sans recevoir l’accès aux prix/profils commerciaux. La préparation exige les droits du dossier source. Les identités de préparation et d’exécution restent auditables séparément.
- Réservation : verrouiller les soldes/lignes Inventory dans un ordre stable ; calculer le disponible ; enregistrer réservation, mouvements et reçu atomiquement. Plusieurs lignes : tout ou rien localement.
- Consommation : convertir la réservation une seule fois en mouvement physique, avec coût officiel figé ; aucune deuxième consommation lors de la facturation.
- Libération : seulement le reliquat réservé. Une pièce consommée nécessite un retour explicite et une nouvelle trace, jamais l’effacement de la consommation.
- Si Inventory a engagé mais le dossier Interventions/Billing ne l’a pas confirmé : état opérationnel `pending`/`reconciliationRequired`, suivi de commande et reprise avec la même clé. Ne pas afficher « terminé » avant confirmation des effets requis.
- Compensation de réservation non consommée : commande dédiée, idempotente et auditée. Compensation d’une consommation : retour/correction autorisé, pas rollback SQL distant. Une compensation échouée reste visible pour réconciliation.

Pas de verrou distribué ou de transaction SQL ouverte pendant un appel réseau.

## Facturation et comptabilité

Billing valide association, autorisation/révision commerciale, coûts et politique fiscale requise. L’émission verrouille séquence et document, fige lignes/totaux/identités fiscales et commerciales, rend le document immuable puis écrit son outbox dans la même transaction. Les modifications ultérieures utilisent un avoir ou une opération corrective autorisée. Un paiement client et son éventuel remboursement appartiennent à Billing ; leur écriture comptable appartient à Accounting.

Accounting déduplique par `(company_id, source_service, source_type, source_id, source_revision, posting_kind)`. Il valide comptes/période et écrit un journal équilibré, ses lignes et son accusé dans une transaction. Un doublon identique ne poste rien de plus ; contenu différent pour la même source ⇒ conflit/quarantaine. Une période clôturée ne s’ouvre pas implicitement : correction dans une période autorisée selon les règles métier validées.

Les achats/réceptions/retours physiques d’Inventory et les factures/règlements fournisseurs d’Accounting sont des objets distincts liés par UUID. La date de reconnaissance, les comptes de passage et la politique de valorisation doivent être validés par le métier comptable ; le transport d’événements ne choisit pas cette politique à sa place.

## Outbox/inbox minimale

Un dispatcher durable lit l’outbox après commit, envoie le fait signé sur réseau privé et conserve l’accusé. Livraison au moins une fois ; pas de promesse « exactly once » du transport. Inbox unique `(company_id, producer, event_id)` et effet local unique assurent l’absence de double traitement.

L’enveloppe versionnée contient eventId, companyId, producer, type, aggregateId/version, occurredAt, requestId, causationId éventuel et payload fermé. Un acteur historique peut être joint uniquement quand connu. Aucun cookie, token, mot de passe ou hash d’identité dans les événements. Les types initiaux sont définis dans [le schéma](lot-0-events.schema.json).

Le destinataire compare le certificat/service signataire au producteur, le type à sa whitelist et le header d’entreprise à l’enveloppe. Une entreprise/projection inconnue est mise en attente/quarantaine, jamais créée à partir d’un événement non réconcilié. Une facture manquante ou une révision hors ordre exige attente/récupération vérifiée, pas application aveugle ; la clé métier de comptabilisation ne suppose pas une livraison ordonnée.

Une autorisation technique peut achever la propagation d’un fait déjà engagé sans conserver la session de l’utilisateur. Elle n’autorise pas une nouvelle commande discrétionnaire. Une suspension d’entreprise bloque les nouvelles opérations interactives ; les faits historiques engagés restent à réconcilier selon une politique système limitée et auditée. Si l’identité d’entreprise ou la provenance n’est pas vérifiable, le traitement attend ; aucun fallback vers une autre entreprise.

Retries bornés avec temporisation, compteur, prochaine tentative et état de réconciliation. Un événement en erreur n’est pas supprimé. Le MVP peut utiliser MariaDB + worker Laravel ; le choix d’un broker est différé jusqu’à besoin mesuré.

Les commandes utilisateur sont interactives dans le MVP. Une reprise automatique qui créerait un nouvel effet discrétionnaire sans contexte utilisateur frais n’est pas autorisée ; elle nécessite un protocole de réautorisation de job distinct, à spécifier avant ce besoin. Les workers d’outbox transportent des faits déjà engagés et peuvent réconcilier un effet confirmé, sans devenir des sessions utilisateurs.
