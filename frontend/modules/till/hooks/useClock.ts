import { useEffect, useState } from "react";

/** Current time, refreshed every 15 seconds (enough for an hh:mm clock). */
export function useClock(): Date {
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 15_000);
    return () => window.clearInterval(id);
  }, []);

  return now;
}

export function formatClock(date: Date): { time: string; day: string } {
  return {
    time: date.toLocaleTimeString("en-KE", { hour: "numeric", minute: "2-digit", hour12: true }).toLowerCase(),
    day: date.toLocaleDateString("en-KE", { weekday: "long", day: "numeric", month: "long" }),
  };
}
