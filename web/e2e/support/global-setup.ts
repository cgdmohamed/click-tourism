import type { FullConfig } from '@playwright/test'
import { globalSignIn } from './session'

export default async function globalSetup(config: FullConfig): Promise<void> {
  // The API is proxied by Vite, so the same origin serves the app and /api/v1.
  await globalSignIn(config.projects[0].use.baseURL as string)
}
