/*
 * Pages get their strings from the per-locale file the server links
 * (js/i18n/<locale>.js, plus <locale>-admin.js in the admin panel). Tests have
 * no server, so every test file starts with all locales and both scopes
 * registered - shared/i18n.ts picks the document locale on first use.
 */
import entries from "virtual:translations";

(globalThis as { __streamEngineTranslations?: unknown }).__streamEngineTranslations = entries;
