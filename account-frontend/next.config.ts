import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  // Dev only: the local stack is served on *.re.localsite (hosts file), not localhost.
  allowedDevOrigins: ["*.re.localsite"],
};

export default nextConfig;
