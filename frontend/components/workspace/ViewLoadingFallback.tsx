"use client";

import { Center, Loader } from "@mantine/core";

export default function ViewLoadingFallback() {
  return (
    <Center py="xl">
      <Loader />
    </Center>
  );
}
