import { test, expect } from '@playwright/test'

import { FakeApi } from './support/fakeApi'

test('sends a signed-out visitor to Google sign-in', async ({ page }) => {
  await page.goto('/')

  await expect(page).toHaveURL(/\/login$/)
  await expect(page.locator('h1')).toHaveText('Sign in')
  await expect(page.getByRole('link', { name: 'Sign in with Google' })).toHaveAttribute(
    'href',
    /\/auth\/google\/redirect$/,
  )
})

test('shows the upload page to a signed-in user', async ({ page }) => {
  await new FakeApi(page).install()

  await page.goto('/')

  await expect(page.locator('h1')).toHaveText('Upload media')
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
})

test('signs in from the Google callback and forgets the token in the address bar', async ({ page }) => {
  await page.route(/\/api\/user$/, (route) =>
    route.fulfill({ json: { data: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', avatar: null } } }),
  )
  await page.route(/\/api\/uploads$/, (route) => route.fulfill({ json: { data: [] } }))

  await page.goto('/auth/callback#token=1%7Cabc')

  await expect(page.locator('h1')).toHaveText('Upload media')
  await expect(page).toHaveURL(/\/$/)
  expect(await page.evaluate(() => localStorage.getItem('auth_token'))).toBe('1|abc')
})
