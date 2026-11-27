# LOT 0 — Entreprises, adhésions et autorisations

## Modèle minimal évolutif

Auth possède `companies` (UUID, libellé administratif, état, version), `company_memberships` (UUID, company_id, user_id, état, version), les rôles/permissions et leurs associations SQL. Une contrainte unique `(company_id, user_id)` empêche les adhésions doublonnées. Les rôles portent sur une adhésion, jamais globalement sur l’identité. Les sept rôles proposés sont des modèles prédéfinis ; un éditeur de rôles personnalisés et un super-administrateur interentreprises ne sont pas introduits.

Une identité peut adhérer à plusieurs entreprises. Les permissions sont l’union des rôles actifs **dans l’entreprise demandée uniquement**. Le service appelant doit aussi être autorisé à demander ces permissions pour ce workflow. Les Policies appliquent ensuite les limites de ressource : entreprise, état, affectation éventuelle et champs sensibles. Un rôle ne permet pas de modifier une facture émise, de supprimer une écriture ou de restaurer les huit archives conservées.

Chaque base métier possède une projection SQL minimale `company_subjects(company_id, status, directory_version)` validée depuis Auth, et des FK locales de ses données vers cette projection. Elle ne possède pas le profil fiscal ou les rôles faisant autorité. Un événement inconnu ne crée pas implicitement une entreprise. Les projections peuvent servir à l’intégrité et à l’audit, pas à remplacer le contrôle en ligne.

## Contexte explicite par requête

Les opérations professionnelles `/api/v2` exigent `X-Company-ID` (UUID). Ce header est un **sélecteur non fiable**, pas une preuve. Une requête sans sélection explicite est refusée ; aucun fallback vers `user_id`, première adhésion, cookie non vérifié ou valeur fournie dans le JSON.

1. React obtient sa session normalement puis `GET /api/v2/me/companies`.
2. React choisit une entreprise autorisée, automatiquement seulement si une seule est proposée, et envoie toujours son ID explicitement. Le choix est maintenu par onglet, pas comme autorité globale mutable de session.
3. Gateway transmet le sélecteur et génère/normalise `X-Request-ID`, mais supprime les prétendus `X-User-ID`, rôles, permissions et signatures fournis par le navigateur. Elle ne décide pas des permissions métier.
4. Le service métier sélectionne les permissions requises dans sa route/Policy, pas depuis un header utilisateur. Il appelle Auth v2 avec le cookie opaque, l’entreprise et la liste de permissions attendue. GET ne valide que des permissions de lecture ; POST valide les commandes et exige le jeton CSRF utilisateur. Un handler de lecture interne qui participe à une commande transmet aussi son jeton pour vérifier une permission mutatrice.
5. Auth vérifie session active, entreprise active, adhésion active, permissions de cette adhésion et whitelist de l’appelant technique. Il retourne `subject`, `companyId`, `membershipId`, `authorizationVersion`, `permissions`, `requestId` et `allowed=true`, liés à la requête.
6. Le service vérifie exactement ces valeurs et construit un acteur immuable. Il filtre toutes ses requêtes SQL par l’entreprise vérifiée avant binding/Policy. L’acteur ne devient pas un utilisateur Eloquent local.

Les endpoints de session/identité, la liste des entreprises et l’acceptation d’invitation ont un contexte spécifique documenté dans OpenAPI. L’acceptation résout l’entreprise depuis l’invitation stockée et exige la session, le courriel vérifié correspondant et CSRF ; elle ne fait pas confiance à un `companyId` JSON arbitraire.

Les requêtes internes interactives transportent la même entreprise, avec mTLS + signature v2. Le destinataire refait les contrôles d’identité et de ressource ; une réponse `allowed=true` d’une autre entreprise, permission ou requête est invalide (503), jamais une autorisation.

## Rôles proposés

Le [catalogue machine](lot-0-permissions.json) est la liste normative proposée ; les noms de rôle n’ont aucun pouvoir implicite.

