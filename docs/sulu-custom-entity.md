# Custom Entity (Datenobjekt) in Sulu 3 erstellen

Vollständige Anleitung am Beispiel `ContactPerson`. Alle Pfade relativ zum Projektstamm.

---

## Übersicht: Was wird benötigt

| # | Datei | Zweck | Fehlt sie, passiert... |
|---|-------|-------|------------------------|
| 1 | `src/Entity/ContactPerson.php` | Datenbankmodell | Tabelle existiert nicht, kein Mapping |
| 2 | `src/Repository/ContactPersonRepositoryInterface.php` | Abstraktion der Persistenz | Kein DIP — Manager hängt direkt an Doctrine |
| 3 | `src/Repository/ContactPersonRepository.php` | Doctrine-Implementierung | Keine Datenbankzugriffe möglich |
| 4 | `src/ContactPerson/ContactPersonManagerInterface.php` | Abstraktion der Business-Logik | Controller hängt direkt an Implementierung |
| 5 | `src/ContactPerson/ContactPersonManager.php` | Validierung + Persistenz orchestrieren | Kein Create/Update/Delete |
| 6 | `src/Admin/ContactPersonAdmin.php` | Navigation + Views + Berechtigungen | Entity erscheint nicht im Sulu-Admin |
| 7 | `src/Controller/Admin/ContactPersonController.php` | REST-API für das Admin-Frontend | Admin-Frontend kann keine Daten laden |
| 8 | `src/Content/PropertyResolver/ContactPersonPropertyResolver.php` | Gespeicherte ID → Objekt auf Seite | Feld kann gespeichert, aber nicht gerendert werden |
| 9 | `src/Content/ResourceLoader/ContactPersonResourceLoader.php` | Batch-Loading der Entities per ID | ContentView bleibt leer beim Rendern |
| 10 | `config/lists/contact_persons.xml` | Spalten der Admin-Listenansicht | ListBuilder wirft Exception, Listenansicht lädt nicht |
| 11 | `config/forms/contact_person.xml` | Felder des Admin-Formulars | Formularansicht lädt nicht |
| 12 | `config/routes/admin_api.yaml` | API-Routen unter `/admin/api/` | HTTP 404 auf alle API-Aufrufe |
| 13 | `config/packages/sulu_admin.yaml` | Resource-Routen + Field-Type registrieren | Frontend-Store weiß nicht, wo die API ist |
| 14 | `config/services.yaml` | Interface-Bindings + Controller-Tag | `controller is private` Fehler |
| 15 | `translations/admin.en.yaml` | Übersetzungsschlüssel | Labels erscheinen als Rohschlüssel (z.B. `app.name`) |
| 16 | Doctrine Migration | Tabelle anlegen | SQL-Fehler bei jedem Zugriff |

---

## Schritt für Schritt

### 1. Entity — das Datenbankmodell

**Datei:** `src/Entity/ContactPerson.php`

**Warum:** Doctrine liest die PHP-Attribute (`#[ORM\Entity]`, `#[ORM\Column]`) und erzeugt daraus das SQL-Schema. Die Entity ist das einzige Objekt, das Doctrine kennt und persistiert — alles andere (Manager, Controller) arbeitet mit Instanzen dieser Klasse.

**Warum Validator-Constraints direkt hier:** Die Constraints (`#[Assert\NotBlank]`, `#[Assert\Length]`) leben auf der Entity, nicht im Controller oder Manager, weil die Entity die einzige Quelle der Wahrheit für ihre eigenen Regeln ist. Egal woher Daten kommen (REST-API, CLI, Import), die Constraints greifen immer.

**Wichtig:** Die Länge in `Assert\Length(max:)` muss mit dem `length:` in `#[ORM\Column]` übereinstimmen, sonst speichert Doctrine Daten, die der Validator ablehnt — oder umgekehrt.

```php
// src/Entity/ContactPerson.php
#[ORM\Entity]
#[ORM\Table(name: 'contact_person')]
class ContactPerson
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Spaltenbreite (100) muss mit Assert\Length(max: 100) übereinstimmen
    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $firstName = '';

    // nullable: true erlaubt NULL in der Datenbank — kein Assert\NotBlank nötig
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive]
    private ?int $mediaId = null;
}
```

---

### 2. Repository Interface — Abstraktion der Persistenz

**Datei:** `src/Repository/ContactPersonRepositoryInterface.php`

**Warum:** Das Interface trennt die Business-Logik (Manager) von der Persistenzschicht (Doctrine). Der Manager kennt nur das Interface — nicht die konkrete Klasse. Damit gilt das Dependency Inversion Principle: Wenn Doctrine morgen durch eine externe API ersetzt wird, ändert sich der Manager nicht.

