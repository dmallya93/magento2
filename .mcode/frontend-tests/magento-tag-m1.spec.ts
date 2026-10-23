import { test, expect } from "@playwright/test";

const ADMIN_USER = process.env.ADMIN_USER || "admin";
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.ADMIN_PASS ?? "";

test.describe("Storefront core pages with Magento_Tag enabled", () => {
  test("home page renders", async ({ page }) => {
    const resp = await page.goto("/");
    expect(resp!.status()).toBe(200);
    await expect(page).toHaveTitle("Home page");
    await expect(page.locator("h1")).toContainText("Home Page");
    await expect(page.getByText("CMS homepage content goes here.")).toBeVisible();
    await expect(page.locator('input[placeholder="Search entire store here..."]')).toBeVisible();
    await expect(page.getByRole("link", { name: "Create an Account" })).toBeVisible();
  });

  test("customer login renders", async ({ page }) => {
    const resp = await page.goto("/customer/account/login");
    expect(resp!.status()).toBe(200);
    await expect(page).toHaveTitle("Customer Login");
    await expect(page.locator("#email")).toBeVisible();
  });

  test("customer create account renders", async ({ page }) => {
    const resp = await page.goto("/customer/account/create");
    expect(resp!.status()).toBe(200);
    await expect(page).toHaveTitle("Create New Customer Account");
    await expect(page.locator("#firstname")).toBeVisible();
  });

  test("cart page renders", async ({ page }) => {
    const resp = await page.goto("/checkout/cart");
    expect(resp!.status()).toBe(200);
    await expect(page.locator("body")).not.toContainText("Fatal error");
  });

  test("search results page renders", async ({ page }) => {
    const resp = await page.goto("/catalogsearch/result/?q=a");
    expect(resp!.status()).toBe(200);
    await expect(page.locator("body")).not.toContainText("Exception");
  });

  test("tag frontend route is not available (404)", async ({ page }) => {
    const resp = await page.goto("/tag/list/index");
    expect(resp!.status()).toBe(404);
    await expect(page).toHaveTitle(/404 Not Found/);
  });
});

test.describe("Admin with Magento_Tag enabled", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/admin/");
    await page.locator("#username").fill(ADMIN_USER);
    await page.locator("#login").fill(ADMIN_PASS);
    await page.locator(".action-login").click();
    await expect(page.locator("#menu-magento-catalog-catalog")).toBeVisible({ timeout: 60000 });
  });

  test("login lands on dashboard with Catalog and Reports menus", async ({ page }) => {
    await expect(page).toHaveTitle(/Dashboard/);
    await expect(page.locator("#menu-magento-catalog-catalog")).toBeVisible();
    await expect(page.locator("#menu-magento-reports-report")).toBeVisible();
  });

  test("dashboard renders", async ({ page }) => {
    await page.goto("/admin/admin/dashboard/");
    await expect(page).toHaveTitle("Dashboard / Magento Admin");
  });

  test("products grid renders", async ({ page }) => {
    await page.goto("/admin/catalog/product/");
    await expect(page).toHaveTitle(/Products \/ Inventory \/ Catalog \/ Magento Admin/);
    await expect(page.getByText("We couldn't find any records.")).toBeVisible({ timeout: 60000 });
    await expect(page.getByRole("button", { name: "Add Product", exact: true })).toBeVisible();
  });

  test("customers grid renders", async ({ page }) => {
    await page.goto("/admin/customer/index/");
    await expect(page).toHaveTitle("Customers / Customers / Magento Admin");
    await expect(page.getByRole("button", { name: "Add New Customer" })).toBeVisible({ timeout: 60000 });
  });

  test("stores configuration renders", async ({ page }) => {
    await page.goto("/admin/admin/system_config/edit/section/admin/");
    await expect(page).toHaveTitle("Configuration / Settings / Stores / Magento Admin");
  });
});
