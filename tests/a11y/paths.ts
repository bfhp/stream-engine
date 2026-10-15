import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));

export const PROJECT_ROOT = path.resolve(here, "../..");

/** Everything the scan writes goes below the git-ignored storage directory. */
const OUTPUT_ROOT = path.join(PROJECT_ROOT, "storage/a11y");

/**
 * run.mjs sets A11Y_RUN_LABEL (for example "bootstrap-ar") for each
 * theme/locale combination, so their reports do not overwrite each other.
 */
const runLabel = (process.env.A11Y_RUN_LABEL ?? "").replace(/[^A-Za-z0-9_-]/g, "");
export const OUTPUT_DIR = runLabel ? path.join(OUTPUT_ROOT, "runs", runLabel) : OUTPUT_ROOT;

/** Signed-in administrator session, produced by auth.setup.ts; run.mjs reads it too. */
export const ADMIN_STATE = path.join(OUTPUT_ROOT, "admin-state.json");

/** Known violations that do not fail the scan. */
export const BASELINE_FILE = path.join(here, "baseline.json");

/** The sign-in trigger in the bundled themes: `default` uses data-ui-*, `bootstrap` data-bs-*. */
export const SIGN_IN_BUTTON = '[data-ui-target="#authFormModal"], [data-bs-target="#authFormModal"]';
