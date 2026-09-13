declare module "virtual:translations" {
    const catalogs: Record<string, Record<string, string | string[]>>;
    export default catalogs;
}
