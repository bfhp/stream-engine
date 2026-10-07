import { ActionIcon, Button, Group, Stack, Text, TextInput, Tooltip } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconDeviceFloppy, IconRefresh } from "@tabler/icons-react";
import { useEffect, useState } from "react";
import { csrfHeaders } from "../../shared/csrf";
import { trans } from "../../shared/i18n";

const SETTING_KEY = "registration.honeypot_field";
const DEFAULT_FIELD = "contact_reference";

type Setting = {
    key: string;
    value: string;
};

function isValidField(value: string): boolean {
    return /^[A-Za-z][A-Za-z0-9_]{2,63}$/.test(value)
        && value !== "email"
        && value !== "password";
}

export default function Registration() {
    const [savedValue, setSavedValue] = useState(DEFAULT_FIELD);
    const [value, setValue] = useState(DEFAULT_FIELD);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);

        fetch("/api/v1/admin/settings", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.settings.load_failed"));
                }

                return response.json();
            })
            .then(data => {
                const settings: Setting[] = Array.isArray(data.data) ? data.data : [];
                const field = settings.find(setting => setting.key === SETTING_KEY)?.value || DEFAULT_FIELD;
                setSavedValue(field);
                setValue(field);
            })
            .catch(error => {
                if (error.name !== "AbortError") {
                    notifications.show({
                        color: "red",
                        title: trans("js.admin.error"),
                        message: error.message || trans("js.admin.settings.load_failed"),
                    });
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [refreshKey]);

    const trimmedValue = value.trim();
    const valid = isValidField(trimmedValue);

    function save() {
        if (!valid || trimmedValue === savedValue) return;

        setSaving(true);
        fetch(`/api/v1/admin/settings/${SETTING_KEY}`, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                ...csrfHeaders(),
            },
            body: JSON.stringify({ value: trimmedValue }),
        })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.settings.save_failed"));
                }

                return response.json();
            })
            .then(() => {
                setSavedValue(trimmedValue);
                setValue(trimmedValue);
                notifications.show({
                    color: "green",
                    message: trans("js.admin.settings.saved"),
                    autoClose: 2000,
                });
            })
            .catch(error => notifications.show({
                color: "red",
                title: trans("js.admin.error"),
                message: error.message || trans("js.admin.settings.save_failed"),
            }))
            .finally(() => setSaving(false));
    }

    return (
        <Stack>
            <Group justify="space-between" align="end">
                <Text fw={700} size="xl">{trans("js.admin.registration.title")}</Text>
                <Group>
                    <Tooltip label={trans("js.admin.refresh")}>
                        <ActionIcon
                            variant="default"
                            loading={loading}
                            onClick={() => setRefreshKey(key => key + 1)}
                        >
                            <IconRefresh size={18} />
                        </ActionIcon>
                    </Tooltip>
                    <Button
                        loading={saving}
                        disabled={!valid || trimmedValue === savedValue}
                        leftSection={<IconDeviceFloppy size={16} />}
                        onClick={save}
                    >
                        {trans("js.admin.save")}
                    </Button>
                </Group>
            </Group>

            <TextInput
                label={trans("js.admin.registration.honeypot_field")}
                description={trans("js.admin.registration.honeypot_description")}
                value={value}
                error={value !== "" && !valid ? trans("js.admin.registration.honeypot_invalid") : undefined}
                maxLength={64}
                onChange={event => setValue(event.currentTarget.value)}
            />
        </Stack>
    );
}
