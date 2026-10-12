import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /**
   * The dev server refuses to serve HMR chunks, JS and CSS to an origin other
   * than localhost. Reaching the console from another device on the LAN means
   * every asset is blocked, the page renders as unstyled server HTML, React
   * never hydrates, and the login form falls back to a native GET submit.
   *
   * Development only — production builds serve static assets normally.
   */
  allowedDevOrigins: ["10.155.71.54", "localhost", "127.0.0.1"],
};

export default nextConfig;
