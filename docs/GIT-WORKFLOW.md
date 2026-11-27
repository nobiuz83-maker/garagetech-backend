# Branches, environnements et promotion

## Branches permanentes

- `main` contient uniquement les versions approuvées et stables. Toute promotion vers la production part de cette branche.
- `develop` est la branche de travail quotidienne et reçoit les fonctionnalités et corrections terminées.
- `test` reçoit les changements prêts pour les tests d'intégration, les contrôles de sécurité et les migrations MariaDB.
- `release` est le candidat de mise en production. Il passe les validations complètes de PRODUCTION-LITE et d'artefact.

Les branches locales initiales `develop`, `test` et `release` sont créées à partir du commit actuellement présent dans `main`. Elles ne contiennent pas encore de promotion distincte.

Le chemin normal est :

```text
feature/<nom> → develop → test → release → main → production
```

Créer les branches de travail avec des noms descriptifs, par exemple `feature/client-search`, `fix/ci-laravel-testing` et `hotfix/session-cookie-expiry`. Les branches de fonctionnalité et de correction sont fusionnées dans `develop` après vérification. Les promotions entre branches permanentes passent par des Pull Requests.

## Contrôles CI et Pull Requests

`.github/workflows/ci.yml` s'exécute sur les branches permanentes, les branches `feature/*`, `fix/*` et `hotfix/*`, ainsi que sur les Pull Requests vers `develop`, `test`, `release` et `main`.

- `develop` et les branches de travail exécutent les huit validations Composer, les huit suites Laravel sur SQLite isolé et Gitleaks sur l'historique complet et les fichiers courants.
- `test` ajoute les tests ciblés d'authentification, de session, de CSRF et d'isolation des entreprises, puis exécute les migrations des sept services sur des bases MariaDB 10.11 jetables. Les cinq tests de sécurité MariaDB dédiés sont exécutés dans ces bases isolées.
- `release` et `main` exécutent tous ces contrôles, puis valident PRODUCTION-LITE, la compatibilité avec Ubuntu 24.04 / AMD64 / PHP 8.3 et l'archive source de déploiement.
- Un tag `vMAJOR.MINOR.PATCH` déclenche tous les contrôles de `main` et vérifie que le tag respecte SemVer et pointe vers un commit de `main`.

Les suites SQLite forcent `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` vide, le mailleur `array` et les sessions en mémoire. Chaque job génère sa propre `APP_KEY`. Les tests MariaDB utilisent une instance de service éphémère, un utilisateur généré et des privilèges limités à la seule base du job. Les tests n'utilisent aucune donnée de client réelle ni aucune ressource OFFICE ou de production.

`.gitleaksignore` ne référence qu'une détection historique précise d'un champ `DB_PASSWORD` vide dans un exemple de configuration. La ligne a été retirée du fichier courant; les nouvelles occurrences ne sont pas ignorées.

## Protections GitHub à activer

Ces règles sont préparées, mais elles ne sont pas configurées automatiquement. Dans **Settings → Rules → Rulesets**, créer les règles suivantes après avoir vérifié les noms de contrôles affichés par une première exécution CI :

1. Cibler `main`, `develop`, `test` et `release`; activer l'interdiction de suppression et de force push.
2. Pour `test`, `release` et `main`, exiger une Pull Request et les contrôles CI indiqués ci-dessous. Régler le nombre d'approbations requises à **0** et ne pas exiger l'approbation d'un propriétaire de code.
3. Pour `develop`, conserver la protection contre les suppressions et force push. Les Pull Requests vers `develop` restent recommandées, sans approbation obligatoire.

Contrôles obligatoires à sélectionner :

- `test` : les huit contrôles `Composer validation (...)`, les huit `Laravel tests (...)`, `Gitleaks secret scan`, les huit `Security tests (...)` et les sept `MariaDB migration and integration tests (...)`.
- `release` et `main` : les mêmes contrôles, plus `PRODUCTION-LITE and deployment artifact validation`.

Ne pas rendre `Semantic version tag validation` obligatoire pour les Pull Requests : ce contrôle s'exécute uniquement lors de la création d'un tag SemVer.

## Environnements logiques

Les environnements `DEVELOPMENT`, `TEST`, `STAGING` et `PRODUCTION` décrivent des niveaux de configuration et de validation. Ils ne demandent pas quatre serveurs Oracle Cloud :

