/**
 * Common ISO 4217 currencies offered by the "New currency" picker (Settings > Currencies).
 *
 * An ISO code cannot be generated, so this is the one master-data code that is picked rather than
 * auto-numbered. Picking an entry prefills code, name, symbol and decimals; "Other" keeps manual entry.
 *
 * Keep one entry per line (backend/tests/Feature/AutoCodesTest.php parses this file) and keep the
 * exponent of the codes built into backend/app/Support/Money.php (EGP SAR USD EUR GBP AED = 2,
 * KWD BHD OMR JOD = 3) equal to Money::builtinExponent, which ignores the stored value.
 */
export type CatalogueCurrency = { code: string; name: string; name_ar: string; symbol: string; exponent: number }

export const CURRENCY_CATALOGUE: readonly CatalogueCurrency[] = [
  // Arab region
  { code: 'EGP', name: 'Egyptian Pound', name_ar: 'جنيه مصري', symbol: 'E£', exponent: 2 },
  { code: 'SAR', name: 'Saudi Riyal', name_ar: 'ريال سعودي', symbol: 'ر.س', exponent: 2 },
  { code: 'AED', name: 'UAE Dirham', name_ar: 'درهم إماراتي', symbol: 'د.إ', exponent: 2 },
  { code: 'KWD', name: 'Kuwaiti Dinar', name_ar: 'دينار كويتي', symbol: 'د.ك', exponent: 3 },
  { code: 'BHD', name: 'Bahraini Dinar', name_ar: 'دينار بحريني', symbol: 'د.ب', exponent: 3 },
  { code: 'OMR', name: 'Omani Rial', name_ar: 'ريال عماني', symbol: 'ر.ع', exponent: 3 },
  { code: 'QAR', name: 'Qatari Riyal', name_ar: 'ريال قطري', symbol: 'ر.ق', exponent: 2 },
  { code: 'JOD', name: 'Jordanian Dinar', name_ar: 'دينار أردني', symbol: 'د.أ', exponent: 3 },
  { code: 'LBP', name: 'Lebanese Pound', name_ar: 'ليرة لبنانية', symbol: 'ل.ل', exponent: 2 },
  { code: 'IQD', name: 'Iraqi Dinar', name_ar: 'دينار عراقي', symbol: 'د.ع', exponent: 3 },
  { code: 'SYP', name: 'Syrian Pound', name_ar: 'ليرة سورية', symbol: 'ل.س', exponent: 2 },
  { code: 'YER', name: 'Yemeni Rial', name_ar: 'ريال يمني', symbol: 'ر.ي', exponent: 2 },
  { code: 'LYD', name: 'Libyan Dinar', name_ar: 'دينار ليبي', symbol: 'د.ل', exponent: 3 },
  { code: 'TND', name: 'Tunisian Dinar', name_ar: 'دينار تونسي', symbol: 'د.ت', exponent: 3 },
  { code: 'DZD', name: 'Algerian Dinar', name_ar: 'دينار جزائري', symbol: 'د.ج', exponent: 2 },
  { code: 'MAD', name: 'Moroccan Dirham', name_ar: 'درهم مغربي', symbol: 'د.م', exponent: 2 },
  { code: 'SDG', name: 'Sudanese Pound', name_ar: 'جنيه سوداني', symbol: 'ج.س', exponent: 2 },
  { code: 'MRU', name: 'Mauritanian Ouguiya', name_ar: 'أوقية موريتانية', symbol: 'أ.م', exponent: 2 },
  { code: 'SOS', name: 'Somali Shilling', name_ar: 'شلن صومالي', symbol: 'Sh', exponent: 2 },
  { code: 'DJF', name: 'Djiboutian Franc', name_ar: 'فرنك جيبوتي', symbol: 'Fdj', exponent: 0 },
  { code: 'KMF', name: 'Comorian Franc', name_ar: 'فرنك قمري', symbol: 'CF', exponent: 0 },
  // Major and travel-market currencies
  { code: 'USD', name: 'US Dollar', name_ar: 'دولار أمريكي', symbol: '$', exponent: 2 },
  { code: 'EUR', name: 'Euro', name_ar: 'يورو', symbol: '€', exponent: 2 },
  { code: 'GBP', name: 'Pound Sterling', name_ar: 'جنيه إسترليني', symbol: '£', exponent: 2 },
  { code: 'CHF', name: 'Swiss Franc', name_ar: 'فرنك سويسري', symbol: 'CHF', exponent: 2 },
  { code: 'JPY', name: 'Japanese Yen', name_ar: 'ين ياباني', symbol: '¥', exponent: 0 },
  { code: 'CNY', name: 'Chinese Yuan', name_ar: 'يوان صيني', symbol: '¥', exponent: 2 },
  { code: 'INR', name: 'Indian Rupee', name_ar: 'روبية هندية', symbol: '₹', exponent: 2 },
  { code: 'TRY', name: 'Turkish Lira', name_ar: 'ليرة تركية', symbol: '₺', exponent: 2 },
  { code: 'CAD', name: 'Canadian Dollar', name_ar: 'دولار كندي', symbol: 'C$', exponent: 2 },
  { code: 'AUD', name: 'Australian Dollar', name_ar: 'دولار أسترالي', symbol: 'A$', exponent: 2 },
  { code: 'ILS', name: 'Israeli New Shekel', name_ar: 'شيكل إسرائيلي جديد', symbol: '₪', exponent: 2 },
  { code: 'PKR', name: 'Pakistani Rupee', name_ar: 'روبية باكستانية', symbol: '₨', exponent: 2 },
  { code: 'MYR', name: 'Malaysian Ringgit', name_ar: 'رينغيت ماليزي', symbol: 'RM', exponent: 2 },
  { code: 'SGD', name: 'Singapore Dollar', name_ar: 'دولار سنغافوري', symbol: 'S$', exponent: 2 },
  { code: 'THB', name: 'Thai Baht', name_ar: 'بات تايلاندي', symbol: '฿', exponent: 2 },
  { code: 'RUB', name: 'Russian Ruble', name_ar: 'روبل روسي', symbol: '₽', exponent: 2 },
  { code: 'KRW', name: 'South Korean Won', name_ar: 'وون كوري جنوبي', symbol: '₩', exponent: 0 },
  { code: 'SEK', name: 'Swedish Krona', name_ar: 'كرونا سويدية', symbol: 'kr', exponent: 2 },
  { code: 'ZAR', name: 'South African Rand', name_ar: 'راند جنوب أفريقي', symbol: 'R', exponent: 2 },
]

/** Value of the picker's manual-entry option. */
export const OTHER_CURRENCY = 'other'

/** The catalogue entries that are not yet in the installation (an existing code cannot be created twice). */
export function availableCatalogue(existingCodes: readonly string[]): CatalogueCurrency[] {
  const taken = new Set(existingCodes.map((code) => code.toUpperCase()))
  return CURRENCY_CATALOGUE.filter((entry) => !taken.has(entry.code))
}

/** Form values prefilled from a catalogue entry (all fields are strings, as in the currency form). */
export function catalogueFormValues(entry: CatalogueCurrency): { code: string; name: string; symbol: string; exponent: string } {
  return { code: entry.code, name: entry.name, symbol: entry.symbol, exponent: String(entry.exponent) }
}

export function findCatalogueCurrency(code: string): CatalogueCurrency | undefined {
  return CURRENCY_CATALOGUE.find((entry) => entry.code === code.toUpperCase())
}
