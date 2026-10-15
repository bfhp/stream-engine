import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));

export const PROJECT_ROOT = path.resolve(here, "../..");

/** Everything the scan writes goes below the git-ignored storage directory. */
export const OUTPUT_DIR = path.join(PROJECT_ROOT, "storage/a11y");

/** Signed-in administrator session, produced by auth.setup.ts. */
export const ADMIN_STATE = path.join(OUTPUT_DIR, "admin-state.json");

/** Known violations that do not fail the scan. */
export const BASELINE_FILE = path.join(here, "baseline.json");

/** The sign-in trigger in the bundled themes: `default` uses data-ui-*, `bootstrap` data-bs-*. */
export const SIGN_IN_BUTTON = '[data-ui-target="#authFormModal"], [data-bs-target="#authFormModal"]';
