/** Kept free of any Playwright import so `playwright.config.ts` can read it without loading fixtures. */
export const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:4242';
