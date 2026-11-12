import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  test: {
    // Pure modules (money, tax, formatting) run without a DOM. If a component
    // test is added later, opt in per file with `// @vitest-environment jsdom`.
    environment: 'node',
    include: ['src/**/*.test.{ts,tsx}'],
    // GAP-QA-03: coverage is only gated on the pure logic modules in src/lib
    // (money, tax, allocation, permissions). Pages and components are not
    // unit-tested yet (GAP-QA-05), so including them would make the number
    // meaningless. Thresholds sit just below the current value (stmts 51.7,
    // branches 55.1, funcs 32.3, lines 49.2) and should only ever be raised.
    coverage: {
      provider: 'v8',
      include: ['src/lib/**/*.ts'],
      exclude: ['src/**/*.test.{ts,tsx}'],
      reporter: ['text-summary', 'lcov'],
      thresholds: { statements: 45, branches: 48, functions: 28, lines: 43 },
    },
  },
})
