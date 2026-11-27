# LOT 0 — Conventions REST, fiabilité et sécurité

## Versionnement et compatibilité

Les surfaces cibles utilisent `/api/v2` (Gateway) et `/api/internal/v2` (réseau privé). Les routes v1, leurs permissions larges, leurs corps `version` et erreurs existantes ne sont pas modifiés dans ce lot. Les contrats v2 sont une évolution explicite de la propriété utilisateur vers l’entreprise ; ils ne sont pas une promesse d’isolation d’entreprise pour une ancienne route v1.

Avant toute ouverture v2, les gardes, données et imports du service doivent être prêts ensemble. Une requête v2 ne retombe jamais sur un handler v1 non cloisonné. Les anciennes routes d’écriture doivent être désactivées ou bridgées par un adaptateur d’entreprise explicitement vérifié avant une future activation ; jamais de double écriture. Les adaptateurs React seront développés ultérieurement derrière des indicateurs de fonctionnalité, sans changer les fonctionnalités actuellement actives.

Réponses ressources : `{ data: ... }`, collections `{ data: [...], meta: {page, perPage, total}, links: {next, previous} }`, pagination maximale 100. JSON camelCase, identifiants utilisateurs BIGINT sous forme de chaînes, UUID des entités conservés. Les identifiants historiques de catalogue/emplacement/compte qui sont des chaînes restent compatibles. Création 201, mutation 200, archivage métier 200 avec ressource versionnée. Aucune suppression physique par les APIs métier v2.

Dates/heures de calendrier : date civile `YYYY-MM-DD`, heure `HH:mm`, fuseau de calendrier explicite, règle actuelle Toronto conservée jusqu’à décision. Instants et événements : UTC ISO8601 avec millisecondes. Les montants sont des cents entiers bornés à 2^53-1 dans JSON ; aucune coercition de chaîne monétaire ou arrondi silencieux. Les quantités de stock sont des milli-unités entières (`quantityScale=1000`). Les quantités commerciales restent des chaînes décimales exactes : le code existant accepte des fractions plus fines que 0,001. Une opération de stock refuse une conversion non représentable, sans arrondi silencieux. Les taux/ratios sont des numérateurs/dénominateurs entiers sérialisés comme chaînes, traités sans flottant. Un document JSON complexe exige `schemaVersion`; champs inconnus refusés en écriture ordinaire. Les anciennes propriétés ne transitent que par un import de maintenance approuvé et versionné.

## Concurrence

Chaque agrégat v2 mutable expose un `version` SQL positif et un ETag fort `"v7"`. PATCH et commandes sur ressource existante exigent `If-Match`. Absence : 428 ; version périmée : 412. Le contrôle est fait sous transaction/verrou avant la mutation. Les versions du document et les révisions commerciales d’approbation ont des fonctions distinctes. Les nouveaux agrégats commencent à version 1.

Les FK composites, index uniques d’entreprise et verrous protègent les invariants entre lignes. Ne pas garder un verrou SQL pendant un appel réseau. Le stock et les séquences financières requièrent des verrous locaux explicites ; une version seule ne protège pas une réservation multi-lignes. Les modifications de JSON définissent des règles par chemin : omission conserve ; `null` efface uniquement les champs explicitement nullables ; tableaux remplacés explicitement ; le document `data` n’est jamais remplacé aveuglément. Les conflits et versions de schéma inconnues bloquent sans écrasement.

Les entrées de journal/mouvements/avoirs restent append-only. Une correction produit un fait inverse ou un nouveau document ; elle ne modifie pas une ancienne écriture.

## Idempotence

Toute mutation métier v2 exige `Idempotency-Key` (16–128 caractères ASCII de `[A-Za-z0-9._:-]`, sans donnée personnelle). Les endpoints de session déjà existants conservent leur comportement v1. Une clé représente une intention ; React garde la même clé et les mêmes octets de requête lors d’un retry. Changer l’intention exige une nouvelle clé après résolution du résultat précédent.

