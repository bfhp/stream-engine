# Developer Guide: Extensions

A module adds features to a site. An extension changes how an existing
platform operation behaves on one site, without forking the engine: for
example, accepting a password checked by the site's own legacy code when the
engine's hash does not match.

The engine calls **extension points** at documented moments. A site handles a
point by writing a class that extends `StreamEngine\Extension\Extension` and
defines a public method with the point's name. The available points are listed
in [Extension points](#extension-points) at the end of this page.

## Write an extension

Put the class anywhere in the site's PSR-4 namespace, for example
`extensions/LegacyAuth.php` with this mapping in the site's `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "MySite\\Extensions\\": "extensions/"
    }
  },
  "config": {
    "optimize-autoloader": true
  }
}
```

```php
<?php

declare(strict_types=1);

namespace MySite\Extensions;

use StreamEngine\Extension\Extension;

final class LegacyAuth extends Extension
{
    public function verifyPasswordFallback(
        int $userId,
        string $email,
        #[\SensitiveParameter] string $password,
        string $clientIp,
    ): ?bool {
        $row = $this->db->fetchOne(
            'SELECT password_md5 FROM legacy_users WHERE email = ?',
            [$email]
        );

        if ($row === null || ! hash_equals($row['password_md5'], md5($password))) {
            return null;
        }

        $this->userRepository->updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));

        return true;
    }
}
```

Then regenerate the classmap:

```bash
composer dump-autoload -o
```

That is all. There is no list to register the class in: every instantiable
`Extension` subclass in the Composer classmap is active, exactly as every
`ControllerInterface` implementation is a module.

## Services available in an extension

The base class receives the shared services that module controllers can
receive and exposes them as protected properties with the same names as in
`StreamEngine`:

| Property | Type |
| --- | --- |
| `$this->db` | `PdoDatabase` |
| `$this->config` | `Config` |
| `$this->cache` | `Cache` |
| `$this->settings` | `SettingsService` |
| `$this->tm` | `TranslationManager` |
| `$this->fmt` | `Formatter` |
| `$this->modules` | `ModuleRegistry` |
| `$this->pageTree` | `PageTree` |
| `$this->urlGenerator` | `UrlGenerator` |
| `$this->accessService` | `AccessService` |
| `$this->authService` | `AuthService` |
| `$this->userService` | `UserService` |
| `$this->userRepository` | `UserRepository` |
| `$this->feedService` | `FeedService` |
| `$this->termService` | `TermService` |
| `$this->pollService` | `PollService` |
| `$this->uploadService` | `UploadService` |
| `$this->messageService` | `MessageService` |
| `$this->notificationService` | `NotificationService` |
| `$this->mentionService` | `MentionService` |
| `$this->themeService` | `ThemeService` |
| `$this->widgets` | `WidgetService` |
| `$this->cronRegistry` | `CronRegistry` |

`RequestContext` is not available. Points can be called before the request's
user is known, and from cron or CLI, so whatever a point needs from the request
is passed to it as arguments.

These services are convenient, not a stable API: their methods follow normal
engine refactoring. The stable part for sites is the point method names and
arguments listed below.

### Constructor

An extension normally declares no constructor. Create helpers lazily instead:

```php
final class Crm extends Extension
{
    private ?CrmClient $client = null;

