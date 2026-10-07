import { ActionIcon, Button, Group, PasswordInput, Select, Stack, Text, TextInput, Tooltip } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconDeviceFloppy, IconRefresh } from "@tabler/icons-react";
import { useEffect, useState } from "react";
import { csrfHeaders } from "../../shared/csrf";
import { trans } from "../../shared/i18n";

const DEFAULT_FIELD = "contact_reference";
const DEFAULT_MODE = "email";
const DEFAULT_CAPTCHA_PROVIDER = "none";

type RegistrationSettings = {
    mode: string;
    honeypotField: string;
    captchaProvider: string;
    captchaSiteKey: string;
    captchaSecretConfigured: boolean;
};

function isValidField(value: string): boolean {
    return /^[A-Za-z][A-Za-z0-9_]{2,63}$/.test(value)
        && value !== "email"
        && value !== "password";
}

export default function Registration() {
    const [savedField, setSavedField] = useState(DEFAULT_FIELD);
    const [field, setField] = useState(DEFAULT_FIELD);
    const [savedMode, setSavedMode] = useState(DEFAULT_MODE);
    const [mode, setMode] = useState(DEFAULT_MODE);
    const [savedCaptchaProvider, setSavedCaptchaProvider] = useState(DEFAULT_CAPTCHA_PROVIDER);
    const [captchaProvider, setCaptchaProvider] = useState(DEFAULT_CAPTCHA_PROVIDER);
    const [savedCaptchaSiteKey, setSavedCaptchaSiteKey] = useState("");
    const [captchaSiteKey, setCaptchaSiteKey] = useState("");
    const [captchaSecret, setCaptchaSecret] = useState("");
    const [captchaSecretConfigured, setCaptchaSecretConfigured] = useState(false);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);

        fetch("/api/v1/admin/registration", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.settings.load_failed"));
                }

                return response.json();
            })
            .then(data => {
                const settings = data as RegistrationSettings;
                const nextField = settings.honeypotField || DEFAULT_FIELD;
                const storedMode = settings.mode || DEFAULT_MODE;
                const nextMode = ["closed", "email", "open"].includes(storedMode) ? storedMode : "closed";
                const storedCaptchaProvider = settings.captchaProvider || DEFAULT_CAPTCHA_PROVIDER;
                const nextCaptchaProvider = ["none", "turnstile", "hcaptcha"].includes(storedCaptchaProvider)
                    ? storedCaptchaProvider
                    : DEFAULT_CAPTCHA_PROVIDER;
                setSavedField(nextField);
                setField(nextField);
                setSavedMode(nextMode);
                setMode(nextMode);
                setSavedCaptchaProvider(nextCaptchaProvider);
                setCaptchaProvider(nextCaptchaProvider);
                setSavedCaptchaSiteKey(settings.captchaSiteKey || "");
                setCaptchaSiteKey(settings.captchaSiteKey || "");
                setCaptchaSecret("");
                setCaptchaSecretConfigured(Boolean(settings.captchaSecretConfigured));
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

    const trimmedField = field.trim();
    const trimmedCaptchaSiteKey = captchaSiteKey.trim();
    const trimmedCaptchaSecret = captchaSecret.trim();
    const captchaEnabled = captchaProvider !== DEFAULT_CAPTCHA_PROVIDER;
    const savedSecretCanBeKept = captchaProvider === savedCaptchaProvider && captchaSecretConfigured;
    const captchaValid = !captchaEnabled
        || (trimmedCaptchaSiteKey !== "" && (trimmedCaptchaSecret !== "" || savedSecretCanBeKept));
    const valid = isValidField(trimmedField) && captchaValid;
    const dirty = trimmedField !== savedField
        || mode !== savedMode
        || captchaProvider !== savedCaptchaProvider
        || trimmedCaptchaSiteKey !== savedCaptchaSiteKey
        || trimmedCaptchaSecret !== "";

    function save() {
        if (!valid || !dirty) return;

        setSaving(true);
        fetch("/api/v1/admin/registration", {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                ...csrfHeaders(),
            },
            body: JSON.stringify({
                mode,
                honeypotField: trimmedField,
                captchaProvider,
                captchaSiteKey: trimmedCaptchaSiteKey,
                captchaSecret: trimmedCaptchaSecret,
            }),
        })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.settings.save_failed"));
                }

                return response.json() as Promise<RegistrationSettings>;
            })
            .then(settings => {
                setMode(settings.mode);
                setSavedMode(settings.mode);
                setField(settings.honeypotField);
                setSavedField(settings.honeypotField);
                setCaptchaProvider(settings.captchaProvider);
                setSavedCaptchaProvider(settings.captchaProvider);
                setCaptchaSiteKey(settings.captchaSiteKey);
                setSavedCaptchaSiteKey(settings.captchaSiteKey);
                setCaptchaSecret("");
                setCaptchaSecretConfigured(settings.captchaSecretConfigured);
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
                        disabled={!valid || !dirty}
                        leftSection={<IconDeviceFloppy size={16} />}
                        onClick={save}
                    >
                        {trans("js.admin.save")}
                    </Button>
                </Group>
            </Group>

            <Select
                label={trans("js.admin.registration.mode")}
                description={trans("js.admin.registration.mode_description")}
                data={[
                    { value: "closed", label: trans("js.admin.registration.mode_closed") },
                    { value: "email", label: trans("js.admin.registration.mode_email") },
                    { value: "open", label: trans("js.admin.registration.mode_open") },
                ]}
                value={mode}
                onChange={nextMode => setMode(nextMode || DEFAULT_MODE)}
                allowDeselect={false}
            />

            <TextInput
                label={trans("js.admin.registration.honeypot_field")}
                description={trans("js.admin.registration.honeypot_description")}
                value={field}
                error={field !== "" && !valid ? trans("js.admin.registration.honeypot_invalid") : undefined}
                maxLength={64}
                onChange={event => setField(event.currentTarget.value)}
            />

            <Select
                label={trans("js.admin.registration.captcha")}
                description={trans("js.admin.registration.captcha_description")}
                data={[
                    { value: "none", label: trans("js.admin.registration.captcha_none") },
                    { value: "turnstile", label: trans("js.admin.registration.captcha_turnstile") },
                    { value: "hcaptcha", label: trans("js.admin.registration.captcha_hcaptcha") },
                ]}
                value={captchaProvider}
                onChange={nextProvider => setCaptchaProvider(nextProvider || DEFAULT_CAPTCHA_PROVIDER)}
                allowDeselect={false}
            />

            {captchaEnabled && (
                <>
                    <TextInput
                        label={trans("js.admin.registration.captcha_site_key")}
                        value={captchaSiteKey}
                        error={trimmedCaptchaSiteKey === ""
                            ? trans("js.admin.registration.captcha_required")
                            : undefined}
                        maxLength={512}
                        onChange={event => setCaptchaSiteKey(event.currentTarget.value)}
                    />
                    <PasswordInput
                        label={trans("js.admin.registration.captcha_secret")}
                        description={savedSecretCanBeKept
                            ? trans("js.admin.registration.captcha_secret_keep")
                            : undefined}
                        value={captchaSecret}
                        error={!savedSecretCanBeKept && trimmedCaptchaSecret === ""
                            ? trans("js.admin.registration.captcha_required")
                            : undefined}
                        maxLength={2048}
                        onChange={event => setCaptchaSecret(event.currentTarget.value)}
                    />
                </>
            )}
        </Stack>
    );
}
