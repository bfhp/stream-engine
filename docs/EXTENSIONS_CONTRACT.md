# Extensions Contract and Development Plan

Date: November 18, 2026. Status: implemented (sections 6.1–6.4).

Sites built on Stream Engine need to change the behavior of selected platform
operations without forking the engine. The first case is a site that must
accept a password verified by its own code when the engine's hash does not
match. More cases of the same shape are expected elsewhere.

This contract defines the smallest general mechanism for that. It does not
change the existing module or theme contracts.

## 1. Decisions

- A site changes platform behavior with a class that extends
  `StreamEngine\Extension\Extension` and defines methods named after
  **extension points**. It defines only the methods it needs.
- Platform code calls a point through `ExtensionRegistry` by method name:
  `$this->extensions->verifyPasswordFallback($request)`.
- The registry calls, in order, every extension that has that method. The
  **first result that is not `null` wins** and is returned; later extensions
  are not called. With no extensions, or when all return `null`, the call
  returns `null` and the platform keeps its default behavior.
- An exception thrown by an extension is logged and treated as `null`.
- Extensions are found through the existing Composer classmap discovery and
  constructed **lazily** by the same resolver as controllers. The base class
  constructor takes the shared services controllers can receive, except
  `RequestContext`, and exposes them as protected properties named as in
  `StreamEngine` (`$this->db`, `$this->accessService`, …).
- Extension code is trusted, exactly like module code. This is not a sandbox.

Scope of the first version: the mechanism and one point,
`verifyPasswordFallback`. New points are added only when a site or module
actually needs one.

## 2. Writing an Extension

```php
namespace MySite\Extensions;

use StreamEngine\Extension\Extension;
use StreamEngine\Repository\UserRepository;

final class LegacyAuth extends Extension
{
    public function __construct(private readonly UserRepository $users) {}

    public function verifyPasswordFallback(int $userId, string $email, #[\SensitiveParameter] string $password, string $clientIp): ?bool
    {
        if (!$this->checkInLegacySystem($email, $password)) {
            return null;
        }

        $this->users->updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));

        return true;
    }
}
```

The class lives anywhere in the site's PSR-4 namespace. After adding,
renaming, or removing one, run `composer dump-autoload -o`, as for modules.
A site may have several extension classes, for example one per module.

Order of extensions: by fully qualified class name, so it is deterministic for
a given classmap.

## 3. How It Works

```text
bootstrap (StreamEngine::__construct)
  ModuleRegistry classmap pass
      instantiable subclasses of Extension  -> ExtensionRegistry (class names only)
  services that call points receive ExtensionRegistry
  ... all shared services built ...
  ServiceResolver::from(modules, ...shared services)
  ExtensionRegistry->bind(resolver)
  ControllerFactory(resolver)

request
  AuthService::login() -> $this->extensions->verifyPasswordFallback($request)
      ExtensionRegistry::__call()
          first call: instantiate every extension class through the resolver
          for each extension with method_exists(name):
              result = extension->name(...args)     (exception -> log, null)
              result !== null -> return result
          return null
```

- `ServiceResolver` is the reflection logic of `ControllerFactory::instantiate()`
  moved to a shared class; `ControllerFactory` delegates to it without behavior
  change.
- A constructor asking for `RequestContext` is an error. Data a point needs
  from the request is passed as arguments, so points also work from cron and
  CLI.
- Calling a point before `bind()` is a `LogicException`; points used during
  composition are not supported.

## 4. Adding a Point

1. Choose a method name and plain arguments; mark secrets with
   `#[\SensitiveParameter]`. A new argument of an existing point goes last and
   has a default value.
2. Call it from platform code through `ExtensionRegistry` and handle `null` as
   "keep the default behavior".
3. Add the point to the table in section 5: when it is called, what is already
   checked, what a non-null result means.
4. Test that with no extensions the operation behaves exactly as before.

Changing the name or arguments of an existing point is a breaking change for
sites and goes to the CHANGELOG.

## 5. Extension Points

| Method | Called from | Arguments | Non-null result |
| --- | --- | --- | --- |
| `verifyPasswordFallback` | `AuthService::login()`, only for an existing, active, non-system user whose password did not match the engine's hash, after the attempt was counted by the rate limiter | `int $userId, string $email, string $password, string $clientIp` | `true` logs the user in; `false` refuses without asking later extensions |

For `verifyPasswordFallback` the extension decides whether and how to store
the password in the engine, for example with
`UserRepository::updatePasswordHash()`. It must not call
`AuthService::login()`. The engine logs
`auth.fallback_accepted user=<id>` on `true`. Web and API
logins both go through `AuthService::login()`.

## 6. Development Sequence

1. Extract `ServiceResolver` from `ControllerFactory`; `ControllerFactoryTest`
   passes unchanged.
2. Add `Extension` and `ExtensionRegistry`; collect extension classes in the
   `ModuleRegistry` classmap pass; wire and bind in `StreamEngine::__construct()`.
3. Add `UserRepository::updatePasswordHash()` and the `AuthService` change.
4. Wiki page section on extensions in `Developer-Modules.md`, including the
   table from section 5.

Tests: discovery, order, lazy construction, first non-null wins, exception is
logged and skipped, call before bind; for `AuthService`, the fallback is not
asked for unknown, system or inactive users or when the rate limit is
exhausted, `true` creates a session, an exception never logs the user in.

## 7. Deferred Until a Real Need

- Points that need all results (collect) or a value passed through every
  extension (pipeline), added as separate registry methods.
- Events for reacting to completed operations.
- Replacing the inline password `UPDATE`s in `UserService` with
  `UserRepository::updatePasswordHash()`.
