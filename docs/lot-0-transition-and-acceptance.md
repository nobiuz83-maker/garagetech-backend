# LOT 0 — Impacts, transition et critères d’acceptation

Ce document prépare le travail futur. Aucune migration ou modification applicative n’est livrée dans le lot 0. Les environnements A/B et les sources sont hors du périmètre d’exécution.

## Impacts par service existant

| Élément actuel | Évolution nécessaire dans un lot ultérieur |
| --- | --- |
| Auth `User`, PermissionService, tables de session et whitelist des deux appelants | Ajouter entreprises/adhésions/rôles/permissions ; validation interne v2 contextualisée ; garder users.id et hashes ; étendre les whitelists sans wildcard. |
| `AuthenticatedActor(subject)` | Acteur immuable avec subject, companyId, membershipId, authorizationVersion et permissions vérifiées pour le handler. |
| Policies `subject === user_id` | Vérifier l’entreprise puis la permission et les invariants métier. Partage entre membres autorisés ; pas d’accès aux autres entreprises. |
| `owner_subjects`, `clients.user_id`, références et associations | Projections d’entreprise et FK composites ; propriété par company_id. Garder une trace de l’ancien propriétaire dans le registre de migration. |
| Horaires/verrouillage Appointments par utilisateur | Calendrier commun d’entreprise et verrou de calendrier. Détecter les collisions entre anciens calendriers avant activation. |
| Clients/Véhicules sans version optimiste | Ajouter version SQL et endpoints v2 If-Match sans modifier les handlers v1 actuels. |
| Importateurs et reçus v1 | Nouveaux manifests explicitant entreprise/adhésions, portée de clé, références et données historiques. Une ancienne preuve par utilisateur n’est pas une preuve d’isolation d’entreprise. |
| Journal d’archives | Ajouter entreprise et nouveau contexte d’acteur pour les nouvelles actions ; ne pas inventer d’acteur ou de lot historique. |
| Quatre squelettes Laravel | Démarrer directement avec contexte d’entreprise, permissions et FK locales appropriées. |

## Affectation des données, jamais implicite

Créer des entreprises exige une identité administrative approuvée. Un `user_id` actuel peut correspondre à plusieurs destinations ; ne pas l’utiliser comme règle automatique unique. Un manifeste approuvé affecte chaque UUID ou cohorte vérifiée à companyId, puis chaque compte à ses adhésions et rôles. Les domaines de courriel, noms similaires et présence dans une même base ne prouvent pas l’appartenance commune.

Le champ historique `user_id` décrit une propriété actuelle, **pas nécessairement le créateur**. Ne pas le renommer aveuglément `created_by_user_id`. Un créateur inconnu reste null/inconnu ; le propriétaire historique reste traçable dans un registre SQL de migration à accès restreint. Les nouvelles actions portent un acteur vérifié et une entreprise.

Migration future additive : colonnes/projections/index ajoutés sans effacer l’existant ; préparation sur une nouvelle copie isolée ; backfill selon manifeste ; contrôle de toutes les associations et FK ; passage à NOT NULL seulement après preuve d’exhaustivité. Le DDL MariaDB 10.6 n’est pas une transaction globale : prévoir reprise et vérification de chaque étape. Une ressource non affectée ou ambiguë bloque l’entreprise concernée ; pas de valeur d’entreprise par défaut inventée.

Conserver UUID, dates, statuts, documents JSON et relations. Les deux clients et six véhicules archivés restent archivés dans toute copie et toute étape ; aucune commande de restauration ne fait partie de cette transition. Conserver les sources et A/B. Si un futur jeu nécessite une restauration d’archive, cette décision demande une autorisation distincte, sans effet implicite de la migration d’entreprise.

Les deux comptes ne reçoivent aucun rôle Owner/Administrator automatiquement. La désignation du premier propriétaire, des adhésions et des permissions initiales nécessite une approbation explicite. Les conflits de séquences, codes catalogue, horaires, réservations et doublons métier après regroupement d’utilisateurs doivent être recensés ; aucune fusion silencieuse de fiches ou renumérotation historique.

## React et données locales

Les repositories v1 et les indicateurs actuels restent actifs jusqu’à un futur plan approuvé. Préparer des adaptateurs v2 typés et des schémas Zod fermés ; ne pas réécrire les composants ou les calculs en masse. Ajouter les capacités d’entreprise au contexte d’interface ; les boutons reflètent les permissions mais le serveur reste autoritaire.

Toutes les clés React Query/snapshots/requêtes en vol sont liées à companyId. Un changement de sélection annule les requêtes précédentes, vide les états métier dérivés et refuse les réponses tardives hors contexte. Aucun token d’accès dans localStorage. La langue peut rester locale. Un éventuel dernier choix d’entreprise est une préférence non fiable, revalidée avant toute requête.

