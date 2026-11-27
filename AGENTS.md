<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

# VICARTECH — NORMES DE DÉVELOPPEMENT ET QUALITÉ DU CODE

## OBJECTIF

À partir de maintenant, tout le code développé pour ViCarTech doit respecter les meilleures pratiques professionnelles de développement logiciel.

Je souhaite un code extrêmement lisible, explicite, structuré, maintenable et agréable à comprendre.

Le code doit pouvoir se lire presque comme un livre.

Un développeur qui découvre le projet doit pouvoir comprendre facilement :

- Ce que fait chaque composant.
- Ce que représente chaque variable.
- Pourquoi une fonction existe.
- Comment les données circulent.
- Où se trouvent les règles métier.
- Comment les différentes parties du système communiquent.

La lisibilité et la maintenabilité sont des priorités.

## 1. NOMMAGE EXPLICITE OBLIGATOIRE

Éviter les noms de variables abrégés ou ambigus.

INTERDIT :

```typescript
clients.map((c) => ...)
vehicles.map((v) => ...)
const x = ...
const d = ...
const tmp = ...
const res = ...
const arr = ...
const obj = ...
```

PRÉFÉRER :

```typescript
clients.map((client) => ...)
vehicles.map((vehicle) => ...)

const selectedClient = ...
const appointmentDate = ...
const invoiceTotal = ...
const availableVehicles = ...
const validationResult = ...
```

Les noms doivent décrire clairement leur rôle.

Éviter les abréviations inutiles.

Les abréviations métier reconnues, comme VIN, API, UUID, TPS et TVQ, sont autorisées lorsqu'elles améliorent la compréhension.

Les noms de variables, fonctions, classes et fichiers doivent être en anglais.

Les textes de l'interface doivent utiliser le système de traduction FR/EN.

## 2. FONCTIONS COURTES ET EXPLICITES

Chaque fonction doit avoir une responsabilité claire.

Éviter les fonctions qui effectuent plusieurs opérations indépendantes.

MAUVAIS :

```typescript
function processData(data: unknown) {
  // Validation
  // Calcul
  // Sauvegarde
  // Notification
}
```

PRÉFÉRER :

```typescript
function saveClient(client: Client): Promise<void> {
  validateClient(client);

  return clientRepository.save(client);
}
```

Séparer les responsabilités lorsque cela apporte réellement de la clarté.

Ne pas créer des fonctions inutiles uniquement pour réduire le nombre de lignes.

## 3. CODE AUTOEXPLICATIF

Le code doit expliquer son intention par :

- Des noms précis.
- Des fonctions bien définies.
- Une structure logique.
- Des conditions compréhensibles.
- Des types explicites.

Éviter les commentaires qui répètent simplement le code.

MAUVAIS :

```typescript
// Add 1 to count
count++;
```

PRÉFÉRER des commentaires expliquant les décisions importantes :

```typescript
// Preserve the original invoice totals because issued invoices
// must not change when tax settings are updated.
```

Les commentaires doivent expliquer principalement POURQUOI une décision a été prise, et non simplement CE QUE fait le code.

## 4. TYPESCRIPT STRICT

Respecter les meilleures pratiques TypeScript.

- Éviter `any`.
- Utiliser des interfaces et types explicites.
- Utiliser les unions discriminées lorsque pertinentes.
- Éviter les conversions de types non sécurisées.
- Gérer correctement les valeurs nulles.
- Utiliser des types métier pour les entités importantes.

Exemple :

```typescript
interface Client {
  id: string;
  firstName: string;
  lastName: string;
  email?: string;
}
```

Éviter les objets anonymes complexes lorsque des types réutilisables apportent de la clarté.

## 5. BONNES PRATIQUES REACT

Appliquer les bonnes pratiques React modernes.

- Composants à responsabilité claire.
- Hooks personnalisés lorsque pertinents.
- Séparation de l'interface et des règles métier.
- Éviter les composants géants.
- Éviter les effets secondaires inutiles.
- Respecter les règles des hooks.
- Ne pas dupliquer l'état dérivé.
- Utiliser `useMemo` et `useCallback` uniquement lorsqu'ils apportent un bénéfice réel.
- Éviter les abstractions prématurées.

Les composants doivent rester faciles à lire.

Exemple :

```tsx
function ClientList({ clients }: ClientListProps) {
  return (
    <List>
      {clients.map((client) => (
        <ClientListItem
          key={client.id}
          client={client}
        />
      ))}
    </List>
  );
}
```

