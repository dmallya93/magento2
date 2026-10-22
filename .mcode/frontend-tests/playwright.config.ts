import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: ".",
  timeout: 90000,
  retries: 1,
  workers: 1,
  use: {
    baseURL: "http://127.0.0.1:8082",
    headless: true,
    launchOptions: { executablePath: process.env.CHROME_PATH || "/l2l/.agent-browser/browsers/chrome-156.0.8078.47/chrome", args: ["--no-sandbox"] },
    screenshot: "on",
    video: "off",
    trace: "retain-on-failure",
  },
  reporter: [["list"], ["json", { outputFile: `${process.env.MCODE_DIR}/fe_testing/playwright-results.json` }]],
});
