# Lesezeichen-API (PHP 8)

Eine schlanke, frameworlose REST-API in PHP 8, mit der Lesezeichen verwaltet
werden können. Ein Lesezeichen besteht aus einer URL, einem Titel und optionalen
Schlagwörtern (Tags). Die API spricht ausschließlich JSON, hat einen eigenen
Mini-Router und legt ihre Daten in einer SQLite-Datei über PDO ab. Als einzige
externe Abhängigkeit wird PHPUnit für die Tests benötigt.

## Tech-Stack

- **Sprache**: PHP 8.2+
- **Framework**: keins — eigener Mini-Router (`App\Router`)
- **Webserver**: eingebauter PHP-Webserver (`php -S`) mit `public/index.php` als Router-Skript
- **Abhängigkeiten**: Composer (`require-dev` ausschließlich PHPUnit)
- **Datenbank**: SQLite über PDO (Datei unter `DB_PATH`)
- **Tests**: PHPUnit
- **Stil**: PSR-12, `declare(strict_types=1)`

## Installation

```bash
composer install
```

## Starten (Entwicklung)

Der Server wird mit einem einzigen Befehl gestartet:

```bash
PORT=8000 php -S 0.0.0.0:8000 public/index.php
```

Standardmäßig lauscht er auf Port `8000`. Der Port kann über die Umgebungsvariable
`PORT` gesetzt werden. `RUN.json` beschreibt denselben Startbefehl:

```bash
php -S 0.0.0.0:${PORT} public/index.php
```

Ein Produktions-Build ist nicht nötig — es handelt sich um reines PHP, das direkt
vom Webserver ausgeliefert wird. Für einen Produktivbetrieb kann jeder Webserver
(z. B. nginx + PHP-FPM) mit `public/index.php` als Front-Controller verwendet werden.

## Umgebungsvariablen

| Variable  | Standard                | Beschreibung                                              |
| --------- | ----------------------- | --------------------------------------------------------- |
| `PORT`    | `8000`                  | Port, auf dem der eingebaute Webserver lauscht.           |
| `DB_PATH` | `data/bookmarks.sqlite` | Pfad zur SQLite-Datei, in der die Lesezeichen liegen.     |

## Endpunkte

Alle Antworten sind `application/json`. Ein Lesezeichen hat die Form:

```json
{
  "id": 1,
  "url": "https://example.com",
  "title": "Beispiel",
  "tags": ["php", "api"],
  "created_at": "2026-01-01T12:00:00Z",
  "updated_at": "2026-01-01T12:00:00Z"
}
```

Fehlerantworten haben die Form:

```json
{
  "error": {
    "code": "not_found",
    "message": "Not Found",
    "details": {}
  }
}
```

| Methode  | Pfad                    | Erfolg        | Beschreibung                                                |
| -------- | ----------------------- | ------------- | ----------------------------------------------------------- |
| `GET`    | `/api/health`           | `200`         | Health-Check: `{"status":"ok"}`.                            |
| `POST`   | `/api/bookmarks`        | `201`         | Legt ein Lesezeichen an. Body: `{url, title, tags?}`.       |
| `GET`    | `/api/bookmarks`        | `200`         | Listet Lesezeichen (neueste zuerst). Optional `?tag=value`. |
| `GET`    | `/api/bookmarks/{id}`   | `200`         | Ein einzelnes Lesezeichen.                                  |
| `PUT`    | `/api/bookmarks/{id}`   | `200`         | Ersetzt Felder; Body: Teilmenge von `{url, title, tags}`.   |
| `PATCH`  | `/api/bookmarks/{id}`   | `200`         | Ändert Felder; Body: Teilmenge von `{url, title, tags}`.    |
| `DELETE` | `/api/bookmarks/{id}`   | `204`         | Löscht ein Lesezeichen (leerer Body).                       |

Unbekannte Pfade liefern `404`, ein bekannter Pfad mit nicht unterstützter Methode
liefert `405` mit einem `Allow`-Header. Fehler-Codes: `bad_request` (400),
`validation_failed` (422), `not_found` (404), `method_not_allowed` (405),
`internal_error` (500).

## Tests

```bash
php vendor/bin/phpunit
```

oder über das Composer-Skript:

```bash
composer test
```

## Funktionen

- Frameworkloser Router mit `{id}`-Platzhaltern, 404 und 405 inklusive `Allow`-Header.
- Ausschließlich JSON-Antworten mit einheitlichem Fehlerformat; keine HTML-Fehlerseiten.
- Lesezeichen anlegen, auflisten, filtern, einzeln abrufen, ändern und löschen.
- Tag-Normalisierung (getrimmt, kleingeschrieben, duplikatfrei) und case-insensitiver Tag-Filter.
- Persistente Speicherung in einer SQLite-Datei über PDO; das Schema wird automatisch angelegt.
- Health-Endpunkt `GET /api/health`.