- `DEVELOPMENT` correspond au travail local sur les branches `feature/*`, `fix/*` et `develop`.
- `TEST` utilise les runners GitHub Actions, SQLite en mémoire et des conteneurs MariaDB isolés remplis de données synthétiques.
- `STAGING` correspond à la validation de la branche `release`; aucun serveur supplémentaire n'est requis.
- `PRODUCTION` reste l'unique serveur Oracle Cloud prévu. Il utilise le profil PRODUCTION-LITE : Nginx natif, PHP-FPM `ondemand`, sept applications Laravel indépendantes, une instance MariaDB 10.11 locale, sept bases et le compte partagé `vicartech` limité à ces bases, sans privilèges globaux.

La configuration Docker existante reste disponible pour OFFICE et pour un futur serveur plus puissant. Le workflow de publication d'images reste manuel et limité à `main`; il construit les plateformes AMD64 et ARM64. Le workflow `deploy-production.yml` reste manuel, limité à `main` et cible le profil Docker `infrastructure/production/`. Il ne déploie pas PRODUCTION-LITE. Pour PRODUCTION-LITE, le déploiement initial reste la procédure manuelle documentée dans `infrastructure/production-lite/README.md`; le workflow GitHub Actions de référence dans ce dossier n'est pas activé et n'effectue aucune mutation distante.

Aucun push, création de branche ou Pull Request ne démarre la publication d'images ou un déploiement. Ces deux opérations ne sont accessibles que par `workflow_dispatch`, avec une confirmation explicite. Les clés, mots de passe, certificats et identifiants restent dans les secrets GitHub ou les fichiers privés du serveur; aucun `.env` réel ni jeu de données client ne doit entrer dans Git.

## Corrections urgentes

1. Créer `hotfix/<description>` depuis `main`.
2. Limiter le changement à la correction urgente et aux tests associés.
3. Ouvrir une Pull Request vers `main`; exécuter tous les contrôles requis.
4. Après fusion, créer un tag SemVer de correctif, par exemple `v2.4.1`, puis effectuer le déploiement par l'option manuelle prévue.
5. Réintégrer la correction dans `release`, `test` et `develop` par des Pull Requests successives depuis la branche située en amont. Résoudre et tester toute différence avant chaque fusion.

Ne pas utiliser `git push --force` pour corriger une branche partagée. Ne pas réécrire l'historique de `main`, `release`, `test` ou `develop`.

## Versionnement SemVer

Utiliser `MAJOR.MINOR.PATCH` :

- `MAJOR` augmente lorsqu'une version introduit une incompatibilité d'API ou de données.
- `MINOR` augmente pour une fonctionnalité rétrocompatible.
- `PATCH` augmente pour une correction rétrocompatible.

Les tags publics sont annotés et suivent la forme `vMAJOR.MINOR.PATCH`. Ils sont créés manuellement sur un commit validé de `main`. La CI vérifie leur format et leur appartenance à l'historique de `main`; elle ne crée pas de tag et ne déclenche pas de déploiement.

## Déploiement et retour arrière

Avant une mise en production, vérifier que les contrôles de `release` sont verts, promouvoir par Pull Request vers `main`, puis créer le tag approuvé. Le déploiement reste une action manuelle avec confirmation explicite. Le workflow Docker doit être lancé uniquement pour un hôte utilisant le profil Docker; il ne doit pas être utilisé pour le serveur PRODUCTION-LITE.

Pour PRODUCTION-LITE, conserver les versions dans des répertoires de release distincts, garder la version précédente disponible, sauvegarder les sept bases et le stockage privé, puis basculer les liens de release après validation. En cas de régression applicative, rebascule vers le répertoire précédent et vérifie les services. Une migration de données exige un plan de retour séparé : privilégier les migrations additives, ne pas exécuter de suppression générale, et ne restaurer qu'une sauvegarde vérifiée vers une cible vide selon `scripts/restore.sh`.

## Commandes de publication à exécuter après approbation

Les branches permanentes ont été créées localement. Une fois les changements examinés et commités sur `fix/ci-laravel-testing`, publier les références sans force push puis ouvrir une Pull Request vers `develop` :

```bash
git push -u origin develop test release
git push -u origin fix/ci-laravel-testing
gh pr create --base develop --head fix/ci-laravel-testing --title "ci: prepare branch promotion checks" --body "Validate Laravel tests, Composer dependencies, Gitleaks, MariaDB integrations, and release checks by branch."
```

Ne configurer les rulesets GitHub qu'après que les noms de contrôles requis apparaissent dans l'interface Actions. Aucun commit, push, merge ou changement de protection n'est effectué par cette préparation.