**Was das Interface definiert:** Nur die Methoden, die wirklich benötigt werden. Keine Doctrine-spezifische Syntax (`findBy`, `createQueryBuilder`) nach außen.

```php
// src/Repository/ContactPersonRepositoryInterface.php
interface ContactPersonRepositoryInterface
{
    public function findById(int $id): ?ContactPerson;

    /** @return ContactPerson[] */
    public function findAll(): array;

    // Factory-Methode: Manager ruft create() auf statt new ContactPerson()
    // → Repository bleibt die einzige Stelle, die weiß, wie eine neue Instanz entsteht
    public function create(): ContactPerson;

    public function save(ContactPerson $contactPerson): void;

    public function remove(ContactPerson $contactPerson): void;
}
```

---

### 3. Repository — Doctrine-Implementierung

**Datei:** `src/Repository/ContactPersonRepository.php`

**Warum:** Hier passiert der tatsächliche Datenbankzugriff. `ServiceEntityRepository` bringt fertige Methoden (`find`, `findAll`) mit; die eigenen Methoden des Interfaces werden darüber implementiert.

**Warum `create()` im Repository:** Der Manager soll kein `new ContactPerson()` aufrufen. Würde sich der Konstruktor der Entity ändern, müsste man ihn an jeder Stelle suchen, die `new` aufruft. Mit `create()` im Repository gibt es nur eine einzige Stelle.

**Warum `flush()` in `save()` und `remove()`:** Diese Methoden sind für einzelne CRUD-Operationen gedacht. Das Flushen der gesamten Unit of Work ist hier bewusst — in diesem Kontext gibt es keine Batch-Operationen, bei denen man das Flushen aufschieben möchte.

```php
// src/Repository/ContactPersonRepository.php
class ContactPersonRepository extends ServiceEntityRepository implements ContactPersonRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactPerson::class);
    }

    public function create(): ContactPerson
    {
        return new ContactPerson();
    }

    // Flushes the entire Unit of Work — intentional for single-entity CRUD operations.
    public function save(ContactPerson $contactPerson): void
    {
        $this->getEntityManager()->persist($contactPerson);
        $this->getEntityManager()->flush();
    }

    // Flushes the entire Unit of Work — intentional for single-entity CRUD operations.
    public function remove(ContactPerson $contactPerson): void
    {
        $this->getEntityManager()->remove($contactPerson);
        $this->getEntityManager()->flush();
    }
}
```

---

### 4. Manager Interface + Implementierung — Business-Logik

**Dateien:**
- `src/ContactPerson/ContactPersonManagerInterface.php`
- `src/ContactPerson/ContactPersonManager.php`

**Warum ein Manager:** Der Controller soll keine Business-Logik enthalten (kein Validieren, kein Hydratisieren). Der Manager übernimmt alles zwischen "Daten empfangen" und "Entity speichern". Das trennt HTTP-Concerns (Controller) von Domain-Concerns (Manager).

**Warum `hydrateData()` und `assertValid()` getrennt:** Diese zwei Methoden haben unterschiedliche Verantwortlichkeiten (SRP). `hydrateData()` weist Werte zu, hat keine Seiteneffekte. `assertValid()` prüft und wirft bei Fehler — das ist klar trennbar und testbar.

**Warum `mediaId` besonders behandelt:** Das Sulu-Admin-Frontend sendet Media-Auswahl-Felder nicht als einfache Integer, sondern als Objekt: `{"id": 5}`. Der rohe Wert aus `$request->request->all()` kann also `["id" => "5"]` (Array) oder `"5"` (String) oder `5` (Integer) sein — daher die explizite Fallunterscheidung.

```php
// src/ContactPerson/ContactPersonManager.php
class ContactPersonManager implements ContactPersonManagerInterface
{
    public function create(array $data): ContactPerson
    {
        $entity = $this->repository->create();
        $this->hydrateData($entity, $data); // Nur Setter aufrufen, keine Seiteneffekte
        $this->assertValid($entity);         // Nur validieren, wirft bei Fehler
        $this->repository->save($entity);

        return $entity;
    }

    private function hydrateData(ContactPerson $contactPerson, array $data): void
    {
        $contactPerson->setFirstName($data['firstName'] ?? '');
        $contactPerson->setLastName($data['lastName'] ?? '');
        $contactPerson->setPosition($data['position'] ?? null);
        $contactPerson->setEmail($data['email'] ?? null);
        $contactPerson->setPhone($data['phone'] ?? null);

        // Admin sendet Media als {"id": 5} — kann Array, String oder Integer sein
        $rawMedia = $data['mediaId'] ?? null;
        if (\is_array($rawMedia) && isset($rawMedia['id']) && \is_numeric($rawMedia['id'])) {
            $mediaId = (int) $rawMedia['id'];
        } elseif (\is_numeric($rawMedia)) {
            $mediaId = (int) $rawMedia;
        } else {
            $mediaId = null;
        }
        $contactPerson->setMediaId($mediaId);
    }

    private function assertValid(ContactPerson $contactPerson): void
    {
        $violations = $this->validator->validate($contactPerson);
        if (\count($violations) > 0) {
            // ValidationFailedException wird im Controller abgefangen und als 422 zurückgegeben
            throw new ValidationFailedException($contactPerson, $violations);
        }
    }
}
```

