<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;
use JsonException;
use Normalizer;

class Formatter
{
    private string $locale;
    private TranslationManager $tm;
    private ?IntlDateFormatter $dateFormatter = null;
    private ?IntlDateFormatter $dateTimeFormatter = null;
    private ?IntlDateFormatter $monthYearFormatter = null;

    public function __construct(TranslationManager $tm, string $locale = 'en')
    {
        $this->tm = $tm;
        $this->locale = $locale;
    }

    private function dateFormatter(): IntlDateFormatter
    {
        if ($this->dateFormatter === null) {
            $this->dateFormatter = new IntlDateFormatter(
                $this->locale,
                IntlDateFormatter::LONG,
                IntlDateFormatter::NONE,
                null,
                null,
                'd MMMM yyyy'
            );
        }

        return $this->dateFormatter;
    }

    private function dateTimeFormatter(): IntlDateFormatter
    {
        if ($this->dateTimeFormatter === null) {
            $this->dateTimeFormatter = new IntlDateFormatter(
                $this->locale,
                IntlDateFormatter::LONG,
                IntlDateFormatter::SHORT
            );
        }

        return $this->dateTimeFormatter;
    }

    private function monthYearFormatter(): IntlDateFormatter
    {
        if ($this->monthYearFormatter === null) {
            // 'LLLL' (not 'MMMM') for the stand-alone nominative month form
            // (the nominative rather than the day-adjacent genitive form
            // date() uses above), as needed by a public profile's joined-at stat.
            $this->monthYearFormatter = new IntlDateFormatter(
                $this->locale,
                IntlDateFormatter::LONG,
                IntlDateFormatter::NONE,
                null,
                null,
                'LLLL yyyy'
            );
        }

        return $this->monthYearFormatter;
    }

    // --- DATE ---

    public function date(DateTimeInterface $dt, bool $withYearWord = false): string
    {
        $result = $this->dateFormatter()->format($dt);

        if ($withYearWord) {
            $result .= $this->tm->trans('time.year_suffix');
        }

        return $result;
    }

    public function datetime(DateTimeInterface $dt): string
    {
        return $this->dateTimeFormatter()->format($dt);
    }

    public function monthYear(DateTimeInterface $dt): string
    {
        return $this->monthYearFormatter()->format($dt);
    }

    public function relative(DateTimeInterface $dt): string
    {
        $now = new DateTimeImmutable();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        if ($diff < 60) {
            return $this->tm->trans('time.just_now');
        }

        if ($diff < 120) {
            return $this->tm->trans('time.minute_ago');
        }

        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return $this->tm->trans('time.minutes_ago', [
                'count' => $m,
                'unit' => $this->unit($m, 'time.minute')
            ]);
        }