## 6. BONNES PRATIQUES LARAVEL

Respecter les conventions Laravel.

- Contrôleurs simples.
- Form Requests pour la validation.
- API Resources pour les réponses JSON.
- Modèles Eloquent bien définis.
- Relations explicites.
- Policies pour les autorisations.
- Services métier lorsque nécessaires.
- Transactions pour les opérations critiques.
- Migrations structurées.

Ne pas placer toute la logique métier dans les contrôleurs.

Éviter également les couches d'abstraction inutiles.

Utiliser des noms de méthodes explicites.

Exemples :

```php
public function calculateInvoiceTotal(): int
```

```php
public function findVehiclesByClientId(string $clientId)
```

```php
public function approveEstimate()
```

## 7. PRINCIPES DE CONCEPTION

Appliquer avec discernement :

- Clean Code.
- SOLID.
- DRY.
- KISS.
- YAGNI.
- Separation of Concerns.
- Single Responsibility Principle.

IMPORTANT :

Ne pas compliquer le code pour respecter artificiellement un principe.

La simplicité et la lisibilité passent avant les abstractions.

## 8. STRUCTURE DES FICHIERS

Organiser les fichiers par fonctionnalité lorsque cela correspond à l'architecture existante.

Exemple :

```text
features/
  clients/
    components/
    hooks/
    models/
    repositories/
    services/
    pages/
```

Éviter les fichiers excessivement longs.

Ne pas découper artificiellement un composant simple en plusieurs fichiers.

Chaque fichier doit avoir un objectif identifiable.

## 9. GESTION DES ERREURS

Utiliser des messages explicites.

MAUVAIS :

```typescript
throw new Error("Error");
```

PRÉFÉRER :

```typescript
throw new Error(
  "Unable to save the client because the server is unavailable."
);
```

Les erreurs techniques doivent être séparées des messages présentés aux utilisateurs.

Ne pas exposer de renseignements confidentiels dans les messages d'erreur.

## 10. TESTS LISIBLES

Les tests doivent décrire clairement le comportement attendu.

Exemple :

```typescript
describe("Invoice total calculation", () => {
  it("calculates GST and QST for a taxable invoice", () => {
    // Arrange
    // Act
    // Assert
  });
});
```

Utiliser des noms de tests explicites.

Éviter les tests difficiles à comprendre ou dépendants de détails d'implémentation inutiles.

## 11. REFACTORISATION DU CODE EXISTANT

Pendant le développement, lorsqu'un fichier doit être modifié :

1. Examiner sa lisibilité.
2. Identifier les noms ambigus.
3. Renommer les variables lorsque cela améliore la compréhension.
4. Simplifier les conditions complexes.
5. Extraire les responsabilités distinctes si nécessaire.
6. Conserver le comportement existant.
7. Exécuter les tests pertinents.

Ne pas refactoriser toute l'application en une seule opération.

Privilégier les améliorations progressives.

Ne pas introduire de régressions.

## 12. CRITÈRES DE QUALITÉ

Avant de considérer une fonctionnalité comme terminée, vérifier :

- Les noms sont explicites.
- Les fonctions ont une responsabilité claire.
- Les types sont précis.
- Les règles métier sont faciles à identifier.
- Le code n'est pas inutilement complexe.
- Les erreurs sont correctement gérées.
- Les tests sont compréhensibles.
- Les fonctionnalités existantes fonctionnent.
- Le code respecte les conventions du projet.

## 13. DOCUMENTATION

Documenter les décisions architecturales importantes.

Éviter la documentation excessive qui répète simplement le code.

Privilégier :

- Une documentation claire.
- Des exemples concrets.
- Des explications des règles métier.
- Des descriptions des choix techniques.

## 14. RÈGLE PERMANENTE POUR VICARTECH

Ces normes doivent être appliquées à toutes les fonctionnalités futures :

- Clients.
- Véhicules.
- Rendez-vous.
- Interventions.
- Inventaire.
- Soumissions.
- Bons de travail.
- Factures.
- Paiements.
- Comptabilité.
- Backend Laravel.
- API REST.
- Tests.

La priorité est de produire un code professionnel, lisible et maintenable.

Ne pas privilégier la rapidité de génération au détriment de la qualité.

Ne pas utiliser de noms de variables d'une seule lettre, sauf dans les contextes mathématiques ou algorithmiques où leur signification est immédiatement évidente.

OBJECTIF FINAL :