---

### 5. Admin-Klasse — Navigation, Views und Berechtigungen

**Datei:** `src/Admin/ContactPersonAdmin.php`

**Warum:** Diese Klasse ist der Einstiegspunkt für das Sulu-Admin-Frontend. Sulu sammelt beim Booten alle Klassen, die `Admin` erweitern, und ruft deren Methoden auf. Ohne diese Klasse weiß das Admin-Frontend nicht, dass die Entity existiert — kein Menüpunkt, keine Views, keine Berechtigungen.

**Drei Aufgaben dieser Klasse:**

1. **`configureNavigationItems()`** — Registriert den Menüpunkt in der linken Sidebar. Das `has()`-Check vor dem Anlegen des Eltern-Elements ist nötig, weil mehrere Admin-Klassen denselben Eltern-Menüpunkt teilen können — nur der erste soll ihn anlegen.

2. **`configureViews()`** — Registriert die Views (Listenansicht, Hinzufügen-Formular, Bearbeiten-Formular) beim Sulu-Frontend-Router. Jeder View bekommt einen eindeutigen Namen (z.B. `app.contact_person.list`) und eine URL. Die Toolbar-Aktionen (Hinzufügen, Speichern, Löschen) sind permission-gated.

3. **`getSecurityContexts()`** — Registriert den Security Context `app.contact_persons` im Rollen-Editor. Damit können Administratoren gezielt VIEW/ADD/EDIT/DELETE-Rechte vergeben.

**Warum Konstanten statt Magic Strings:** `RESOURCE_KEY`, `LIST_KEY`, `FORM_KEY` etc. werden an vielen Stellen verwendet (Admin, Controller, sulu_admin.yaml). Konstanten verhindern Tippfehler und machen Refactoring sicherer.

```php
// src/Admin/ContactPersonAdmin.php
class ContactPersonAdmin extends Admin
{
    // Muss mit dem Schlüssel in config/lists/contact_persons.xml übereinstimmen
    public const LIST_KEY = 'contact_persons';

    // Muss mit dem Schlüssel in config/forms/contact_person.xml übereinstimmen
    public const FORM_KEY = 'contact_person';

    // Muss mit resources.contact_persons in sulu_admin.yaml übereinstimmen
    public const RESOURCE_KEY = 'contact_persons';

    public function configureNavigationItems(NavigationItemCollection $collection): void
    {
        // Berechtigung prüfen: Ohne VIEW-Recht kein Menüpunkt
        if (!$this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        // Eltern-Item nur anlegen, wenn es noch nicht existiert
        // (andere Admin-Klassen könnten es bereits angelegt haben)
        if (!$collection->has(self::DATA_OBJECTS_NAVIGATION_ITEM)) {
            $dataObjects = new NavigationItem(self::DATA_OBJECTS_NAVIGATION_ITEM);
            $dataObjects->setPosition(45);
            $dataObjects->setIcon('su-storage');
            $collection->add($dataObjects);
        }

        $item = new NavigationItem('app.contact_persons');
        $item->setPosition(10);
        $item->setView(self::LIST_VIEW);
        $collection->get(self::DATA_OBJECTS_NAVIGATION_ITEM)->addChild($item);
    }

    public function getSecurityContexts(): array
    {
        return [
            // SULU_ADMIN_SECURITY_SYSTEM ist die Konstante für den Admin-Bereich
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'Settings' => [
                    self::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::ADD,
                        PermissionTypes::EDIT,
                        PermissionTypes::DELETE,
                    ],
                ],
            ],
        ];
    }
}
```

---

### 6. REST-Controller — die API für das Admin-Frontend

**Datei:** `src/Controller/Admin/ContactPersonController.php`

**Warum:** Das Sulu-Admin-Frontend ist eine React-SPA, die alle Daten über eine JSON-API lädt. Jede View (Liste, Formular) macht HTTP-Requests an diese API. Der Controller ist der einzige Eintrittspunkt für diese Requests.

**Warum jede Action mit `checkPermission()` beginnt:** Der Controller ist über eine URL erreichbar — auch für Nutzer ohne Berechtigung, wenn man die URL kennt. Die Admin-Klasse versteckt nur den Menüpunkt im Frontend; sie schützt nicht die API. `checkPermission()` wirft eine Exception, wenn die Berechtigung fehlt.

