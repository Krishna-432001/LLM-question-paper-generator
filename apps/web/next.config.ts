import { networkInterfaces } from "node:os";
import type { NextConfig } from "next";

/**
 * This machine's own IPv4 addresses, read when the dev server starts. DHCP
 * moves the LAN address, and a hard-coded IP silently stops working the day
 * it changes; restarting `npm run dev` picks up the new one.
 */
function localAddresses(): string[] {
  return Object.values(networkInterfaces())
    .flat()
    .filter((i) => i && i.family === "IPv4" && !i.internal)
    .map((i) => i!.address);
}

const nextConfig: NextConfig = {
  /**
   * The dev server refuses to serve HMR chunks, JS and CSS to an origin other
   * than localhost. Reaching the console from another device on the LAN means
   * every asset is blocked, the page renders as unstyled server HTML, React
   * never hydrates, and the login form falls back to a native GET submit.
   *
   * Development only — production builds serve static assets normally.
   */
  allowedDevOrigins: ["localhost", "127.0.0.1", ...localAddresses()],
};

export default nextConfig;