Portée unique SQL : `(company_id, actor_subject_id, operation_id, key)`, ou `(company_id, producer_service, operation_id, key)` pour une commande système autorisée. Empreinte : SHA-256 de méthode + cible brute + companyId + acteur + If-Match (ou `-`) + corps brut, séparés par LF. Le cookie, CSRF, nonce et requestId ne font pas partie de cette empreinte : leur renouvellement ne doit pas créer une nouvelle intention. Un corps sérialisé autrement est considéré différent ; le client ne le reconstruit pas pendant un retry.

Pipeline : authentifier et autoriser de nouveau → établir l’entreprise → consulter/verrouiller la clé → si réponse engagée et contexte de permissions inchangé, rejouer le résultat métier → sinon vérifier version et règles → écrire données, reçu et outbox dans la même transaction. Les cookies, secrets et headers de session ne sont jamais stockés dans le reçu.

- Même clé + même empreinte : aucun effet supplémentaire, résultat métier engagé rejoué.
- Même clé + autre empreinte : 409 `IDEMPOTENCY_KEY_REUSED`.
- Exécution en cours : 409 `OPERATION_IN_PROGRESS`, `Retry-After` borné.
- Autorisation révoquée : 403, sans rejeu du contenu historique.
- Contexte de permissions/champs autorisés changé depuis le reçu : 409 `IDEMPOTENCY_CONTEXT_CHANGED`; consulter la ressource autorisée et réconcilier, sans nouvelle exécution automatique ni fuite d’ancien contenu.
- Timeout/réponse perdue : résultat inconnu, retry uniquement avec la même clé. Ne jamais supposer un rollback distant.

Rétention proposée du reçu HTTP : au moins 7 jours. La déduplication métier des factures émises, conversions, règlements, remboursements, mouvements et écritures reste permanente par identifiant de commande/source et contraintes uniques. L’expiration d’une clé HTTP ne rend pas une opération financière rejouable. Les erreurs avant engagement peuvent être réessayées selon leur code ; les 503 ne sont pas conservés comme succès définitifs.

## Archivage et références

Archivage = marqueur SQL, version et événement d’audit avec entreprise et acteur vérifiés. Les deux clients et six véhicules historiques doivent rester archivés : aucune migration d’entreprise, permission ou import ne les réactive. Une provenance historique inconnue reste inconnue.

Les nouvelles références sont enregistrées auprès du propriétaire de la ressource avant l’engagement local, sous le même verrou que son archivage. Un accusé de référence porte entreprise, ressource référencée, service/ressource consommateurs et révision. Le registre est exhaustif pour chaque domaine déclaré prêt ; domaine non importé, référence indéterminée ou indisponibilité ⇒ archivage refusé (503 ou conflit métier documenté), jamais autorisation implicite.

La référence porte sur l’histoire, pas seulement l’état actif. Annulation, facturation ou archivage d’un dossier ne supprime pas automatiquement sa référence. Pas d’endpoint de suppression de référence dans le MVP. Une réservation conservatrice sans engagement local après panne exige une réconciliation auditée. Les documents émis et écritures ne sont pas effacés par un archivage ; l’archivage de brouillons reste une action distincte.

## Erreurs v2

Format : `{ error: { code, messageKey, requestId, fields? } }`. `fields` mappe les chemins aux clés de traduction ; pas de SQL, stack trace, cookie, hash ou profil d’une autre entreprise. Les clés FR/EN seront ajoutées avec les futurs écrans, sans texte technique présenté comme message utilisateur.