**Warum `cgetAction` `DoctrineListBuilder` nutzt:** Die Listenansicht unterstützt Suche, Sortierung und Pagination. Der `DoctrineListBuilder` liest die `config/lists/contact_persons.xml` (via `FieldDescriptorFactory`) und baut daraus automatisch die passenden SQL-Queries. Manuelles Schreiben dieser Queries wäre fehleranfällig und würde die XML-Konfiguration ignorieren.

**Warum `serialize()` manuell implementiert ist:** Sulu nutzt keinen automatischen Normalizer für Custom Entities. Die Methode gibt ein einfaches Array zurück, das FOS RestBundle als JSON serialisiert. `mediaId` wird als `{"id": 5}` zurückgegeben, weil das Admin-Frontend bei `single_media_selection`-Feldern ein Objekt erwartet — konsistent mit dem, was es beim Speichern sendet.

**Warum `EntityNotFoundException` statt manueller 404-Response:** Die Elternklasse `AbstractRestController` fängt diese Exception ab und wandelt sie automatisch in eine passende HTTP-Response um.

```php
// src/Controller/Admin/ContactPersonController.php

public function cgetAction(Request $request): Response
{
    // Wirft AccessDeniedException wenn keine VIEW-Berechtigung
    $this->securityChecker->checkPermission(
        new SecurityCondition(ContactPersonAdmin::SECURITY_CONTEXT),
        PermissionTypes::VIEW
    );

    // FieldDescriptors aus config/lists/contact_persons.xml lesen
    $fieldDescriptors = $this->fieldDescriptorFactory->getFieldDescriptors(ContactPersonAdmin::LIST_KEY);
    $listBuilder = $this->listBuilderFactory->create(ContactPerson::class);

    // Search, Sort, Pagination aus dem Request in den Builder übertragen
    $this->restHelper->initializeListBuilder($listBuilder, $fieldDescriptors);

    $list = new PaginatedRepresentation(
        $listBuilder->execute(),
        ContactPersonAdmin::RESOURCE_KEY,
        (int) $listBuilder->getCurrentPage(),
        (int) $listBuilder->getLimit(),
        (int) $listBuilder->count()
    );

    return $this->handleView($this->view($list, 200));
}

public function postAction(Request $request): Response
{
    $this->securityChecker->checkPermission(
        new SecurityCondition(ContactPersonAdmin::SECURITY_CONTEXT),
        PermissionTypes::ADD
    );

    try {
        $contactPerson = $this->manager->create($request->request->all());
    } catch (ValidationFailedException $e) {
        // 422 Unprocessable Entity mit strukturierten Fehlermeldungen
        return $this->handleView($this->view($this->formatViolations($e), 422));
    }

    return $this->handleView($this->view($this->serialize($contactPerson), 201));
}

private function serialize(ContactPerson $contactPerson): array
{
    return [
        'id'        => $contactPerson->getId(),
        'firstName' => $contactPerson->getFirstName(),
        'lastName'  => $contactPerson->getLastName(),
        'fullName'  => $contactPerson->getFullName(),
        'position'  => $contactPerson->getPosition(),
        'email'     => $contactPerson->getEmail(),
        'phone'     => $contactPerson->getPhone(),
        // single_media_selection erwartet ein Objekt {"id": N}, keinen Integer
        'mediaId'   => $contactPerson->getMediaId() !== null ? ['id' => $contactPerson->getMediaId()] : null,
    ];
}
```

---

### 7. Sulu 3 Content-Layer — Seitenreferenzierung

In Sulu 3 gibt es **keine** `ContentTypeInterface` mehr. Stattdessen zwei separate Klassen:

#### PropertyResolver

**Datei:** `src/Content/PropertyResolver/ContactPersonPropertyResolver.php`

**Warum:** Wenn eine Seite einen `single_contact_person`-Wert speichert, wird nur die ID gespeichert (z.B. `42`). Beim Rendern der Seite muss Sulu wissen, was mit dieser ID zu tun ist — soll sie direkt ausgegeben werden, oder muss die Entity geladen werden? Der PropertyResolver beantwortet das.

**Was `ContentView::createResolvableWithReferences()` bedeutet:** Es sagt Sulu: "Dieser Wert ist noch nicht aufgelöst, lade ihn mit dem ResourceLoader `contact_person`." Sulu ruft dann den ResourceLoader auf und übergibt die Entity an das Twig-Template.

**Warum der Typ-Check am Anfang:** Wenn das Feld leer ist (noch keine Auswahl getroffen), ist `$data` `null` oder kein positiver Integer. In diesem Fall gibt der Resolver sofort `null` zurück, ohne den ResourceLoader aufzurufen.

