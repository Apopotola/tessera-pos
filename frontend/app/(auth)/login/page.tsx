import { Suspense } from "react";
import LoginForm from "@/components/auth/LoginForm";

export default function LoginPage() {
  // useSearchParams (for ?next=) requires a Suspense boundary.
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}
