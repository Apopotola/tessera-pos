"use client";

import { Alert, Anchor, Box, Button, Checkbox, Divider, Group, Modal, PasswordInput, Stack, Text, TextInput, Title } from "@mantine/core";
import { isNotEmpty, useForm } from "@mantine/form";
import { useDisclosure } from "@mantine/hooks";
import { IconAlertCircle, IconCalculator } from "@tabler/icons-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { brand } from "@/app/theme";
import BottleSkyline from "@/components/brand/BottleSkyline";
import Logo from "@/components/brand/Logo";
import { useBrand } from "@/components/brand/useBrand";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { login } from "@/store/slices/authSlice";
import type { LoginPayload } from "@/types/auth";
import { safeRedirectPath } from "@/utils/menu";
import classes from "./LoginForm.module.css";

/** Back-office sign-in: brand panel (left / top on phones) and the form. */
export default function LoginForm() {
  const dispatch = useAppDispatch();
  const router = useRouter();
  const searchParams = useSearchParams();
  const { status, loginError, loginFieldErrors } = useAppSelector((state) => state.auth);
  const [submitting, setSubmitting] = useState(false);
  const [forgotOpened, forgot] = useDisclosure(false);
  const next = safeRedirectPath(searchParams.get("next"));
  const branding = useAppSelector((state) => state.settings.branding);
  const { poweredBy } = useBrand();
  const centred = branding?.loginStyle === "centered";
  const background = branding?.loginBackground ?? "bottles";
  const backgroundImage = background === "image" && branding?.loginBackgroundImage ? `url("${branding.loginBackgroundImage}")` : undefined;

  const form = useForm<Required<LoginPayload>>({
    mode: "uncontrolled",
    initialValues: { login: "", password: "", remember: false },
    validate: {
      login: isNotEmpty("Enter your email or phone number"),
      password: isNotEmpty("Enter your password"),
    },
  });

  useEffect(() => {
    if (status === "authenticated") router.replace(next);
  }, [status, next, router]);

  const handleSubmit = async (values: Required<LoginPayload>) => {
    if (submitting) return;
    setSubmitting(true);
    const result = await dispatch(login({ ...values, login: values.login.trim() }));
    if (login.rejected.match(result)) {
      form.setErrors(result.payload?.fieldErrors ?? {});
      setSubmitting(false);
    }
  };

  return (
    <div className={`${classes.page} ${centred ? classes.centred : ""}`}>
      <section
        className={`${classes.brandPanel} ${background === "mosaic" ? classes.mosaic : ""}`}
        style={{ backgroundColor: brand.navy, backgroundImage, backgroundSize: "cover", backgroundPosition: "center" }}
      >
        <Logo size={30} />
        <Box className={classes.headline}>
          <h1 className={`tessera-display ${classes.title}`}>
            Every bottle,
            <br />
            counted.
          </h1>
          <Text className={classes.tagline}>Sales, stock and shifts for your wine & spirits shop, in one place.</Text>
        </Box>
        {background === "bottles" && (
          <div className={classes.skyline}>
            <BottleSkyline height={230} />
          </div>
        )}
      </section>

      <section className={classes.formPanel} style={{ background: brand.cream }}>
        <form onSubmit={form.onSubmit(handleSubmit)} noValidate className={classes.form}>
          <Stack gap="lg">
            <div>
              <Text size="xs" fw={600} c="dimmed" tt="uppercase" style={{ letterSpacing: "0.12em" }}>
                Back office
              </Text>
              <Title order={1} fz={branding?.welcomeText && branding.welcomeText.length > 24 ? 26 : 32} c={brand.navy}>
                {branding?.welcomeText ?? "Sign in"}
              </Title>
            </div>

            {loginError && Object.keys(loginFieldErrors).length === 0 && (
              <Alert color="red" variant="light" icon={<IconAlertCircle size={18} />}>
                {loginError}
              </Alert>
            )}

            <TextInput
              label="Email or phone number"
              placeholder="you@shop.co.ke or 0712 345 678"
              autoComplete="username"
              size="md"
              required
              withAsterisk={false}
              key={form.key("login")}
              {...form.getInputProps("login")}
            />

            <PasswordInput
              label={
                <Group justify="space-between" w="100%" component="span">
                  <span>Password</span>
                  <Anchor component="button" type="button" size="sm" fw={600} onClick={forgot.open}>
                    Forgot password?
                  </Anchor>
                </Group>
              }
              labelProps={{ style: { width: "100%" } }}
              autoComplete="current-password"
              size="md"
              required
              withAsterisk={false}
              key={form.key("password")}
              {...form.getInputProps("password")}
            />

            <Checkbox label="Keep me signed in on this computer" key={form.key("remember")} {...form.getInputProps("remember", { type: "checkbox" })} />

            <Button type="submit" size="md" fullWidth loading={submitting}>
              Sign in
            </Button>

            <Divider label="or" labelPosition="center" />

            <Button component={Link} href="/till" variant="default" size="md" fullWidth leftSection={<IconCalculator size={18} />}>
              Cashier? Sign in with your PIN
            </Button>

            <Text size="xs" c="dimmed" ta="center">
              {poweredBy.text} · Need help? Contact your shop administrator.
            </Text>
          </Stack>
        </form>
      </section>

      <Modal opened={forgotOpened} onClose={forgot.close} title="Forgot your password?" centered>
        <Stack>
          <Text size="sm">For security, passwords are reset by your shop owner or administrator. Ask them to set a new one for you.</Text>
          <Button onClick={forgot.close}>OK</Button>
        </Stack>
      </Modal>
    </div>
  );
}
