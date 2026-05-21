# Custom Entity (Datenobjekt) in Sulu 3 erstellen

Vollständige Anleitung am Beispiel `ContactPerson`. Alle Pfade relativ zum Projektstamm.

---

## Übersicht: Was wird benötigt

| # | Datei / Ort | Zweck |
|---|---|---|
| 1 | `src/Entity/MyEntity.php` | Doctrine-Entity mit Validator-Constraints |
| 2 | `src/Repository/MyEntityRepositoryInterface.php` | DIP-Abstraktion |
| 3 | `src/Repository/MyEntityRepository.php` | Doctrine-Implementierung |
| 4 | `src/MyEntity/MyEntityManagerInterface.php` | Business-Logik-Abstraktion |
| 5 | `src/MyEntity/MyEntityManager.php` | Hydration + Validierung + Persistenz |
| 6 | `src/Admin/MyEntityAdmin.php` | Sulu-Navigation, Views, Security Contexts |
| 7 | `src/Controller/Admin/MyEntityController.php` | REST-Controller (CRUD) |
| 8 | `src/Content/PropertyResolver/MyEntityPropertyResolver.php` | Sulu 3 Content-Layer (Seiten-Rendering) |
| 9 | `src/Content/ResourceLoader/MyEntityResourceLoader.php` | Sulu 3 Content-Layer (Batch-Loading) |
| 10 | `config/lists/my_entities.xml` | Spalten der Admin-Listenansicht |
| 11 | `config/forms/my_entity.xml` | Felder des Admin-Formulars |
| 12 | `config/routes/admin_api.yaml` | API-Routen unter `/admin/api/` |
| 13 | `config/packages/sulu_admin.yaml` | Resource-Routen + Field-Type registrieren |
| 14 | `config/services.yaml` | Interface-Bindings + Controller-Tag |
| 15 | `translations/admin.en.yaml` | Übersetzungsschlüssel |
| 16 | Doctrine Migration | Tabelle anlegen |

---

## Schritt für Schritt

### 1. Entity

```php
// src/Entity/ContactPerson.php
#[ORM\Entity]
#[ORM\Table(name: 'contact_person')]
class ContactPerson
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank, Assert\Length(max: 100)]
    private string $firstName = '';

    // ... weitere Felder mit passenden Assert-Constraints
}
```

**Wichtig:** Symfony-Validator-Constraints direkt auf den Eigenschaften — die Doctrine-Spaltenlängen müssen mit den `Assert\Length(max:)` übereinstimmen.

---

### 2. Repository Interface + Implementierung

```php
// src/Repository/ContactPersonRepositoryInterface.php
interface ContactPersonRepositoryInterface
{
    public function findById(int $id): ?ContactPerson;
    public function findAll(): array;
    public function create(): ContactPerson;
    public function save(ContactPerson $contactPerson): void;
    public function remove(ContactPerson $contactPerson): void;
}
```

```php
// src/Repository/ContactPersonRepository.php
class ContactPersonRepository extends ServiceEntityRepository implements ContactPersonRepositoryInterface
{
    // save() und remove() rufen flush() auf — dokumentieren, dass die gesamte UoW geflusht wird
}
```

---

### 3. Manager Interface + Implementierung

```php
// src/MyEntity/ContactPersonManager.php
class ContactPersonManager implements ContactPersonManagerInterface
{
    public function create(array $data): ContactPerson
    {
        $entity = $this->repository->create();
        $this->hydrateData($entity, $data);   // Daten zuweisen (ohne Seiteneffekte)
        $this->assertValid($entity);           // Validierung separat
        $this->repository->save($entity);
        return $entity;
    }

    private function hydrateData(...): void { /* nur Setter aufrufen */ }
    private function assertValid(...): void  { /* nur validieren, wirft ValidationFailedException */ }
}
```

**Wichtig:** `hydrateData()` und `assertValid()` trennen (CQRS/SRP). `mediaId` sicher casten: `is_numeric($raw) ? (int) $raw : null`.

---

### 4. Admin-Klasse

