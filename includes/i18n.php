<?php
declare(strict_types=1);

/**
 * Πολυγλωσσία + προτιμήσεις εμφάνισης (γλώσσα, πλάτος).
 * Για νέα γλώσσα: πρόσθεσε αρχείο includes/lang/<κωδικός>.php και τον κωδικό στο VAROS_LANGS.
 */

const VAROS_LANGS = ['el' => 'Ελληνικά', 'en' => 'English'];
const VAROS_DEFAULT_LANG = 'el';
const VAROS_WIDTHS = ['narrow', 'wide', 'wider'];
const VAROS_DEFAULT_WIDTH = 'wide';

/** Γλώσσα: cookie -> γλώσσα προγράμματος περιήγησης -> προεπιλογή. */
function varos_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $cookie = $_COOKIE['varos_lang'] ?? '';
    if (isset(VAROS_LANGS[$cookie])) {
        return $lang = $cookie;
    }
    $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    foreach (preg_split('/\s*,\s*/', $accept) as $part) {
        $code = substr(trim(explode(';', $part)[0]), 0, 2);
        if (isset(VAROS_LANGS[$code])) {
            return $lang = $code;
        }
    }
    return $lang = VAROS_DEFAULT_LANG;
}

function varos_width(): string
{
    $cookie = $_COOKIE['varos_width'] ?? '';
    return in_array($cookie, VAROS_WIDTHS, true) ? $cookie : VAROS_DEFAULT_WIDTH;
}

/** Αποθήκευση προτίμησης σε cookie (1 έτος), περιορισμένο στον φάκελο της εφαρμογής. */
function varos_set_pref_cookie(string $name, string $value): void
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    setcookie($name, $value, ['expires' => time() + 365 * 86400, 'path' => $path, 'samesite' => 'Lax']);
}

function varos_messages(string $lang): array
{
    static $cache = [];
    return $cache[$lang] ??= require __DIR__ . '/lang/' . $lang . '.php';
}

/** Υπάρχει μετάφραση για το κλειδί στην τρέχουσα γλώσσα (χωρίς fallback); */
function t_has(string $key): bool
{
    return isset(varos_messages(varos_lang())[$key]);
}

/** Μετάφραση (ακατέργαστο κείμενο). Fallback: ελληνικά, μετά το ίδιο το κλειδί. */
function t(string $key, array $params = []): string
{
    $s = varos_messages(varos_lang())[$key] ?? varos_messages(VAROS_DEFAULT_LANG)[$key] ?? $key;
    if ($params) {
        $map = [];
        foreach ($params as $k => $v) {
            $map['{' . $k . '}'] = (string)$v;
        }
        $s = strtr($s, $map);
    }
    return $s;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Μετάφραση escaped, έτοιμη για HTML. */
function te(string $key, array $params = []): string
{
    return e(t($key, $params));
}

/** Μετάφραση όπου το πρότυπο γίνεται escape αλλά οι παράμετροι είναι έτοιμο HTML. */
function t_html(string $key, array $htmlParams): string
{
    $map = [];
    foreach ($htmlParams as $k => $v) {
        $map['{' . $k . '}'] = $v;
    }
    return strtr(e(t($key)), $map);
}

/** [δεκαδικό, χιλιάδες] ανά γλώσσα. */
function num_separators(): array
{
    return varos_lang() === 'el' ? [',', '.'] : ['.', ','];
}