    private function client(): CrmClient
    {
        return $this->client ??= new CrmClient((string) getenv('CRM_URL'));
    }
}
```

A class that does declare a constructor must accept every service of the base
class and pass them to `parent::__construct()`; otherwise the properties stay
unset.

## How a point is called

```php
// inside the engine
$result = $this->extensions->verifyPasswordFallback($userId, $email, $password, $clientIp);
```

- Every extension that has a public method with the point's name is called, in
  order of fully qualified class name.
- The **first result that is not `null` wins**. Later extensions are not
  called.
- `null` means "do not change the outcome". When there are no extensions, or
  all return `null`, the engine keeps its default behavior.
- An exception thrown by the method is written to the PHP error log with the
  class and point name and treated as `null`. A failing extension therefore
  never turns into a positive answer.
- All extension classes are constructed on the first point call of the
  process and reused. An exception during construction (for example a
  constructor asking for a service that does not exist) is not caught: it is a
  configuration error.

A site may have several extension classes, and one class may handle several
points. Keep one class per integration so its order relative to others is easy
to see from the class names.

## Test an extension

The point method is ordinary PHP. When it does not use base-class services,
call it on an instance created without the constructor:

```php
$extension = (new ReflectionClass(LegacyAuth::class))->newInstanceWithoutConstructor();
self::assertNull($extension->verifyPasswordFallback(7, 'a@example.com', 'wrong', '127.0.0.1'));
```

When it does, move the logic into a small class that receives the services it
needs and test that class, or cover the point with an integration test that
boots the engine.

In the engine repository, a test fixture that extends `Extension` must be
listed under `autoload-dev.exclude-from-classmap` in `composer.json` and loaded
with `require_once`, like `tests/Support/ExtensionFixtures.php`. Otherwise it
is discovered and becomes active on a development site.

## Remove an extension

Delete the class (or its method, to stop handling one point) and run
`composer dump-autoload -o`. Extensions own no schema or routes of their own;
anything they stored through engine services stays where they stored it.

## Add an extension point

For engine developers:

1. Pick a method name in the form of a verb (`verifyPasswordFallback`) and plain
   arguments. Mark secrets with `#[\SensitiveParameter]`.
2. Call it through the `ExtensionRegistry` the service received from the
   composition root, and treat `null` as "keep the default behavior". Decide
   which non-null results the caller accepts and ignore the rest.
3. Do not call a point during composition: the registry is bound to the
   resolver at the end of `StreamEngine::__construct()`, and calls before that
   throw.
4. Add the point to [Extension points](#extension-points): when it is called,
   what is already checked, its arguments, and what each result means.
5. Test that with no extensions the operation behaves exactly as before, and
   test each accepted result.

A new argument of an existing point goes last and has a default value.
Renaming a point or changing the meaning of its arguments is a breaking change
and goes to the CHANGELOG.

The design behind these rules is in
[`docs/EXTENSIONS_CONTRACT.md`](https://github.com/bfhp/stream-engine/blob/main/docs/EXTENSIONS_CONTRACT.md).

## Extension points

| Point | Called from |
| --- | --- |
| [`verifyPasswordFallback`](#verifypasswordfallback) | `AuthService::login()` |

### verifyPasswordFallback

```php
public function verifyPasswordFallback(
    int $userId,
    string $email,
    #[\SensitiveParameter] string $password,
    string $clientIp,
): ?bool
```

Called from `AuthService::login()`, used by both the sign-in form and the API
login, when the submitted password does not match the engine's hash.

Already checked before the call:

- the attempt was counted by the login rate limiter;
- the user exists, is active, and is not the system account.

| Argument | Meaning |
| --- | --- |
| `$userId` | ID of the engine user found by e-mail. |
| `$email` | E-mail as submitted. |
| `$password` | Password as submitted, in plain text. |
| `$clientIp` | `REMOTE_ADDR` of the request. |

| Result | Effect |
| --- | --- |
| `true` | The user is logged in; the engine writes `auth.fallback_accepted user=<id>` to the error log. |
| `false` | The login is refused; later extensions are not asked. |
| `null` | Not decided by this extension; the next one is asked. If none accepts, the login is refused. |

Storing the password in the engine is the extension's decision, for example
with `$this->userRepository->updatePasswordHash()`; after that the user's next
logins succeed without the extension. The extension must not call
`AuthService::login()`.

[Back: Modules](https://github.com/bfhp/stream-engine/wiki/Developer-Modules) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Pages and routing](https://github.com/bfhp/stream-engine/wiki/Developer-Pages-and-Routing)