```php
// src/Admin/ContactPersonAdmin.php
class ContactPersonAdmin extends Admin
{
    public const RESOURCE_KEY   = 'contact_persons';
    public const LIST_KEY       = 'contact_persons';
    public const FORM_KEY       = 'contact_person';
    public const LIST_VIEW      = 'app.contact_person.list';
    public const ADD_FORM_VIEW  = 'app.contact_person.add_form';
    public const EDIT_FORM_VIEW = 'app.contact_person.edit_form';
    public const SECURITY_CONTEXT = 'app.contact_persons';

    // configureNavigationItems(): Menüpunkt registrieren
    // configureViews(): List + Add + Edit Views registrieren (permission-gated)
    // getSecurityContexts(): VIEW/ADD/EDIT/DELETE für Rollen-Verwaltung registrieren
}
```

**Navigation unter einem gemeinsamen Eltern-Menüpunkt** (z.B. "Datenobjekte"):

```php
public function configureNavigationItems(NavigationItemCollection $collection): void
{
    // Eltern-Item lazy erstellen (falls noch nicht von einem anderen Admin angelegt)
    if (!$collection->has('app.data_objects')) {
        $parent = new NavigationItem('app.data_objects');
        $parent->setPosition(45);
        $parent->setIcon('su-database');
        $collection->add($parent);
    }

    $item = new NavigationItem('app.contact_persons');
    $item->setPosition(10);
    $item->setView(self::LIST_VIEW);
    $collection->get('app.data_objects')->addChild($item);
}
```

---

### 5. REST-Controller

```php
// src/Controller/Admin/ContactPersonController.php
class ContactPersonController extends AbstractRestController
{
    // Jede Action beginnt mit SecurityChecker::checkPermission()
    // cgetAction: DoctrineListBuilder + PaginatedRepresentation
    // getAction / putAction / deleteAction: EntityNotFoundException werfen (nicht manuell als View)
    // postAction / putAction: ValidationFailedException → 422 zurückgeben
    // serialize(): private Methode — kein Normalizer notwendig für einfache Entities
}
```

**Wichtig in `services.yaml`:**

```yaml
App\Controller\Admin\ContactPersonController:
    tags:
        - controller.service_arguments  # PFLICHT — sonst "controller is private"
```

---

### 6. Sulu 3 Content-Layer (für Seitenreferenzierung)

In Sulu 3 gibt es **keine** `ContentTypeInterface` mehr. Stattdessen:

**PropertyResolver** — sagt dem Content-System, wie ein gespeicherter Wert (die ID) aufzulösen ist:

```php
// src/Content/PropertyResolver/ContactPersonPropertyResolver.php
class ContactPersonPropertyResolver implements PropertyResolverInterface
{
    public function resolve(mixed $data, string $locale, array $params = []): ContentView
    {
        if (!\is_int($data) || $data < 1) {
            return ContentView::create(null, array_merge(['id' => null], $params));
        }
        return ContentView::createResolvableWithReferences(
            $data, ContactPersonResourceLoader::RESOURCE_LOADER_KEY,
            ContactPersonAdmin::RESOURCE_KEY, array_merge(['id' => $data], $params)
        );
    }

    public static function getType(): string { return 'single_contact_person'; }
}
```

**ResourceLoader** — lädt die Entitäten anhand einer Liste von IDs:

```php
// src/Content/ResourceLoader/ContactPersonResourceLoader.php
class ContactPersonResourceLoader implements ResourceLoaderInterface
{
    public const RESOURCE_LOADER_KEY = 'contact_person';

    public function load(array $ids, ?string $locale, array $params = []): array
    {
        // $locale ignorieren, wenn Entity nicht lokalisiert ist
        // IDs validieren: !is_int($id) && !ctype_digit($id) → skip
    }

    public static function getKey(): string { return self::RESOURCE_LOADER_KEY; }
}
```

Beide Interfaces werden dank `autoconfigure: true` **automatisch** getaggt — kein manueller Tag nötig.

---

### 7. XML-Konfigurationen

**`config/lists/my_entities.xml`** — Spalten der Listenansicht:

