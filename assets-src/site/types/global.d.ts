export {};

import type CMS_TYPE from "../app";

declare global {
    interface Window {
        CMS: typeof CMS_TYPE;
    }
}