**Automatisches Tagging:** Dank `autoconfigure: true` in `services.yaml` wird diese Klasse automatisch als PropertyResolver registriert, weil sie `PropertyResolverInterface` implementiert. Kein manueller Tag nötig.

```php
// src/Content/PropertyResolver/ContactPersonPropertyResolver.php
class ContactPersonPropertyResolver implements PropertyResolverInterface
{
    public function resolve(mixed $data, string $locale, array $params = []): ContentView
    {
        // Kein gültiger Wert gespeichert (Feld leer oder ungültige ID)
        if (!\is_int($data) || $data < 1) {
            return ContentView::create(null, \array_merge(['id' => null], $params));
        }

        // Sulu anweisen: Lade diese ID mit dem ContactPerson-ResourceLoader
        return ContentView::createResolvableWithReferences(
            $data,
            ContactPersonResourceLoader::RESOURCE_LOADER_KEY, // Welcher Loader wird aufgerufen
            ContactPersonAdmin::RESOURCE_KEY,                  // Für API-Referenzen im Frontend
            \array_merge(['id' => $data], $params),
        );
    }

    // Muss mit dem type-Attribut in der Seitenvorlage-XML übereinstimmen
    public static function getType(): string
    {
        return 'single_contact_person';
    }
}
```

#### ResourceLoader

**Datei:** `src/Content/ResourceLoader/ContactPersonResourceLoader.php`

**Warum:** Wenn mehrere Seiten denselben Ansprechpartner referenzieren, würde ein naiver Ansatz die Entity mehrfach aus der Datenbank laden. Der ResourceLoader bekommt eine Liste von IDs und lädt alle auf einmal — Sulu verwendet ihn für effizientes Batch-Loading.

**Warum `$locale` ignoriert wird:** `ContactPerson` hat keine lokalisierten Felder — dieselbe Entity gilt für alle Sprachen. Würde man `$locale` beachten, würde man unnötig viele Queries erzeugen.

**Warum der ID-Typ-Check:** Die IDs kommen aus dem Content-System und können Strings oder Integer sein, je nachdem wie sie serialisiert wurden. `ctype_digit()` prüft ob ein String nur Ziffern enthält — sicherer als `is_numeric()`, das auch `"1e5"` akzeptiert.

```php
// src/Content/ResourceLoader/ContactPersonResourceLoader.php
class ContactPersonResourceLoader implements ResourceLoaderInterface
{
    public const RESOURCE_LOADER_KEY = 'contact_person'; // Muss mit PropertyResolver übereinstimmen

    // $locale wird ignoriert — ContactPerson ist nicht lokalisiert
    public function load(array $ids, ?string $locale, array $params = []): array
    {
        $result = [];

        foreach ($ids as $id) {
            // IDs können aus der Serialisierung als String ankommen
            if (!\is_int($id) && !\ctype_digit($id)) {
                continue;
            }

            $contactPerson = $this->repository->findById((int) $id);
            if ($contactPerson !== null) {
                $result[$id] = $contactPerson; // Rückgabe: ID → Entity
            }
        }

        return $result;
    }

    public static function getKey(): string
    {
        return self::RESOURCE_LOADER_KEY;
    }
}
```

---

### 8. XML-Konfigurationen

#### config/lists/contact_persons.xml — Listenansicht

**Warum XML (nicht YAML):** Sulu liest diese Dateien über seinen eigenen `ListXmlLoader`, der ausschließlich XML versteht. Es gibt keine YAML-Alternative — das ist eine Designentscheidung von Sulu.

**Warum diese Datei überhaupt nötig ist:** Der `DoctrineListBuilder` im Controller ruft `FieldDescriptorFactory::getFieldDescriptors('contact_persons')` auf. Diese Methode sucht in den konfigurierten `lists`-Verzeichnissen (siehe `sulu_admin.yaml`) nach einer XML-Datei mit `<key>contact_persons</key>`. Fehlt die Datei, wirft der Builder eine Exception.

**Was die Attribute steuern:**
- `visibility="always"` — Spalte immer anzeigen, kann nicht vom Nutzer ausgeblendet werden
- `visibility="yes"` — Spalte standardmäßig sichtbar, kann ausgeblendet werden
- `visibility="no"` — Spalte versteckt (z.B. `id` für interne Verwendung)
- `searchability="yes"` — Feld in die Volltextsuche einbeziehen
- `searchability="never"` — Feld niemals durchsuchbar (z.B. `id`)
- `concatenation-property` — Mehrere Datenbankfelder werden im Frontend zusammengefügt und als eine Spalte dargestellt

