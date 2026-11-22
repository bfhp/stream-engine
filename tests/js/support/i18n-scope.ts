/*
 * shared/i18n.ts loads the `js.admin.*` keys only on a page marked
 * `<html data-i18n-scope="admin">` (the admin panel). Tests exercise both
 * site and admin code, so every test file gets both scopes - set before any
 * test imports i18n.ts, which reads the attribute once at load.
 */
document.documentElement.dataset.i18nScope = "admin";
