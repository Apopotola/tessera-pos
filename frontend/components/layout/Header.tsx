"use client";

import { Avatar, Burger, Group, Menu, Text, UnstyledButton } from "@mantine/core";
import { IconCalculator, IconChevronDown, IconKey, IconLogout, IconShieldLock } from "@tabler/icons-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { brand } from "@/app/theme";
import ChangePasswordModal from "@/components/auth/ChangePasswordModal";
import TwoStepModal from "@/components/auth/TwoStepModal";
import Logo from "@/components/brand/Logo";
import NotificationBell from "@/components/layout/NotificationBell";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { logout } from "@/store/slices/authSlice";
import { resetTabs } from "@/store/slices/tabsSlice";

interface HeaderProps {
  navOpened: boolean;
  onToggleNav: () => void;
}

/** Navy brand bar: logo, and the account menu. */
export default function Header({ navOpened, onToggleNav }: HeaderProps) {
  const dispatch = useAppDispatch();
  const router = useRouter();
  const user = useAppSelector((state) => state.auth.user);
  const [dialog, setDialog] = useState<"password" | "twoStep" | null>(null);

  const handleLogout = async () => {
    await dispatch(logout());
    dispatch(resetTabs());
    router.replace("/login");
  };

  const initials = (user?.name ?? "?")
    .split(" ")
    .map((part) => part[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();

  return (
    <Group h="100%" px="md" justify="space-between" wrap="nowrap" style={{ background: brand.navy }}>
      <Group gap="sm" wrap="nowrap">
        <Burger opened={navOpened} onClick={onToggleNav} hiddenFrom="sm" size="sm" color="white" aria-label="Toggle navigation" />
        <Logo size={24} />
      </Group>

      {user && (
        <Group gap="md" wrap="nowrap">
          <NotificationBell />
          <Menu position="bottom-end" withinPortal>
            <Menu.Target>
              <UnstyledButton aria-label="Account menu">
                <Group gap="xs" wrap="nowrap">
                  <Avatar size="sm" radius="xl" styles={{ placeholder: { background: brand.amber, color: brand.navy, fontWeight: 700 } }}>
                    {initials}
                  </Avatar>
                  <div>
                    <Text size="sm" fw={600} lh={1.2} c="white">
                      {user.name}
                    </Text>
                    <Text size="xs" lh={1.2} c="gray.5">
                      {user.roles.join(", ") || "No role"}
                    </Text>
                  </div>
                  <IconChevronDown size={14} color="white" />
                </Group>
              </UnstyledButton>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Label>{user.email}</Menu.Label>
              <Menu.Item component={Link} href="/till" leftSection={<IconCalculator size={16} />}>
                Open till screen
              </Menu.Item>
              <Menu.Item leftSection={<IconKey size={16} />} onClick={() => setDialog("password")}>
                Change password
              </Menu.Item>
              <Menu.Item leftSection={<IconShieldLock size={16} />} onClick={() => setDialog("twoStep")}>
                Two-step login{user.mfaEnabled ? " · on" : ""}
              </Menu.Item>
              <Menu.Item leftSection={<IconLogout size={16} />} onClick={handleLogout}>
                Sign out
              </Menu.Item>
            </Menu.Dropdown>
          </Menu>
        </Group>
      )}
      {/* Set by an admin or expired (Settings → Staff): nothing else works until it is changed. */}
      {user?.mustChangePassword ? (
        <ChangePasswordModal forced onClose={() => undefined} />
      ) : (
        dialog === "password" && <ChangePasswordModal onClose={() => setDialog(null)} />
      )}
      {dialog === "twoStep" && <TwoStepModal onClose={() => setDialog(null)} />}
    </Group>
  );
}