```xml
<!-- config/lists/contact_persons.xml -->
<!-- Schlüssel muss mit ContactPersonAdmin::LIST_KEY übereinstimmen -->
<list xmlns="http://schemas.sulu.io/list-builder/list">
    <key>contact_persons</key>

    <properties>
        <!-- id versteckt, aber vorhanden: DoctrineListBuilder braucht es intern -->
        <property
            name="id"
            visibility="no"
            searchability="never"
            translation="sulu_admin.id"
        >
            <field-name>id</field-name>
            <entity-name>App\Entity\ContactPerson</entity-name>
        </property>

        <!-- Vor- und Nachname werden im Frontend als eine Spalte "Name" gezeigt -->
        <!-- sortable="false" weil SQL ORDER BY auf einem CONCAT komplex wäre -->
        <concatenation-property
            name="fullName"
            visibility="always"
            searchability="yes"
            sortable="false"
            translation="app.name"
        >
            <field>
                <field-name>firstName</field-name>
                <entity-name>App\Entity\ContactPerson</entity-name>
            </field>
            <field>
                <field-name>lastName</field-name>
                <entity-name>App\Entity\ContactPerson</entity-name>
            </field>
        </concatenation-property>

        <property
            name="email"
            visibility="yes"
            searchability="yes"  <!-- E-Mail in Volltextsuche einbeziehen -->
            translation="app.email"
        >
            <field-name>email</field-name>
            <entity-name>App\Entity\ContactPerson</entity-name>
        </property>

        <property
            name="phone"
            visibility="yes"
            searchability="no"  <!-- Telefon nicht durchsuchbar -->
            translation="app.phone"
        >
            <field-name>phone</field-name>
            <entity-name>App\Entity\ContactPerson</entity-name>
        </property>
    </properties>
</list>
```

#### config/forms/contact_person.xml — Bearbeitungsformular

**Warum XML (nicht YAML):** Sulu liest diese Dateien über seinen `FormXmlLoader`. Auch hier gibt es keine YAML-Alternative.

**Warum diese Datei nötig ist:** Wenn das Admin-Frontend das Bearbeitungsformular öffnet, fragt es Sulus Metadata-API: "Welche Felder hat das Formular `contact_person`?" Die Antwort kommt aus dieser Datei. Fehlt sie, crasht das Frontend beim Öffnen des Formulars.

**Was die Attribute steuern:**
- `type` — welcher React-Komponenten-Typ gerendert wird (z.B. `text_line` = einfaches Textfeld, `single_media_selection` = Medienauswahl)
- `mandatory="true"` — Frontend markiert das Feld als Pflichtfeld
- `colspan` — Breite auf einem 12-Spalten-Grid (Gesamtbreite einer `section` teilt sich auf Felder auf)
- `section` — visuelles Gruppierungselement ohne eigenes Datenbankfeld

```xml
<!-- config/forms/contact_person.xml -->
<!-- Schlüssel muss mit ContactPersonAdmin::FORM_KEY übereinstimmen -->
<form xmlns="http://schemas.sulu.io/template/template"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://schemas.sulu.io/template/template http://schemas.sulu.io/template/form-1.0.xsd"
>
    <key>contact_person</key>

    <properties>
        <!-- section gruppiert Felder visuell — hat selbst kein Datenbankfeld -->
        <!-- colspan="8" = diese Section nimmt 8 von 12 Spalten ein -->
        <section name="name_section" colspan="8">
            <meta>
                <title>app.name</title>
            </meta>
            <properties>
                <!-- type="text_line" = einzeiliges Textfeld -->
                <!-- mandatory="true" = Frontend-Validierung, Backend validiert zusätzlich via Assert -->
                <property name="firstName" type="text_line" mandatory="true" colspan="6">
                    <meta>
                        <title>app.first_name</title>
                    </meta>
                </property>

                <property name="lastName" type="text_line" mandatory="true" colspan="6">
                    <meta>
                        <title>app.last_name</title>
                    </meta>
                </property>
            </properties>
        </section>

        <!-- colspan="4" = rechte Spalte für das Bild (8+4 = 12) -->
        <section name="photo_section" colspan="4">
            <meta>
                <title>app.photo</title>
            </meta>
            <properties>
                <!-- type="single_media_selection" = Medienauswahl aus der Sulu Medienbibliothek -->
                <!-- param types="image" = nur Bilder erlaubt, keine Videos/Dokumente -->
                <property name="mediaId" type="single_media_selection" colspan="12">
                    <meta>
                        <title>app.photo</title>
                    </meta>
                    <params>
                        <param name="types" value="image"/>
                    </params>
                </property>
            </properties>
        </section>
    </properties>
</form>
```

---

### 9. Routing

**Datei:** `config/routes/admin_api.yaml`

