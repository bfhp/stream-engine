/** Whether the server rendered this document right-to-left (`<html dir="rtl">`). */
export function isRtl(): boolean {
    return document.documentElement.dir === "rtl";
}
