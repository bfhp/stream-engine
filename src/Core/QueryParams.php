<?php

declare(strict_types=1);

namespace StreamEngine\Core;

/**
 * The request's query string, as a value rather than as a superglobal.
 *
 * This replaces `filter_input(INPUT_GET, …)`, which had one property that
 * made it worth removing everywhere: it reads the **SAPI's own copy** of the
 * request, taken before any PHP code ran. Under the CLI SAPI that PHPUnit
 * uses there is no such request, so `filter_input()` returns `null` for
 * everything, always - and no test can make it return anything else. Every
 * branch behind a query parameter was therefore unreachable, in
 * `SearchController`, `ProfileController`, `APIController`, `ForumsController`,
 * `UsersController` and `FeedbackController`. Some tests had resorted to declaring their own
 * `filter_input()` in the module's namespace so PHP's name resolution would
 * find theirs first, which works and is a trick nobody should have to know.
 *
 * A snapshot, not a live view of `$_GET`: `fromGlobals()` copies the array
 * once, at the start of the request. That keeps the one genuinely good thing
 * about `filter_input()` - later code cannot change what the request said -
 * while making the value something a test can simply construct.
 *
 * Every accessor is total: a missing key, a key holding an array
 * (`?q[]=a` is a legal request), and a value the filter rejects all produce
 * the caller's default. So the return types are honest and no call site needs
 * a null check or a cast, which is what the old `trim((string) filter_input(…))`
 * idiom was working around.
 */
final readonly class QueryParams
{
    /**
     * @param array<array-key, mixed> $params
     */
    public function __construct(
        private array $params = []
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->params);
    }

    /**
     * The raw value, or the default if it is absent or not a string.
     *
     * An array is not an error: `?type[]=article` is a request a browser can be
     * made to send, and `(string) $value` is an "Array to string conversion"
     * warning followed by the literal text `Array`. Treating it as absent is
     * what `filter_input()` did (it answered `false` without
     * FILTER_REQUIRE_ARRAY) and is the only reading that cannot surprise a
     * caller with the wrong type.
     */
    public function string(string $name, string $default = ''): string
    {
        $value = $this->params[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * `string()` with the surrounding whitespace removed - the shape every
     * text parameter actually wants, since `?q=%20%20` is an empty search
     * rather than a two-character one.
     */
    public function trimmed(string $name, string $default = ''): string
    {
        $value = $this->string($name, $default);

        return trim($value);
    }

    /**
     * A whole number, or the default if the value is absent, non-numeric or
     * fractional.
     *
     * Note this differs from the `filter_input(…, FILTER_VALIDATE_INT) ?: N`
     * idiom it replaces, which used `?:` and so treated a valid **0** as a
     * failure. `?child_limit=0` asked for no children and got three. Zero is
     * a number here; callers that need a floor already clamp with `max()`.
     */
    public function int(string $name, int $default = 0): int
    {
        $value = $this->params[$name] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return $default;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return $validated === false ? $default : $validated;
    }

    /**
     * `?flag=1|true|on|yes` -> true, `?flag=0|false|off|no|''` -> false,
     * absent or unrecognised -> null.
     *
     * Three states rather than two because the pages that use it need to tell
     * "the visitor did not come from a redirect" apart from "they did, and it
     * said no" - `ProfileController`'s `?registered=1` banner is the case.
     */
    public function boolOrNull(string $name): ?bool
    {
        $value = $this->params[$name] ?? null;

        if (! is_string($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->params;
    }
}