| HTTP | Codes représentatifs |
| --- | --- |
| 400 | `COMPANY_CONTEXT_REQUIRED`, `COMPANY_CONTEXT_INVALID`, `INVALID_REQUEST_ID` |
| 401 | `UNAUTHENTICATED` ; sur réseau interne `MACHINE_AUTHENTICATION_FAILED`, distinct d’une session expirée |
| 403 | `FORBIDDEN`, `COMPANY_ACCESS_DENIED` |
| 404 | `RESOURCE_NOT_FOUND` dans l’entreprise sélectionnée |
| 409 | `STATE_CONFLICT`, `HISTORY_REFERENCED`, `IDEMPOTENCY_KEY_REUSED`, `OPERATION_IN_PROGRESS`, `IDEMPOTENCY_CONTEXT_CHANGED` |
| 410 | `INVITATION_EXPIRED_OR_REVOKED` sans détail de compte |
| 412 / 428 | `VERSION_CONFLICT` / `PRECONDITION_REQUIRED` |
| 419 | `CSRF_TOKEN_MISMATCH` |
| 422 | `VALIDATION_FAILED`, `RELATIONSHIP_INVALID`, `UNSUPPORTED_SCHEMA_VERSION` |
| 429 | `RATE_LIMITED`, Retry-After |
| 503 | `AUTHORIZATION_UNAVAILABLE`, `DEPENDENCY_UNAVAILABLE`, `DEPENDENCY_TIMEOUT`, `DEPENDENCY_INVALID_RESPONSE`, `MIGRATION_NOT_READY` |
| 500 | `INTERNAL_ERROR` pour une faute propre inattendue ; ne pas masquer toutes les erreurs de programmation en 503 |

Une mauvaise preuve machine reçue d’un dépendant attendu est une indisponibilité pour l’utilisateur (503), pas une déconnexion 401. Un refus métier authentifié 403/404/409/422 est propagé seulement après validation de son enveloppe et du contexte. Budget proposé : connexion interne 500 ms, total 2 s ; Gateway 2 s/5 s. Aucun retry automatique d’écriture sans idempotence, pas de retry caché de Gateway. Toute commande mutatrice interrompue reste à réconcilier.

## Signature interne v2

mTLS vérifie CA, dates, SAN du serveur, usage de certificat et identité de l’appelant. CN/service attendu et whitelist doivent correspondre. Clés HMAC distinctes par relation/appelant, séparées des clés TLS et de session. La Gateway publique ne signe pas les demandes utilisateur à la place d’un microservice et ne publie pas `/api/internal/*`.

Headers signés : X-Internal-Service, X-Internal-Timestamp (Unix secondes), X-Internal-Nonce (20–80 caractères URL-safe), X-Internal-Signature (HMAC-SHA256 hex), X-Company-ID, X-Request-ID. Canonique UTF8 sans LF final, dans cet ordre :

```text
serviceName
METHOD
rawRequestTargetIncludingQuery
companyId-or--
requestId
unixTimestamp
nonce
contentType-or--
idempotencyKey-or--
ifMatch-or--
SHA256(rawCookieHeader-or-empty)
SHA256(rawXsrfHeader-or-empty)
SHA256(rawBody)
```

Chaque ligne contient la valeur, pas son nom. `or--` signifie un seul tiret `-`. Les headers signés sont uniques, sans CR/LF ; pas de normalisation après signature. Le destinataire utilise les octets réellement transmis. L’URL brute et le corps ne sont pas re-sérialisés pour vérifier. Les cookies transmis sont limités à la session autorisée ; ils ne figurent jamais en clair dans logs ou événements.

Fenêtre temporelle proposée : ±30 secondes. Nonce partagé atomiquement dans la base du destinataire pendant 90 secondes, clé incluant le service appelant. Rejeu, signature/certificat expiré ou contexte différent refusés. V2 n’est jamais vérifié avec la canonique v1. La réponse est authentifiée par TLS et validée strictement (`companyId`, permissions demandées, requestId, schéma, types) ; `allowed` doit être exactement le booléen `true`.

Une commande système ne contient ni cookie ni CSRF ; leurs empreintes sont celles de la chaîne vide. Elle exige un endpoint et une capacité techniques explicitement autorisés, sans inventer un utilisateur. Voir les faits durables et limites de reprise dans les workflows.

Les GET internes qui ne demandent que des permissions de lecture utilisent Auth GET (liste comma-separated explicitement parsée). Une commande ou une lecture liée à une permission mutatrice utilise Auth POST et CSRF. L’introspection Auth ne crée pas un effet métier et ne requiert pas de reçu d’idempotence ; elle garde sa signature/nonce propre à chaque tentative.
