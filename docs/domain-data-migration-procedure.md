# Migration de maintenance Clients / Appointments — sans activation automatique

> Les preuves des répétitions réelles et les empreintes associées sont conservées dans l’espace privé local et ne font pas partie de la documentation publique.

L’importateur et les répétitions synthétiques sont décrits ici. Les rapports d’exécution et d’activation liés à des environnements réels sont conservés localement dans `.private/prepublication/`.

## Périmètre et préconditions

L’importateur est un outil CLI livré indépendamment dans chacune des deux applications Laravel. Il n’ouvre que la connexion MariaDB de son application. Les quatre tables sources logiques sont `clients`, `vehicles`, `appointments`, `appointment_schedules`. Le lot contient une liste approuvée d’identifiants propriétaires (`ownerSubjects`), jamais les profils utilisateurs, hashes de mots de passe ou sessions Auth. Aucun utilisateur Auth n’est créé ni remplacé.

Prévoir une fenêtre de gel des écritures des deux domaines et des changements d’identité concernés. Ne pas faire de double écriture. Obtenir l’autorisation de préparer le véritable instantané et sa sauvegarde, puis une autorisation distincte de basculement. Les commandes ci-dessous sont une procédure future, pas une activation autorisée par ce document.

Utiliser deux bases candidates neuves et vides, avec des comptes MariaDB distincts limités à leur propre schéma. Les commandes refusent les noms des bases sources actuelles : elles acceptent `vicartech_candidate_<suffixe>` ou `vicartech_test_<suffixe>`. Les noms candidats doivent être conservés dans la configuration approuvée si ces bases sont ultérieurement activées. Ne pas renommer ou réutiliser une base source pour contourner ce contrôle. Une cible non vide sans reçu identique est refusée ; pas de fusion ni d’écrasement.

## 1. Sauvegarde et instantané approuvé

1. Inventorier les UUID, propriétaires, dates, statuts, documents JSON, archives et relations, en lecture seule. Vérifier la correspondance des identifiants propriétaires avec Auth sans extraire leurs profils. Conserver les huit archives existantes en l’état ; leur provenance reste inconnue.
2. Sous gel des écritures, produire un instantané logique cohérent des quatre tables avec tous leurs enregistrements, y compris les archives, et les seuls identifiants propriétaires approuvés. L’export doit utiliser une transaction de lecture cohérente, la même version temporelle pour les quatre tables et un contexte UTC connu. Le générateur de lots ne se connecte pas à la source : l’extraction réelle doit être exécutée par l’opérateur autorisé et revue avant utilisation.
3. Protéger les fichiers métier (ils contiennent des données personnelles) par un répertoire privé et des permissions 0700/0600. Chiffrer la sauvegarde logique, enregistrer SHA-256 et manifeste, conserver la clé séparément dans le dispositif d’exploitation approuvé. Tester le déchiffrement et la restauration dans d’autres bases candidates ; comparer le contenu intégral, pas seulement les nombres de lignes. Les sources conservées ne remplacent pas cette sauvegarde vérifiée.
4. Les répétitions automatisées `rehearse-domain-backup.py` sont volontairement limitées aux projets Docker fictifs autorisés : elles n’autorisent ni ne réalisent une sauvegarde réelle. Le test vérifie AES256, rejet d’une altération, restauration sur une nouvelle instance et égalité des checksums, sans utilisateurs/sessions copiés ni volume supprimé.

Format d’entrée : `schemaVersion: 1`, `ownerSubjects` (identifiants BIGINT canoniques sous forme de chaînes), `tables` (les quatre tables SQL logiques). Colonnes détaillées dans `validate-domain-migration.py`. Les UUID doivent déjà être canoniques ; les identifiants et dates ne sont pas régénérés. Le format exige les propriétés SQL déclarées et rejette les propriétés supplémentaires. Les dates sans fuseau doivent provenir de l’export UTC documenté.

Le validateur rejette doublons UUID/propriétés JSON, relations orphelines, propriétaires incohérents, statuts/horaires invalides, versions inconnues, nombres JSON non représentables sans perte et dates incompatibles. Les propriétés JSON inconnues sont conservées par ce chemin de maintenance contrôlé, sans les autoriser dans les écritures API ordinaires. Un document JSON `null` reste `null`. Les objets vides, tableaux, booléens, nombres et valeurs nulles conservent leur type. Une adresse SQL historique est déplacée vers JSON uniquement sans conflit ; un conflit SQL/JSON bloque au lieu de faire disparaître une valeur.

Les colonnes historiques restent aux précisions actuelles : millisecondes pour création/modification, secondes pour archivage. Une précision supérieure ou une date hors plage MariaDB 10.6 bloque et exige une migration additive explicitement testée ; aucune troncature silencieuse.

## 2. Préparation des lots, sans connexion SQL

```bash
python3 infrastructure/docker/prepare-domain-import.py \
  --source /chemin/prive/source-approuvee.json \
  --output /chemin/prive/nouveau-lot
```

