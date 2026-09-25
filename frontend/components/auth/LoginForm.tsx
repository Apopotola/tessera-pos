"use client";

import { Alert, Button, Center, Paper, PasswordInput, Stack, Text, TextInput, ThemeIcon, Title } from "@mantine/core";
import { isEmail, isNotEmpty, useForm } from "@mantine/form";
import { IconAlertCircle, IconBottle } from "@tabler/icons-react";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { login } from "@/store/slices/authSlice";
import type { LoginPayload } from "@/types/auth";
import { safeRedirectPath } from "@/utils/menu";

export default function LoginForm() {
  const dispatch = useAppDispatch();
  const router = useRouter();
  const searchParams = useSearchParams();
  const { status, loginError, loginFieldErrors } = useAppSelector((state) => state.auth);
  const [submitting, setSubmitting] = useState(false);
  const next = safeRedirectPath(searchParams.get("next"));

  const form = useForm<LoginPayload>({
    mode: "uncontrolled",
    initialValues: { email: "", password: "" },
    validate: {
      email: isEmail("Enter a valid email address"),
      password: isNotEmpty("Enter your password"),
    },
  });

  useEffect(() => {
    if (status === "authenticated") router.replace(next);
  }, [status, next, router]);

  const handleSubmit = async (values: LoginPayload) => {
    if (submitting) return;
    setSubmitting(true);
    const result = await dispatch(login(values));
    if (login.rejected.match(result)) {
      form.setErrors(result.payload?.fieldErrors ?? {});
      setSubmitting(false);
    }
  };

  return (
    <Center mih="100vh" bg="var(--mantine-color-gray-0)" p="md">
      <Paper withBorder shadow="sm" p="xl" w="100%" maw={400}>
        <form onSubmit={form.onSubmit(handleSubmit)} noValidate>
          <Stack gap="md">
            <Stack gap={4} align="center">
              <ThemeIcon size={48} radius="xl">
                <IconBottle size={26} />
              </ThemeIcon>
              <Title order={2}>Tessera POS</Title>
              <Text c="dimmed" size="sm">
                Sign in to continue
              </Text>
            </Stack>

            {loginError && Object.keys(loginFieldErrors).length === 0 && (
              <Alert color="red" variant="light" icon={<IconAlertCircle size={18} />}>
                {loginError}
              </Alert>
            )}

            <TextInput
              label="Email"
              type="email"
              autoComplete="username"
              required
              key={form.key("email")}
              {...form.getInputProps("email")}
            />
            <PasswordInput
              label="Password"
              autoComplete="current-password"
              required
              key={form.key("password")}
              {...form.getInputProps("password")}
            />
            <Button type="submit" fullWidth loading={submitting}>
              Sign in
            </Button>
          </Stack>
        </form>
      </Paper>
    </Center>
  );
}
