import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'

export type Theme = 'light' | 'dark'

/**
 * Explicit theme support.
 *
 * The design tokens are authored as light values with a matching `-dark`
 * palette. `index.css` remaps the semantic tokens when `<html data-theme>`
 * is `light` or `dark`, so the mode is never inferred from the DOM at random.
 * When the user has not chosen a theme we still follow the operating system
 * and keep following it live until they choose one explicitly.
 */
const THEME_KEY = 'tourism_erp_theme'
const DARK_QUERY = '(prefers-color-scheme: dark)'

export function getStoredTheme(): Theme | null {
  try {
    const value = localStorage.getItem(THEME_KEY)
    return value === 'light' || value === 'dark' ? value : null
  } catch {
    return null
  }
}

export function systemTheme(): Theme {
  if (typeof window === 'undefined') return 'light'
  return window.matchMedia(DARK_QUERY).matches ? 'dark' : 'light'
}

export function resolveTheme(): Theme {
  return getStoredTheme() ?? systemTheme()
}

export function applyTheme(theme: Theme): void {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  root.dataset.theme = theme
  root.style.colorScheme = theme
}

/** Apply the stored/system theme before React paints the first frame. */
export function bootTheme(): Theme {
  const theme = resolveTheme()
  applyTheme(theme)
  return theme
}

type ThemeContextValue = {
  theme: Theme
  setTheme: (theme: Theme) => void
  toggleTheme: () => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

function initialTheme(): Theme {
  if (typeof document !== 'undefined') {
    const attribute = document.documentElement.dataset.theme
    if (attribute === 'light' || attribute === 'dark') return attribute
  }
  return resolveTheme()
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<Theme>(initialTheme)

  useEffect(() => {
    applyTheme(theme)
  }, [theme])

  useEffect(() => {
    const media = window.matchMedia(DARK_QUERY)
    const onChange = (event: MediaQueryListEvent) => {
      // An explicit user choice wins over the operating-system preference.
      if (!getStoredTheme()) setThemeState(event.matches ? 'dark' : 'light')
    }
    media.addEventListener('change', onChange)
    return () => media.removeEventListener('change', onChange)
  }, [])

  const setTheme = useCallback((next: Theme) => {
    try {
      localStorage.setItem(THEME_KEY, next)
    } catch {
      // Storage can be blocked in private modes; the in-memory theme still applies.
    }
    setThemeState(next)
  }, [])

  const value = useMemo<ThemeContextValue>(
    () => ({
      theme,
      setTheme,
      toggleTheme: () => setTheme(theme === 'dark' ? 'light' : 'dark'),
    }),
    [theme, setTheme],
  )

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext)
  if (!context) throw new Error('useTheme must be used inside ThemeProvider')
  return context
}