**Warum eine separate Datei:** Die Datei `config/routes/sulu_admin.yaml` bindet diese Datei mit dem Prefix `/admin/api` ein. Alle eigenen API-Routen landen so automatisch unter `/admin/api/contact-persons`. Durch die Trennung bleibt `sulu_admin.yaml` übersichtlich und eigene Routen sind gebündelt.

**Warum die Route-Namen wichtig sind:** Die Namen (z.B. `app.contact_person.get_contact_persons`) werden in `sulu_admin.yaml` unter `resources.contact_persons.routes` referenziert. Stimmt der Name nicht, findet das Frontend-Store die API nicht.

```yaml
# config/routes/admin_api.yaml
# Diese Datei wird von config/routes/sulu_admin.yaml mit prefix /admin/api eingebunden.
# Vollständige URLs: GET /admin/api/contact-persons.json, GET /admin/api/contact-persons/{id}.json usw.

app.contact_person.get_contact_person:
    path: /contact-persons/{id}.{_format}
    controller: App\Controller\Admin\ContactPersonController::getAction
    methods: GET
    format: json

app.contact_person.get_contact_persons:
    path: /contact-persons.{_format}
    controller: App\Controller\Admin\ContactPersonController::cgetAction
    methods: GET
    format: json

app.contact_person.post_contact_person:
    path: /contact-persons.{_format}
    controller: App\Controller\Admin\ContactPersonController::postAction
    methods: POST
    format: json

app.contact_person.put_contact_person:
    path: /contact-persons/{id}.{_format}
    controller: App\Controller\Admin\ContactPersonController::putAction
    methods: PUT
    format: json

app.contact_person.delete_contact_person:
    path: /contact-persons/{id}.{_format}
    controller: App\Controller\Admin\ContactPersonController::deleteAction
    methods: DELETE
    format: json
```

---

### 10. sulu_admin.yaml — Resource und Field-Type registrieren

**Datei:** `config/packages/sulu_admin.yaml`

**Warum diese Konfiguration nötig ist:** Das Sulu-Admin-Frontend ist eine React-SPA. Sie kennt keine PHP-Klassen — sie kommuniziert über eine JSON-API. Diese Konfiguration teilt dem Frontend mit:
1. **Wo** es die API für `contact_persons` findet (→ `resources`)
2. **Wie** der Auswahltyp `single_contact_person` in Formularen dargestellt wird (→ `field_type_options`)

**`resources`:** Jede Resource braucht zwei benannte Routen:
- `list` → Route für `GET /contact-persons` (Liste, mit Pagination)
- `detail` → Route für `GET /contact-persons/{id}` (Einzeldatensatz)

**`field_type_options.single_selection`:** Registriert den Typ `single_contact_person`, den man in Seitenvorlagen-XMLs und Admin-Formularen als `type="single_contact_person"` verwenden kann. `list_overlay` bedeutet: beim Klick öffnet sich ein Modal mit einer Tabelle zur Auswahl.

```yaml
# config/packages/sulu_admin.yaml
sulu_admin:
    email: "%env(SULU_ADMIN_EMAIL)%"

    # Verzeichnisse, in denen Sulu nach Form- und List-XMLs sucht
    forms:
        directories:
            - "%kernel.project_dir%/config/forms"
    lists:
        directories:
            - "%kernel.project_dir%/config/lists"

    resources:
        contact_persons:
            routes:
                # Route-Namen aus config/routes/admin_api.yaml
                list:   app.contact_person.get_contact_persons
                detail: app.contact_person.get_contact_person

    field_type_options:
        single_selection:
            # Schlüssel = der Typ-Name, der in Seitenvorlage-XML als type="..." verwendet wird
            single_contact_person:
                default_type: list_overlay  # Auswahl über Modal mit Tabelle
                resource_key: contact_persons
                types:
                    list_overlay:
                        adapter: table
                        list_key: contact_persons           # Schlüssel der list-XML
                        display_properties: [fullName]     # Welches Feld im Auswahlfeld angezeigt wird
                        empty_text: app.no_contact_person_selected
                        icon: su-user
                        overlay_title: app.contact_person_selection_overlay_title
```

---

### 11. services.yaml — Interface-Bindings und Controller-Tag

**Datei:** `config/services.yaml`

**Warum Interface-Aliase:** Symfony injiziert Abhängigkeiten per Typ. Wenn der Manager `ContactPersonRepositoryInterface` als Konstruktor-Parameter hat, muss Symfony wissen, welche konkrete Klasse es injizieren soll. Ohne diesen Alias gibt es einen `AutowiringFailedException`.

