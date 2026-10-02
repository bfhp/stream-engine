import { describe, expect, it } from "vitest";
import { userListParams, userListState } from "../../assets-src/admin/lib/users";

describe("admin user list", () => {
    it("distinguishes loading, error, empty and populated states", () => {
        expect(userListState(true, null, 0)).toBe("loading");
        expect(userListState(false, "Network error", 0)).toBe("error");
        expect(userListState(false, null, 0)).toBe("empty");
        expect(userListState(false, null, 2)).toBe("ready");
        expect(userListState(true, null, 2)).toBe("ready");
    });

    it("builds paginated search and filter parameters", () => {
        expect(userListParams(3, "  alice@example.com ", "admin", "active").toString())
            .toBe("page=3&q=alice%40example.com&role=admin&status=active");
    });

    it("omits blank optional filters", () => {
        expect(userListParams(1, "  ", null, null).toString()).toBe("page=1");
    });
});
