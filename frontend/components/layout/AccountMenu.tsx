"use client";

import { Avatar, Group, Menu, Text, UnstyledButton } from "@mantine/core";
import { IconBuildingStore, IconCalculator, IconChevronDown, IconKey, IconLogout, IconSettings, IconShieldLock } from "@tabler/icons-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { brand } from "@/app/theme";
import ChangePasswordModal from "@/components/auth/ChangePasswordModal";
import TwoStepModal from "@/components/auth/TwoStepModal";
import { usePermissions } from "@/hooks/usePermissions";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { logout } from "@/store/slices/authSlice";
import { openTab, resetTabs } from "@/store/slices/tabsSlice";
import { PERMISSIONS } from "@/types/permissions";

/** The signed-in person (top right of the workspace bar): profile, business details, settings, till, password, two-step login, sign out. */
export default function AccountMenu() {
  const dispatch = useAppDispatch();
  const router = useRouter();
  const user = useAppSelector((state) => state.auth.user);
  const { can } = usePermissions();
  const [dialog, setDialog] = useState<"password" | "twoStep" | null>(null);

  if (!user) return null;

  const handleLogout = async () => {
    await dispatch(logout());
    dispatch(resetTabs());
    router.replace("/login");
  };

  const initials = user.name
    .split(" ")
    .map((part) => part[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();

  return (
    <>
      <Menu position="bottom-end" width={260} withinPortal>
        <Menu.Target>
          <UnstyledButton aria-label="Account menu">
            <Group gap="xs" wrap="nowrap">
              <Avatar size="sm" radius="xl" styles={{ placeholder: { background: brand.amber, color: brand.navy, fontWeight: 700 } }}>
                {initials}
              </Avatar>
              <div>
                <Text size="sm" fw={600} lh={1.2} visibleFrom="xs">
                  {user.name}
                </Text>
                <Text size="xs" lh={1.2} c="dimmed" visibleFrom="xs">
                  {user.roles.join(", ") || "No role"}
                </Text>
              </div>
              <IconChevronDown size={14} color="var(--mantine-color-dimmed)" />
            </Group>
          </UnstyledButton>
        </Menu.Target>
        <Menu.Dropdown>
          {/* Profile: who is signed in, and with which role. */}
          <Group gap="sm" px="sm" py="xs" wrap="nowrap">
            <Avatar radius="xl" styles={{ placeholder: { background: brand.amber, color: brand.navy, fontWeight: 700 } }}>
              {initials}
            </Avatar>
            <div style={{ minWidth: 0 }}>
              <Text size="sm" fw={700} truncate>
                {user.name}
              </Text>
              <Text size="xs" c="dimmed" truncate>
                {user.email}
              </Text>
              <Text size="xs" c="dimmed" truncate>
                {user.roles.join(", ") || "No role"}
              </Text>
            </div>
          </Group>
          {(can(PERMISSIONS.ORGANISATION_MANAGE) || can(PERMISSIONS.SETTINGS_BRANCH)) && (
            <>
              <Menu.Divider />
              <Menu.Label>Organisation</Menu.Label>
            </>
          )}
          {can(PERMISSIONS.ORGANISATION_MANAGE) && (
            <Menu.Item leftSection={<IconBuildingStore size={16} />} onClick={() => dispatch(openTab({ title: "Branches", path: "/admin/branches", view: "branchesList" }))}>
              Business &amp; branches
            </Menu.Item>
          )}
          {can(PERMISSIONS.SETTINGS_BRANCH) && (
            <Menu.Item leftSection={<IconSettings size={16} />} onClick={() => dispatch(openTab({ title: "Settings", path: "/admin/settings", view: "settings" }))}>
              Settings
            </Menu.Item>
          )}
          <Menu.Divider />
          <Menu.Label>My account</Menu.Label>
          <Menu.Item component={Link} href="/till" leftSection={<IconCalculator size={16} />}>
            Open till screen
          </Menu.Item>
          <Menu.Item leftSection={<IconKey size={16} />} onClick={() => setDialog("password")}>
            Change password
          </Menu.Item>
          <Menu.Item leftSection={<IconShieldLock size={16} />} onClick={() => setDialog("twoStep")}>
            Two-step login{user.mfaEnabled ? " · on" : ""}
          </Menu.Item>
          <Menu.Divider />
          <Menu.Item color="red" leftSection={<IconLogout size={16} />} onClick={handleLogout}>
            Sign out
          </Menu.Item>
        </Menu.Dropdown>
      </Menu>
      {/* Set by an admin or expired (Settings → Staff): nothing else works until it is changed. */}
      {user.mustChangePassword ? (
        <ChangePasswordModal forced onClose={() => undefined} />
      ) : (
        dialog === "password" && <ChangePasswordModal onClose={() => setDialog(null)} />
      )}
      {dialog === "twoStep" && <TwoStepModal onClose={() => setDialog(null)} />}
    </>
  );
}
