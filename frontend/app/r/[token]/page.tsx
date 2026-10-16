import type { Metadata } from "next";
import PublicReceipt from "@/modules/sales/components/PublicReceipt";

/** Receipt links are private to whoever holds them: never indexed, never sent on as a referrer. */
export const metadata: Metadata = {
  title: "Your receipt",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

/** A receipt shared by link (WhatsApp, SMS). Outside the back office; no sign-in. */
export default async function SharedReceiptPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return <PublicReceipt token={token} />;
}