Les collections localStorage d’interventions, inventory, commercial, accounting et tarifs ne sont pas automatiquement « rattachées à l’utilisateur connecté ». Elles peuvent provenir de plusieurs utilisateurs/entreprises et comporter des fusions historiques. Exporter après autorisation, sauvegarder, classifier et affecter par manifeste. Les fichiers IndexedDB comptables nécessitent un paquet de fichiers + manifeste/empreintes, pas un simple JSON de chaînes.

Préserver `legacyProperties` dans le chemin d’import contrôlé sans leur donner un rôle de permission ou de relation. Revoir les métadonnées historiques non classifiées avant de les rendre visibles à tous les membres de l’entreprise. Les montants en dollars et quantités d’anciens formats exigent une conversion décimale explicite et testée : cents pour les montants, milli-unités pour le stock, chaînes décimales exactes pour les quantités commerciales. Les anciens prix unitaires d’intervention sous le cent doivent conserver leur ratio historique et leur règle d’arrondi de ligne, pas subir un arrondi prématuré au cent. Toute valeur non représentable bloque l’import plutôt que perdre sa précision ; pas de multiplication binaire naïve. Les identifiants non UUID des catalogues/comptes restent reconnus ou passent par une correspondance conservée.

Settings devient une façade des propriétaires définis dans les frontières. DocumentEditor conserve son ergonomie, mais les modifications de diagnostic/temps/pièces sont dirigées vers Interventions ; Billing reçoit une projection d’exécution, pas une deuxième source modifiable. Une soumission de formulaire multi-services peut être en attente/réconciliation : ne pas annoncer une transaction globale inexistante.

## Matrice minimale de tests du lot suivant

| Domaine | Scénarios obligatoires |
| --- | --- |
| Entreprise | Deux employés de même entreprise partagent selon leurs permissions ; deux entreprises sont isolées ; UUID connu d’une autre entreprise ⇒ 404 ; aucune mutation/binding avant Auth valide. |
| Multi-adhésion | Rôle technicien A/comptable B sans union de droits ; header changé, réponse Auth d’une autre entreprise, contexte absent et réponse tardive React refusés. |
| Révocation | Suspension/role retiré entre deux requêtes et avant un job ; pas de cache positif ; dernier propriétaire protégé ; tentative de self-escalation refusée. |
| Machine | Certificat/CA/nom/expiration, signature v2, companyId/purpose/permissions modifiés, nonce rejoué, réponse Auth invalide ; indisponibilité ⇒ 503 sans SQL métier. |
| Références | Réservation/archivage concurrents, source non importée, commande après timeout, référence interentreprise, historique annulé conservé. |
| Stock | Deux consommateurs sur le dernier stock, commande répétée, effets multi-lignes atomiques, réponse perdue et compensation autorisée ; pas de déduction à la facturation. |
| Documents | Séquences concurrentes par entreprise, révision/approbation figée, facture immuable, doublon de paiement/conversion/remboursement, cents exacts. |
| Comptabilité | Fait répété/hors ordre, écritures équilibrées, contenu divergent pour la même source, période clôturée, reprise après panne outbox/inbox. |
| Idempotence/version | Même clé/corps rejoué, clé réutilisée, If-Match périmé, retry après succès perdu, nouvelle session même acteur, rôle changé avant rejeu ; aucune fuite de réponse financière. |
| Migration | Affectation ambiguë, horaires incompatibles, UUID/code en conflit, source JSON inconnue, reprise après DDL partiel, conservation des 8 archives et des fichiers. |
| React | Même formulaires FR/EN, affichage sans champs financiers non autorisés, sélection d’entreprise, isolation des caches, erreurs 401/403/412/419/503 distinctes ; aucun effacement local. |

Tests Laravel Feature/Policy et métier, contrats consommateurs/producteurs, MariaDB 10.6 isolée pour contraintes/verrous/DDL, React et E2E sur de nouveaux environnements dédiés. Les tests A/B historiques restent des preuves du modèle utilisateur v1 ; ils ne sont pas réinterprétés comme tests du modèle d’entreprise.

## Porte de sortie du lot 0

Documents cohérents, contrats différenciés de v1, catalogue de permissions explicite, décisions ouvertes identifiées. Les exemples et références des contrats doivent être contrôlés hors ligne sans démarrer d’application, contacter une base ou installer de dépendance.

L’ordre ultérieur commence par le socle d’identité d’entreprise et les gardes v2, après autorisation du lot 1. Les choix d’affectation réelle, de rôles initiaux, de calendriers, de fiscalité et toute opération de données resteront soumis à leur validation propre. Aucun accord sur des documents n’autorise un backfill ou un changement de routage.
