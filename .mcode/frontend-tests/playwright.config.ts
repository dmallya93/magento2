import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: ".",
  timeout: 90000,
  retries: 1,
  workers: 1,
  use: {
    baseURL: process.env.BASE_URL || `http://127.0.0.1:${process.env.MAGENTO_PORT || 8082}`,
    headless: true,
    launchOptions: { ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), args: ["--no-sandbox"] },
    screenshot: "on",
    video: "off",
    trace: "retain-on-failure",
  },
  reporter: [["list"], ["json", { outputFile: `${process.env.MCODE_DIR}/fe_testing/playwright-results.json` }]],
});