Le répertoire de sortie doit être nouveau. Conserver ensemble les deux fichiers métier, leur manifeste et leurs empreintes approuvées ; sauvegarder l’ensemble chiffré. Le manifeste lie le même `migrationId`, l’empreinte source, les empreintes de fichiers et l’empreinte de toutes les références historiques. L’empreinte des références porte sur les quadruplets rendez-vous/client/véhicule/propriétaire, triés, indépendamment de l’ordre SQL.

Le fichier Clients contient clients, véhicules, propriétaires de rendez-vous et références permanentes. Appointments contient les associations locales, rendez-vous et horaires. Toutes les références des rendez-vous présents dans l’instantané sont préchargées, même si leur rendez-vous ou leurs ressources sont archivés. Les anciennes associations remplacées sans journal dans la source ne sont pas reconstructibles : ne pas prétendre les reconstituer.

## 3. Import et vérification, application par application

Appliquer uniquement les migrations additives des bases candidates. Laisser `APPOINTMENT_REFERENCE_REGISTRY_READY=false`. Mettre **les deux** applications candidates en maintenance avec `php artisan down --no-interaction`. Pour une écriture CLI autorisée, fournir `MAINTENANCE_IMPORT_ENABLED=true` uniquement à l’environnement de maintenance, avec les secrets de la base propre. Le seul réglage `APP_ENV=testing` ne contourne pas la maintenance hors du runner PHPUnit.

Dans chaque application indépendante :

```bash
php artisan domain:import /chemin/lot-du-domaine.json --sha256=EMPREINTE_APPROUVEE
```

Cette commande planifie sans écrire. Après revue explicite du plan et des empreintes, l’opérateur autorisé peut lancer la même commande avec `--apply`. Importer Clients d’abord, puis Appointments. Il s’agit d’un chemin de maintenance, pas d’un assouplissement des APIs : l’API ordinaire continue à refuser un nouveau rendez-vous sur une ressource archivée.

Chaque import verrouille le domaine, insère en transaction ses projections et données, écrit un reçu local, puis compare toutes les colonnes et lignes stockées avant commit. Une erreur SQL ou une divergence annule l’ensemble local, reçu inclus. Un second import avec le même lot vérifie le contenu et ne réécrit aucune ligne. Un fichier modifié, reçu modifié ou contenu ayant évolué provoque un refus. La reprise après échec du deuxième service conserve le premier, puis vérifie et reprend le lot inchangé ; aucune transaction distribuée n’est annoncée.

Produire les deux reçus par une nouvelle exécution avec `--verify` et conserver leurs sorties JSON privées. Puis :

```bash
python3 infrastructure/docker/verify-domain-import-receipts.py \
  --manifest /chemin/lot/manifest.json \
  --clients /chemin/recu-clients.json \
  --appointments /chemin/recu-appointments.json
```

Les deux bases doivent correspondre intégralement aux lots approuvés. Le vérificateur exige des identifiants et empreintes communs, le nombre exhaustif de références et des vérifications locales réussies. Il n’active ni le registre ni le routage.

## 4. Conditions d’activation

- Sauvegarde réelle déchiffrable et restaurée avec succès sur une nouvelle cible ; preuves et clés conservées séparément.
- Zéro perte ou divergence inexpliquée de UUID, propriétaire, date, statut, JSON ou relation ; aucune ligne supplémentaire ignorée.
- Deux reçus vérifiés, même source et même ensemble de références ; compteurs incluant les rendez-vous annulés et archivés ; aucune réservation non réconciliée.
- Décision explicite de conserver les huit archives en l’état ; procédure manuelle disponible pour une demande ultérieure. Leur restauration n’est pas nécessaire à la migration et ne doit pas être déclenchée automatiquement.
- Scénarios React/API/sessions/CSRF/mTLS et pannes réussis sur ces véritables cibles candidates ; comptes SQL étrangers refusés.
- Autorisation explicite de l’utilisateur avant toute modification du registre ou du routage actif. Ne retirer la maintenance qu’après validation du plan d’activation.

## 5. Retour arrière sans suppression

Avant réouverture des écritures candidates : conserver les sources inchangées et le routage précédent ; abandonner l’activation ou revenir au routage approuvé. Conserver les bases candidates, volumes, sauvegardes et reçus. Aucun `down` de migration, `migrate:fresh`, `truncate` ou effacement.

Après des écritures candidates : geler les deux domaines, sauvegarder les états source et cible, inventorier et réconcilier le delta par UUID, versions et dates, y compris archives et restaurations. Ne jamais écraser les nouvelles écritures avec une ancienne sauvegarde. Faire approuver la réconciliation et le routage de retour. Vérifier la continuité des identités et sessions Auth selon la configuration retenue.

Une restauration manuelle volontaire change le contenu par rapport au lot d’import. La vérification de l’ancien lot doit alors refuser : c’est attendu. Pour cette migration, les archives restent inchangées. Une exception autorisée avant activation nécessiterait un nouveau plan de validation des différences auditées ; ne jamais falsifier l’instantané source ou un reçu pour obtenir un résultat vert.
