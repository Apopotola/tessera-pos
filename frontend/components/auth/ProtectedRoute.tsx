"use client";

import { Center, Loader } from "@mantine/core";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { useAppSelector } from "@/store/hooks";

/** Renders children only for an authenticated session; otherwise sends the user to /login. */
export default function ProtectedRoute({ children }: { children: ReactNode }) {
  const status = useAppSelector((state) => state.auth.status);
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (status === "unauthenticated") {
      router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    }
  }, [status, router, pathname]);

  if (status !== "authenticated") {
    return (
      <Center h="100vh">
        <Loader />
      </Center>
    );
  }

  return <>{children}</>;
}
