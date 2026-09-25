"use client";

import { Avatar, Burger, Group, Menu, Text, ThemeIcon, UnstyledButton } from "@mantine/core";
import { IconBottle, IconChevronDown, IconLogout } from "@tabler/icons-react";
import { useRouter } from "next/navigation";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { logout } from "@/store/slices/authSlice";
import { resetTabs } from "@/store/slices/tabsSlice";

interface HeaderProps {
  navOpened: boolean;
  onToggleNav: () => void;
}

export default function Header({ navOpened, onToggleNav }: HeaderProps) {
  const dispatch = useAppDispatch();
  const router = useRouter();
  const user = useAppSelector((state) => state.auth.user);

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
    <Group h="100%" px="md" justify="space-between" wrap="nowrap">
      <Group gap="sm" wrap="nowrap">
        <Burger opened={navOpened} onClick={onToggleNav} hiddenFrom="sm" size="sm" aria-label="Toggle navigation" />
        <ThemeIcon size="lg" radius="md" variant="filled">
          <IconBottle size={20} />
        </ThemeIcon>
        <Text fw={700} size="lg">
          Tessera POS
        </Text>
      </Group>

      {user && (
        <Menu position="bottom-end" withinPortal>
          <Menu.Target>
            <UnstyledButton aria-label="Account menu">
              <Group gap="xs" wrap="nowrap">
                <Avatar size="sm" radius="xl" color="wine">
                  {initials}
                </Avatar>
                <div>
                  <Text size="sm" fw={500} lh={1.2}>
                    {user.name}
                  </Text>
                  <Text size="xs" c="dimmed" lh={1.2}>
                    {user.roles.join(", ") || "No role"}
                  </Text>
                </div>
                <IconChevronDown size={14} />
              </Group>
            </UnstyledButton>
          </Menu.Target>
          <Menu.Dropdown>
            <Menu.Label>{user.email}</Menu.Label>
            <Menu.Item leftSection={<IconLogout size={16} />} onClick={handleLogout}>
              Sign out
            </Menu.Item>
          </Menu.Dropdown>
        </Menu>
      )}
    </Group>
  );
}