Un code que l'on peut lire naturellement, comprendre rapidement et modifier avec confiance, même plusieurs années après son écriture.


## 15. DÉCISION ARCHITECTURALE PERMANENTE — SQL + JSON

Tous les futurs modules ViCarTech doivent suivre l’architecture hybride MariaDB relationnelle + JSON appliquée aux Clients et Véhicules.

- **Colonnes SQL** : identifiants, clés étrangères, relations, champs de recherche et de filtre fréquents, contraintes d’unicité, dates importantes, statuts métier et montants financiers. Conserver l’intégrité référentielle avec de véritables clés étrangères ; ne jamais stocker les relations uniquement dans JSON. Ne pas utiliser de flottants pour les calculs monétaires.
- **Documents JSON** : objets imbriqués, préférences, métadonnées et caractéristiques facultatives ou variables. Ne pas dupliquer les colonnes SQL dans JSON, ni utiliser JSON pour éviter de modéliser les données. Ne pas inventer de valeurs pour les propriétés absentes.
- **MariaDB 10.6** : le type JSON repose sur LONGTEXT avec validation JSON. Ne pas supposer les mécanismes internes de MySQL ou Cosmos DB. Ajouter des colonnes générées et des index uniquement lorsqu’une recherche réelle le justifie.
- **Contrat API** : garder des propriétés métier explicites et stables, indépendantes du stockage. Utiliser les casts Eloquent, les Form Requests et les API Resources. Valider strictement les propriétés autorisées et réutiliser les types métier TypeScript avec un typage strict, sans `any`.
- **Évolution et concurrence** : versionner les documents complexes avec `schemaVersion`. Tout changement de format exige une migration explicite et testée. Définir les règles de fusion et de suppression ; protéger les écritures concurrentes par des transactions, des verrous ou un contrôle de version adapté afin de ne pas écraser involontairement un document complet.
- **Migration des données** : conserver une sauvegarde, les identifiants, les dates, les objets imbriqués, les propriétés facultatives et les relations. Prévoir un import transactionnel et idempotent. Préserver les propriétés inconnues par une migration contrôlée et validée, sans les accepter arbitrairement dans les écritures ordinaires. Ne pas effacer les données localStorage.
- **Sécurité des migrations et tests** : privilégier les migrations additives ; ne pas exécuter `migrate:fresh`, `db:wipe`, `truncate` ou de suppressions destructrices sur les données existantes. Tester sur une base isolée les colonnes SQL, les documents JSON, leur validation et évolution, les mises à jour imbriquées, les relations et les reprises d’import. Vérifier la compatibilité MariaDB avant déploiement.

Référence détaillée : `docs/hybrid-data-architecture.md` dans le dépôt `garagetech-frontend` (accessible depuis le backend par `../garagetech-frontend/docs/hybrid-data-architecture.md`).

## 16. RÈGLE OBLIGATOIRE — BUILD, DÉPLOIEMENT ET VÉRIFICATION OFFICE

Après chaque correction, modification ou ajout ViCarTech, terminer les étapes pertinentes jusqu’à la vérification dans l’application déployée. Ne pas déclarer une correction terminée sur la seule base des tests locaux.

- Exécuter les tests ciblés et corriger les échecs.
- Pour toute modification React, exécuter `npm run build` dans `../garagetech-frontend`.
- Déployer le build React OFFICE dans `/home/vitalie/Projects/garagetech-backend/.private/office/frontend/` après compilation réussie. Cette source est montée en lecture seule par la Gateway; confirmer le mode de service dans `.private/office/compose.json` avant d’agir.
- Pour toute modification backend, reconstruire et redémarrer uniquement les services concernés, sans supprimer de volumes ni réinitialiser de données.
- Vérifier la Gateway, les services concernés, la version réellement servie sur `https://localhost:8443` et le parcours fonctionnel réel lorsque l’accès le permet.
- Préserver les données existantes. Ne jamais utiliser `docker compose down -v`, ni modifier HOME, A ou B sans demande explicite. Ne pas créer de commit ou push automatiquement.
- Si une permission, une configuration ou un outil manque, ne pas simuler l’étape. Indiquer clairement l’état de chaque étape et fournir la commande exacte nécessaire pour lever le blocage.

Chaque compte rendu final doit préciser : correction effectuée, tests réussis, compilation réussie, déploiement Docker effectué, fonctionnalité vérifiée dans l’application déployée et préservation des données existantes, chacun avec OUI/NON et la raison en cas de NON.