| Rôle | Intention par défaut |
| --- | --- |
| `owner` | Gouvernance de l’entreprise et ensemble explicite des permissions ; protections financières et dernier propriétaire toujours applicables. |
| `administrator` | Membres hors propriétaires et exploitation métier ; pas de clôture/écriture comptable par simple pouvoir d’administration. |
| `service_advisor` | Clients/véhicules, rendez-vous, soumissions/bons et facturation courante ; pas de remboursement ou d’ajustement de stock implicite. |
| `technician` | Lecture des dossiers nécessaires, interventions et commandes de stock liées aux travaux ; pas de prix de revient ou comptabilité. |
| `inventory_manager` | Catalogue, fournisseurs, achats, réceptions, retours et stock ; pas d’édition des documents commerciaux émis. |
| `accountant` | Comptabilité/fiscalité/rapports, accès aux faits commerciaux nécessaires ; pas d’administration des utilisateurs. |
| `auditor` | Lecture autorisée sans mutation, selon la matrice explicite. |

Ces profils nécessitent une validation métier. Une permission d’écriture dépend des lectures nécessaires, vérifiées par le catalogue. Les restrictions plus fines (travaux affectés au technicien, seuil de remboursement, second approbateur) restent des Policies/workflows explicites ; aucune n’est supposée déjà implémentée.

## Gouvernance des adhésions

- Suspendre une adhésion n’efface ni l’utilisateur, ni ses interventions, ni l’audit. Un départ d’employé ne change pas la propriété des données de l’entreprise.
- Seul `identity.owners.manage` permet d’attribuer/retirer `owner` ; un gestionnaire ne s’accorde pas des pouvoirs supérieurs à ceux autorisés par sa délégation. Pas de self-escalation via tableau de rôles fourni par le client.
- Le dernier propriétaire actif ne peut être suspendu ou rétrogradé. Une modification de rôle/adhésion est transactionnelle et versionnée ; elle incrémente la version d’autorisation d’entreprise.
- Invitations à usage unique, expiration proposée de 72 heures, token stocké haché, jamais dans logs/événements. Acceptation liée à l’identité authentifiée et à la version de l’invitation ; toute invitation révoquée ou devenue incompatible est refusée. Pas d’auto-rattachement par domaine de courriel.
- Aucune liste globale de profils utilisateurs n’est exposée à une entreprise. Les membres visibles sont ceux de son périmètre.

## Isolation et révocation

Session invalide : 401. Sélection d’entreprise manquante/invalide : 400. Entreprise/adhésion inconnue, inactive ou interdite : 403 identique, sans divulguer son existence. Ressource ciblée par URL appartenant à une autre entreprise : 404 dans le périmètre sélectionné, même si l’utilisateur adhère aussi à l’autre entreprise. Une référence inconnue ou hors entreprise dans un corps est refusée uniformément avec 422 RELATIONSHIP_INVALID, sans révéler son existence ailleurs. Permission insuffisante dans l’entreprise : 403. Auth inaccessible ou réponse non vérifiable : 503, avant tout accès métier.

Aucun cache positif de rôle/adhésion ne sert de fallback. Chaque opération est validée au moment de son exécution. Un traitement différé initié par un utilisateur doit revérifier l’autorisation avant un nouvel effet métier ; il ne stocke pas le cookie pour l’exécuter plus tard. Les faits système déjà engagés ont une autorisation technique distincte et limitée (voir workflows), sans devenir un mécanisme d’usurpation d’utilisateur.

Le changement d’entreprise dans React annule les requêtes en vol, vide les snapshots/états dérivés de l’ancienne entreprise et change toutes les clés de cache (`companyId` inclus). Une réponse tardive de l’ancienne entreprise ne peut repeupler l’écran. Aucune collection locale historique n’est automatiquement fusionnée ou réaffectée.

Le provisionnement d’une projection locale exige une réponse Auth en ligne vérifiée ou un manifeste administratif approuvé. Un événement seul ne crée pas l’entreprise. La disponibilité du domaine et l’exhaustivité de ses références sont des états séparés de l’état actif d’entreprise. Les invitations supposent un mécanisme de provisionnement/vérification d’identité à valider ; elles ne marquent pas automatiquement les anciennes adresses comme vérifiées et n’impliquent aucune inscription publique libre.