        if ($diff < 7200) {
            return $this->tm->trans('time.hour_ago');
        }

        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return $this->tm->trans('time.hours_ago', [
                'count' => $h,
                'unit' => $this->unit($h, 'time.hour')
            ]);
        }

        if ($diff < 2592000) {
            $d = (int) floor($diff / 86400);
            return $this->tm->trans('time.days_ago', [
                'count' => $d,
                'unit' => $this->unit($d, 'time.day')
            ]);
        }

        return $this->date($dt, true);
    }

    private function unit(int $n, string $key): string
    {
        $forms = $this->tm->getAll()[$key] ?? null;

        if (!$forms || count($forms) < 3) {
            return $key; // fallback
        }

        return $this->plural($n, $forms[0], $forms[1], $forms[2]);
    }

    // --- NUMBERS / TEXT ---

    public function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;

        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 == 1) {
            return $one;
        }

        return $many;
    }

    // --- TEMPLATE ---

    public function format(string $template, array $params = []): string
    {
        foreach ($params as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }

        return $template;
    }

    // --- SLUGS ---

    /**
     * The single-codepoint latin letters that Unicode decomposition does not
     * reach, plus the plain accented forms as a belt-and-braces fallback for
     * a build without ext-intl. See foldDiacritics() for why both halves are
     * needed.
     */
    private const array DIACRITIC_FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    private const array SLUG_TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /**
     * Turns free-text, possibly-cyrillic input into a URL/handle-safe
     * alphanumeric string: lowercases, transliterates cyrillic to latin, and
     * collapses every run of remaining disallowed characters into
     * $separator. Static - it's a pure string transform, not something that
     * needs a locale or any other instance state.
     *
     * Used wherever something user-facing (a nick, a WP user_login, a post
     * title) needs to become part of a URL or a bare handle - callers are
     * responsible for their own uniqueness/collision handling (e.g.
     * appending a numeric suffix), since what counts as "taken" differs per
     * use case.
     *
     * @param string $separator Placed between words in place of whatever
     *     was there (e.g. '-' for a readable URL slug). Pass '' (default)
     *     for a bare alphanumeric handle, like a username - in which case
     *     nothing is trimmed since there's no separator to trim.
     * @param int|null $maxLength Cut to this many characters, then trimmed
     *     again so the cut cannot leave a dangling separator.
     * @param string $fallback Returned when nothing sluggable survived. An
     *     empty slug is worse than an ugly one: it makes a URL of `/tags//`
     *     and collides with every other unsluggable name. Note it is
     *     *shared* - two such names get the same fallback - so a caller that
     *     needs uniqueness still has to add its own suffix.
     */
    public static function slugify(
        string $value,
        string $separator = '',
        ?int $maxLength = null,
        string $fallback = ''
    ): string {
        // Order matters, and getting it wrong is silent. foldDiacritics()
        // decomposes with NFD and strips the combining marks - and the Cyrillic short-I is
        // canonically the corresponding base letter plus a breve, while yo uses a diaeresis. Folding
        // first therefore ate both letters before SLUG_TRANSLIT could see
        // them, so the Cyrillic word for yoga became `ioga` and its short-I mapping in the
        // table below was unreachable code.
        //
        // So: transliterate Cyrillic first, using the table that was written
        // for it, and fold afterwards - which is what folding is for anyway,
        // latin letters carrying accents that have no transliteration of
        // their own.
        $value = strtr(mb_strtolower(trim($value)), self::SLUG_TRANSLIT);
        $value = self::foldDiacritics($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', $separator, $value);

        if ($separator !== '') {
            $value = trim($value, $separator);
        }

        if ($maxLength !== null) {
            $value = mb_substr($value, 0, $maxLength);

            // Trimmed *again* after the cut. FeedTermRepository's version did
            // not, so a name truncated mid-word left a trailing separator -
            // `mikhajlovich-viktor` cut at 14 became `mikhajlovich-v`, but cut
            // at 13 became `mikhajlovich-`, a slug with a dangling dash.
            if ($separator !== '') {
                $value = rtrim($value, $separator);
            }
        }

        return $value !== '' ? $value : $fallback;
    }

    /**
     * A slug that keeps its letters instead of transliterating them:
     * a mixed-case Cyrillic tag stays Cyrillic and becomes lowercase.
     *
     * The deliberate counterpart to slugify(), and the reason both exist. A
     * feed slug is machine-adjacent and long-lived, so it is transliterated;
     * a *term* slug is the whole of a readable URL - a readable Unicode tag URL - and
     * `FeedTermRepositoryTest` pins that as a decision rather than an
     * oversight. Modern browsers show percent-encoded Cyrillic decoded in the
     * address bar, so the readable form really is readable.
     *
     * `\p{L}\p{N}` rather than `[a-z0-9]`, so any script survives - and
     * Unicode digits count, which is why a Cyrillic title followed by `٣` keeps its Arabic-Indic
     * numeral. No diacritic folding either: there is nothing to fold *to*
     * when the letter is allowed to stay as it is.
     */
    public static function unicodeSlug(
        string $value,
        string $separator = '-',
        ?int $maxLength = null,
        string $fallback = ''
    ): string {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', $separator, $value);

        if ($separator !== '') {
            $value = trim($value, $separator);
        }

        if ($maxLength !== null) {
            $value = mb_substr($value, 0, $maxLength);

            if ($separator !== '') {
                $value = rtrim($value, $separator);
            }
        }

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Lowercases and reduces accented latin to plain ascii: `Müller` becomes
     * `muller`, `Kübler-Ross` becomes `kubler-ross`.
     *
     * Two passes because neither alone is enough. Unicode decomposition (NFD)
     * splits `ü` into `u` plus a combining diaeresis, which `\p{Mn}` then
     * strips - that handles every accent there is, but only for characters
     * that decompose. The table catches the ones that do not: `ø`, `ł`, `đ`,
     * `ħ`, `ŧ` are single codepoints with no base letter to fall back to.
     *
     * Slugify needs this because the step after it deletes anything outside
     * `[a-z0-9]`, so an unfolded `ü` is not merely unconverted - it is *gone*,
     * and `müller` came out as `m-ller`. Keeping this helper public lets
     * callers reuse the same normalization rules.
     */
    public static function foldDiacritics(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_D);

            if ($normalized !== false) {
                $value = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $value;
            }
        }

        return strtr($value, self::DIACRITIC_FOLD);
    }

    /**
     * Undoes the legacy CMS's own habit of storing free-text columns
     * (`title`/`header`/quiz `question`/answer `title`, seen so far) HTML-
     * entity-encoded - e.g. an entity-encoded quoted title for a title containing
     * literal quote marks - which, left as-is, gets *re*-escaped once more
     * by the Twig template that eventually renders it, showing up on the
     * page as literal `&quot;` instead of a quote mark.
     *
     * A single html_entity_decode() call only unescapes one encoding pass;
     * the legacy dump isn't consistent about how many passes some values
     * went through before being stored (WpFeedTagsImport-adjacent code
     * found plain single-encoded `&quot;...&quot;` in forum/blog titles,
     * but feed_quiz.question has been seen *double*-encoded, e.g.
     * a double-encoded quoted title - the `&` itself got escaped too),
     * so this keeps decoding until a pass changes nothing, capped at
     * $maxPasses purely as a defensive bound against looping forever on
     * some pathological input that keeps forming new-looking entities out
     * of its own decoded text (practically never happens - genuine legacy
     * values need at most two passes).
     */
    public static function decodeLegacyText(string $value, int $maxPasses = 5): string
    {
        $previous = null;
        $passes = 0;

        while ($previous !== $value && $passes < $maxPasses) {
            $previous = $value;
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            $passes++;
        }

        return $value;
    }

    /**
     * How this application writes JSON to a client.
     *
     * One place rather than a flag argument repeated at eighty `json_encode()`
     * calls, because the flags are not a per-call decision - they are what a
     * response from this API looks like, and the failure mode of getting them
     * wrong is invisible.
     *
     * The three flags, and why each is not the default:
     *
     * **JSON_UNESCAPED_UNICODE.** PHP escapes every non-ASCII character to
     * `\uXXXX` by default, which is valid JSON and parses back identically - so
     * nothing is broken without it, which is exactly why it went unnoticed. The
     * cost is size: a Cyrillic character is 2 bytes as UTF-8 and 6 as an escape,
     * so a response of Russian text triples. On this site almost every payload is
     * Russian - city names, book titles, forum posts, messages - and the city
     * autocomplete fires on every keystroke.
     *
     * **JSON_UNESCAPED_SLASHES.** The other half of the same story: every URL in
     * a payload goes out as `https:\/\/example.com\/x`. The escaping is a legacy
     * of embedding JSON in HTML `<script>` blocks, where `</` had to be broken up.
     * Nothing here does that - these are `application/json` responses read by
     * `fetch()`.
     *
     * **JSON_THROW_ON_ERROR.** Without it `json_encode()` returns `false` on
     * malformed UTF-8, on INF/NAN, and past the recursion limit - and
     * `echo false` prints nothing. The client gets **HTTP 200 with an empty
     * body**, which it reports as whatever its own error handling decides, and
     * nothing anywhere records that a response was lost. With it, the same input
     * raises a JsonException, which Core\ApiErrorResponse turns into a logged
     * 500. Not a smaller failure - a visible one.
     *
     * Deliberately not used for: values written to the database or to a cookie
     * (`GuestFeedReadStore` and the base64-encoded keyset cursors) or outgoing
     * request bodies. Those are not responses, and changing how they
     * are stored would mean old rows and new rows encoded differently for no gain.
     */
    /**
     * @throws JsonException when the value cannot be represented as JSON -
     *                       see above for why that is preferable to an
     *                       empty 200.
     */
    public static function json(mixed $value, int $extraFlags = 0): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | $extraFlags
        );
    }
}