**Warum der `controller.service_arguments`-Tag:** Symfony kennt zwei Arten von Controllern: solche, die vom `FrameworkBundle` über den Klassen-Namespace gefunden werden, und solche, die über YAML-Routing referenziert werden. Letztere müssen explizit als Service bekannt gemacht werden — und `controller.service_arguments` sagt dem Framework, dass Abhängigkeiten per Konstruktor injiziert werden sollen. Fehlt dieser Tag, wirft Symfony `The controller for URI is not callable: Controller is private.`

```yaml
# config/services.yaml

# Interface → Konkrete Klasse (Dependency Inversion Principle)
# Wenn ContactPersonManagerInterface irgendwo injiziert wird, bekommt es ContactPersonManager
App\Repository\ContactPersonRepositoryInterface:
    alias: App\Repository\ContactPersonRepository

App\ContactPerson\ContactPersonManagerInterface:
    alias: App\ContactPerson\ContactPersonManager

# Controller ist über YAML-Routing referenziert (nicht über Symfony's automatische Controller-Erkennung).
# controller.service_arguments erlaubt Konstruktor-Injection für diesen Controller.
App\Controller\Admin\ContactPersonController:
    tags:
        - controller.service_arguments
```

---

### 12. Seitenvorlage — Ansprechpartner auf einer Seite einbinden

Sobald `single_contact_person` in `sulu_admin.yaml` registriert ist, kann der Typ in Seitenvorlage-XMLs verwendet werden:

```xml
<!-- Als direktes Feld der Seite -->
<property name="contactPerson" type="single_contact_person">
    <meta><title lang="de">Ansprechpartner</title></meta>
</property>

<!-- Als Block-Typ in einem bestehenden Block -->
<type name="contact_person">
    <properties>
        <property name="contact_person" type="single_contact_person" mandatory="true">
            <meta><title lang="de">Ansprechpartner</title></meta>
        </property>
    </properties>
</type>
```

Im Twig-Template ist das aufgelöste Entity-Objekt direkt verfügbar (der PropertyResolver + ResourceLoader haben es geladen):

```twig
{%- if block.contact_person -%}
    <p>{{ block.contact_person.fullName }}</p>
    <p>{{ block.contact_person.email }}</p>
{%- endif -%}
```

---

### 13. Übersetzungen

**Datei:** `translations/admin.en.yaml` (und `admin.de.yaml`)

**Warum:** Alle Strings in Admin-XMLs und PHP-Klassen sind Übersetzungsschlüssel, keine direkten Texte. Sulu lädt sie über Symfonys Translator. Fehlt ein Schlüssel, erscheint der Rohschlüssel im Frontend (z.B. `app.first_name` statt `Vorname`).

```yaml
# translations/admin.de.yaml
app:
    contact_persons: Ansprechpartner
    first_name: Vorname
    last_name: Nachname
    position: Position
    email: E-Mail
    phone: Telefon
    photo: Foto
    name: Name
    contact: Kontakt
    no_contact_person_selected: Kein Ansprechpartner ausgewählt
    contact_person_selection_overlay_title: Ansprechpartner auswählen
    data_objects: Datenobjekte
```

---

### 14. Migration — Datenbanktabelle anlegen

```bash
# Diff erzeugen (vergleicht Entity mit aktueller Datenbankstruktur)
docker compose exec php bin/console doctrine:migrations:diff --namespace="DoctrineMigrations"

# Migration ausführen
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Häufige Fehler

| Fehler | Ursache | Fix |
|--------|---------|-----|
| `Controller is private` | `controller.service_arguments`-Tag fehlt | In `services.yaml` ergänzen |
| `There is no field with key "single_xyz"` | Field-Type nicht in `sulu_admin.yaml` registriert | `field_type_options.single_selection` ergänzen |
| `NavigationItemNotFoundException` | Eltern-Menüpunkt existiert noch nicht | `has()` prüfen vor `get()` in `configureNavigationItems()` |
| `ValidationFailedException` ohne 422 | `try/catch` im Controller fehlt | `catch (ValidationFailedException)` mit `formatViolations()` ergänzen |
| Labels zeigen Rohschlüssel (`app.name`) | Übersetzungsschlüssel fehlt | `translations/admin.de.yaml` ergänzen |
| Listenansicht lädt nicht | `config/lists/*.xml` fehlt oder `<key>` stimmt nicht | Datei anlegen, Schlüssel mit `LIST_KEY`-Konstante abgleichen |
| Formular lädt nicht | `config/forms/*.xml` fehlt oder `<key>` stimmt nicht | Datei anlegen, Schlüssel mit `FORM_KEY`-Konstante abgleichen |
| Entity auf Seite immer `null` | PropertyResolver oder ResourceLoader fehlt/nicht registriert | Klassen anlegen; `autoconfigure: true` sicherstellen |
| `(int) 'abc' = 0` | Unsichere mediaId-Coercion | `is_numeric($raw) ? (int) $raw : null` verwenden |
