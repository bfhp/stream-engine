/*
 * The admin panel's translation functions: the site strings plus the
 * `js.admin.*` keys. Those come from their own catalog
 * (`stream-engine-translations/admin`), which only the panel imports, so site
 * pages never download them. Admin code imports trans() and friends from here;
 * the rest of shared/i18n is re-exported for convenience.
 */
import siteCatalog from "stream-engine-translations";
import adminCatalog from "stream-engine-translations/admin";
import { createTranslator } from "../shared/i18n";

export { getAvailableLocales, getLocale, resetTranslations, setTranslationsForTests } from "../shared/i18n";
export type { TranslationParams } from "../shared/i18n";

export const { trans, transChoice, transChoiceWithCount } = createTranslator({ ...siteCatalog, ...adminCatalog });
