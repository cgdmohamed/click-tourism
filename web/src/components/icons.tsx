import type { SVGProps } from 'react'

const cx = (...values: Array<string | false | null | undefined>) => values.filter(Boolean).join(' ')

/**
 * Shared stroke-icon set.
 *
 * Icons are inline SVG paths on a 24×24 grid so they inherit `currentColor`,
 * stay crisp at any size, need no extra dependency and never load over the
 * network. Directional icons (arrows/chevrons) are mirrored automatically in
 * RTL by the `directional` class.
 */
const shapes = {
  /* Navigation and modules */
  dashboard: ['M4 4h6v7H4V4Zm10 0h6v4h-6V4ZM4 15h6v5H4v-5Zm10-3h6v8h-6v-8Z'],
  ledger: ['M12 7v14', 'M3 18a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3Z'],
  scale: ['M12 3v18', 'M7 21h10', 'M5 7h14', 'M8 7l-3 6a3 3 0 0 0 6 0L8 7Z', 'M16 7l-3 6a3 3 0 0 0 6 0l-3-6Z'],
  wallet: ['M3 7a2 2 0 0 1 2-2h13a1 1 0 0 1 1 1v3', 'M3 7v10a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-3', 'M21 12h-4a2 2 0 0 0 0 4h4v-4Z'],
  receipt: ['M5 3v18l2-1.5L9 21l2-1.5L13 21l2-1.5L17 21l2-1.5V3l-2 1.5L15 3l-2 1.5L11 3 9 4.5 7 3 5 3Z', 'M9 8h6M9 12h6M9 16h4'],
  landmark: ['M3 21h18', 'M5 21V10M9 21V10M15 21V10M19 21V10', 'M12 3 3 8h18l-9-5Z'],
  calendar: ['M8 2v4M16 2v4', 'M3 9h18', 'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z'],
  calendarClock: ['M8 2v4M16 2v4', 'M3 9h18', 'M5 4h14a2 2 0 0 1 2 2v5', 'M3 6v14a2 2 0 0 0 2 2h8', 'M16 15a4 4 0 1 0 8 0 4 4 0 0 0-8 0Z', 'M20 13v2l1.5 1.5'],
  fileText: ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z', 'M14 2v6h6', 'M8 13h8M8 17h5'],
  clipboardCheck: ['M9 5H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2', 'M9 5a3 3 0 0 1 6 0M9 5a3 3 0 0 0 6 0', 'm9 14 2 2 4-4'],
  listChecks: ['M3 6h2m0 0L4 7.5M3 12h2m0 0-1 1.5M3 18h2m0 0-1 1.5', 'M10 6h11M10 12h11M10 18h11'],
  package: ['m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z', 'M12 3v9', 'm20 7.5-8 4.5-8-4.5'],
  users: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M22 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
  truck: ['M1 6h11v11H1V6Z', 'M12 10h4l4 4v3h-8v-7Z', 'M4 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM16 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'],
  plane: ['M17.8 19.2 16 11l3.5-3.5a2.1 2.1 0 0 0-3-3L13 8 4.8 6.2a1 1 0 0 0-.9 1.7l5 3.4-2 2-2.5-.6a1 1 0 0 0-1 1.6l3 3 3 3a1 1 0 0 0 1.6-1l-.6-2.5 2-2 3.4 5a1 1 0 0 0 1.7-.9Z'],
  creditCard: ['M2 6h20v12H2V6Z', 'M2 10h20', 'M6 15h3'],
  handshake: ['M3 10l4-4 5 5 2-2 5 5-4 4-3-3', 'm11 17 2 2a1 1 0 1 0 3-3l-3-3', 'm7 16 2 2a1 1 0 0 0 3-3', 'M3 10l2 2'],
  chart: ['M4 19V5m0 14h16', 'M8 15V9m4 6V6m4 9v-4'],
  target: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z', 'M12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z'],
  settings: ['M12 15.5A3.5 3.5 0 1 0 12 8.5a3.5 3.5 0 0 0 0 7Z', 'M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z'],
  compass: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'm15 9-2 5-5 2 2-5 5-2Z'],

  /* Actions */
  search: ['M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z', 'm21 21-4.3-4.3'],
  plus: ['M12 5v14M5 12h14'],
  x: ['M6 6l12 12M18 6 6 18'],
  menu: ['M4 6h16M4 12h16M4 18h16'],
  chevronDown: ['m6 9 6 6 6-6'],
  chevronLeft: ['m15 18-6-6 6-6'],
  chevronRight: ['m9 18 6-6-6-6'],
  arrowRight: ['M5 12h14m-6-6 6 6-6 6'],
  arrowLeftRight: ['M3 7h18m-4-4 4 4-4 4M21 17H3m4-4-4 4 4 4'],
  download: ['M12 3v12m0 0 4-4m-4 4-4-4', 'M4 21h16'],
  upload: ['M12 21V9m0 0 4 4m-4-4-4 4', 'M4 3h16'],
  printer: ['M6 9V3h12v6', 'M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2', 'M6 14h12v7H6v-7Z'],
  refresh: ['M21 12a9 9 0 0 1-15.4 6.4M3 12a9 9 0 0 1 15.4-6.4', 'M3 4v5h5M21 20v-5h-5'],
  save: ['M5 3h11l5 5v13H3V3h2Z', 'M7 3v6h8V3', 'M7 21v-8h10v8'],
  trash: ['M3 6h18', 'M8 6V4h8v2', 'm9 6 1 15h8l1-15', 'M10 10v7M14 10v7'],
  edit: ['M4 20h4L20 8l-4-4L4 16v4Z', 'm13 5 4 4'],
  eye: ['M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
  eyeOff: ['m3 3 18 18', 'M10.6 10.6a2 2 0 0 0 2.8 2.8', 'M9.9 4.2A10.4 10.4 0 0 1 12 4c6.5 0 10 7 10 7a17 17 0 0 1-3 3.9', 'M6.1 6.1A17 17 0 0 0 2 11s3.5 7 10 7a10 10 0 0 0 4-.8'],
  filter: ['M3 5h18l-7 8v6l-4-2v-4L3 5Z'],
  logOut: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'm16 17 5-5-5-5', 'M21 12H9'],
  logIn: ['M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4', 'm10 17 5-5-5-5', 'M15 12H3'],
  globe: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M12 21c2.5-2.4 4-5.6 4-9s-1.5-6.6-4-9c-2.5 2.4-4 5.6-4 9s1.5 6.6 4 9Z', 'M3 12h18'],
  building: ['M4 21V3h10v18', 'M14 9h6v12', 'M2 21h20', 'M7 7h2M7 11h2M7 15h2M17 13h1M17 17h1'],
  link: ['M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1', 'M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1'],
  externalLink: ['M14 3h7v7m0-7L10 14', 'M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5'],
  copy: ['M8 8h12v12H8V8Z', 'M4 16V4h12'],
  send: ['m22 2-7 20-4-9-9-4 20-7Z'],
  play: ['m8 5 11 7-11 7V5Z'],
  check: ['m5 12 5 5L20 7'],
  more: ['M6 12h.01M12 12h.01M18 12h.01'],

  /* Status and feedback */
  checkCircle: ['M22 11.1V12a10 10 0 1 1-5.9-9.1', 'M22 4 12 14l-3-3'],
  xCircle: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'm9 15 6-6m0 6-6-6'],
  alertTriangle: ['M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z', 'M12 9v4', 'M12 17h.01'],
  info: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M12 11v5', 'M12 8h.01'],
  clock: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M12 7v5l3 2'],
  lock: ['M6 11h12v10H6V11Z', 'M8 11V7a4 4 0 0 1 8 0v4'],
  unlock: ['M6 11h12v10H6V11Z', 'M8 11V7a4 4 0 0 1 7.5-2'],
  inbox: ['M3 12h5l2 3h4l2-3h5', 'M5 5h14l2 7v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5l2-7Z'],
  folder: ['M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z'],
  coins: ['M9 14a6 6 0 1 0 0-12 6 6 0 0 0 0 12Z', 'M15 12a6 6 0 1 0-6 6', 'M9 8h.01M9 11h.01'],
  trendUp: ['m3 17 6-6 4 4 8-8', 'M16 7h5v5'],
  trendDown: ['m3 7 6 6 4-4 8 8', 'M16 17h5v-5'],
  shield: ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z'],
  mail: ['M2 5h20v14H2V5Z', 'm2 6 10 7 10-7'],
  tag: ['M3 3h8l10 10-8 8L3 11V3Z', 'M7 7h.01'],
  percent: ['M19 5 5 19', 'M6.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z', 'M17.5 20a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z'],
} as const satisfies Record<string, readonly string[]>

export type IconName = keyof typeof shapes

export type IconProps = Omit<SVGProps<SVGSVGElement>, 'name'> & {
  name: IconName
  /** Pixel size for both width and height. Defaults to 20. */
  size?: number
  /** Accessible label; omit for purely decorative icons. */
  title?: string
  /** Mirror the icon horizontally in RTL layouts. */
  directional?: boolean
}

export function Icon({ name, size = 20, title, directional, className, strokeWidth = 1.8, ...props }: IconProps) {
  return (
    <svg
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={cx('shrink-0', directional && 'rtl:-scale-x-100', className)}
      aria-hidden={title ? undefined : true}
      role={title ? 'img' : undefined}
      {...props}
    >
      {title ? <title>{title}</title> : null}
      {shapes[name].map((path) => (
        <path key={path} d={path} />
      ))}
    </svg>
  )
}