```xml
<list xmlns="http://schemas.sulu.io/list-builder/list">
    <key>contact_persons</key>
    <properties>
        <concatenation-property name="fullName" visibility="always" searchability="yes" ...>
            <field><field-name>firstName</field-name><entity-name>App\Entity\ContactPerson</entity-name></field>
            <field><field-name>lastName</field-name><entity-name>App\Entity\ContactPerson</entity-name></field>
        </concatenation-property>
        <!-- weitere Spalten -->
    </properties>
</list>
```

**`config/forms/my_entity.xml`** — Felder des Bearbeitungsformulars:

```xml
<form xmlns="http://schemas.sulu.io/template/template" ...>
    <key>contact_person</key>
    <properties>
        <property name="firstName" type="text_line" mandatory="true">...</property>
        <property name="mediaId" type="single_media_selection">...</property>
    </properties>
</form>
```

---

### 8. Routing

**`config/routes/admin_api.yaml`** (neue Datei anlegen):

```yaml
app.contact_person.get_contact_persons:
    path: /contact-persons.{_format}
    controller: App\Controller\Admin\ContactPersonController::cgetAction
    methods: GET
    format: json

# + get / post / put / delete
```

**`config/routes/sulu_admin.yaml`** — Datei einbinden:

```yaml
app_contact_person_api:
    resource: "../../config/routes/admin_api.yaml"
    prefix: /admin/api
```

---

### 9. sulu_admin.yaml — Resource + Field-Type registrieren

```yaml
sulu_admin:
    resources:
        contact_persons:
            routes:
                list:   app.contact_person.get_contact_persons
                detail: app.contact_person.get_contact_person

    field_type_options:
        single_selection:
            single_contact_person:              # Typ-Name für Seitenvorlage-XML
                default_type: list_overlay
                resource_key: contact_persons
                types:
                    list_overlay:
                        adapter: table
                        list_key: contact_persons
                        display_properties: [fullName]
                        empty_text: app.no_contact_person_selected
                        icon: su-user
                        overlay_title: app.contact_person_selection_overlay_title
```

**Warum beides?** `resources` registriert die API-Routen für den Admin-Frontend-Store. `field_type_options` registriert den Auswahltyp (`single_contact_person`) für Formulare und Seitenvorlagen.

---

### 10. services.yaml

```yaml
# Interface-Bindings für DIP
App\Repository\ContactPersonRepositoryInterface:
    alias: App\Repository\ContactPersonRepository

App\ContactPerson\ContactPersonManagerInterface:
    alias: App\ContactPerson\ContactPersonManager

# Controller MUSS getaggt werden, da er über YAML-Routing referenziert wird
App\Controller\Admin\ContactPersonController:
    tags:
        - controller.service_arguments
```

---

### 11. Seitenvorlage (Seite / Block)

In der Template-XML den Typ aus `field_type_options` verwenden:

```xml
<!-- Als direkte Property -->
<property name="contactPerson" type="single_contact_person">
    <meta><title lang="de">Ansprechpartner</title></meta>
</property>

<!-- Als Block-Typ in einem bestehenden Block -->
<type name="contact_person">
    <properties>
        <property name="contact_person" type="single_contact_person" mandatory="true">...</property>
    </properties>
</type>
```

Im Twig-Template ist `block.contact_person` dann das aufgelöste Entity-Objekt:

```twig
{%- if block.contact_person -%}
    {{ block.contact_person.fullName }}
    {{ block.contact_person.email }}
{%- endif -%}
```

---

### 12. Migration

```bash
docker compose exec php bin/console doctrine:migrations:diff --namespace="DoctrineMigrations"
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Häufige Fehler

| Fehler | Ursache | Fix |
|---|---|---|
| `controller is private` | `controller.service_arguments` Tag fehlt | In `services.yaml` ergänzen |
| `There is no field with key "single_xyz"` | Field-Type nicht in `sulu_admin.yaml` registriert | `field_type_options.single_selection` ergänzen |
| `NavigationItemNotFoundException` | Eltern-Menüpunkt existiert noch nicht | `has()` prüfen vor `get()` |
| `ValidationFailedException` ohne 422 | Fehlerbehandlung im Controller fehlt | `try/catch` mit `formatViolations()` |
| `(int) 'abc' = 0` | Unsichere mediaId-Coercion | `is_numeric($raw) ? (int) $raw : null` |
