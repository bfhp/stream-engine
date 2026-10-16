import { forwardRef, useState, type FocusEvent } from "react";
import { Select, type SelectProps } from "@mantine/core";

/*
 * Mantine's Select closes its list on blur only when it is searchable; a
 * plain one stayed open after Tab moved on, so the list of one field hung
 * over the next (WCAG 2.4.3, 1.4.13). This keeps the dropdown state here
 * and closes it whenever the field loses focus. Choosing an option does not
 * blur the field, so selection by pointer is unaffected.
 *
 * Use it instead of Select everywhere in the administration panel.
 */
const AdminSelect = forwardRef<HTMLInputElement, SelectProps>(function AdminSelect(
    { onBlur, onDropdownOpen, onDropdownClose, ...props },
    ref,
) {
    const [opened, setOpened] = useState(false);

    return (
        <Select
            {...props}
            ref={ref}
            dropdownOpened={opened}
            onDropdownOpen={() => {
                setOpened(true);
                onDropdownOpen?.();
            }}
            onDropdownClose={() => {
                setOpened(false);
                onDropdownClose?.();
            }}
            onBlur={(event: FocusEvent<HTMLInputElement>) => {
                setOpened(false);
                onBlur?.(event);
            }}
        />
    );
});

export default AdminSelect;
