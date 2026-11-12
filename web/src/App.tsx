import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { I18nProvider } from './i18n'
import { ThemeProvider } from './theme'
import { AuthProvider } from './auth/AuthProvider'
import { Layout } from './components/Layout'
import { DemoBanner } from './components/DemoBanner'
import Login from './pages/Login'
import Dashboard from './pages/Dashboard'
import Accounts from './pages/Accounts'
import Journal from './pages/Journal'
import Customers from './pages/Customers'
import CustomerProfile from './pages/CustomerProfile'
import Suppliers from './pages/Suppliers'
import SupplierProfile from './pages/SupplierProfile'
import Employees from './pages/Employees'
import EmployeeProfile from './pages/EmployeeProfile'
import Bookings from './pages/Bookings'
import TourPackages from './pages/TourPackages'
import Payments from './pages/Payments'
import PaymentMethods from './pages/PaymentMethods'
import SupplierSettlements from './pages/SupplierSettlements'
import Reports from './pages/Reports'
import Leads from './pages/Leads'
import AssignmentRules from './pages/AssignmentRules'
import Settings from './pages/Settings'
import Users from './pages/Users'
import AuditLog from './pages/AuditLog'
import Roles from './pages/Roles'
import Profile from './pages/Profile'
import Treasury from './pages/Treasury'
import Commissions from './pages/Commissions'
import Expenses from './pages/Expenses'
import OpeningBalances from './pages/OpeningBalances'
import Periods from './pages/Periods'
import Invoices from './pages/Invoices'
import InvoiceProfile from './pages/InvoiceProfile'
import Documents from './pages/Documents'
import Reconciliation from './pages/Reconciliation'
import Schedules from './pages/Schedules'
import FixedAssets from './pages/FixedAssets'
import ExchangeRates from './pages/ExchangeRates'
import CostCenters from './pages/CostCenters'
import TaxProfiles from './pages/TaxProfiles'
import Imports from './pages/Imports'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false, staleTime: 30_000 },
  },
})

export default function App() {
  return (
    <I18nProvider>
      <ThemeProvider>
        <QueryClientProvider client={queryClient}>
          <AuthProvider>
            <BrowserRouter>
              <DemoBanner />
              <Routes>
                <Route path="/login" element={<Login />} />
                <Route element={<Layout />}>
                  <Route index element={<Dashboard />} />
                  <Route path="accounts" element={<Accounts />} />
                  <Route path="journal" element={<Journal />} />
                  <Route path="treasury" element={<Treasury />} />
                  <Route path="expenses" element={<Expenses />} />
                  <Route path="commissions" element={<Commissions />} />
                  <Route path="opening-balances" element={<OpeningBalances />} />
                  <Route path="periods" element={<Periods />} />
                  <Route path="invoices" element={<Invoices />} />
                  <Route path="invoices/:id" element={<InvoiceProfile />} />
                  <Route path="documents" element={<Documents />} />
                  <Route path="reconciliation" element={<Reconciliation />} />
                  <Route path="schedules" element={<Schedules />} />
                  <Route path="fixed-assets" element={<FixedAssets />} />
                  <Route path="cost-centers" element={<CostCenters />} />
                  <Route path="tax-profiles" element={<TaxProfiles />} />
                  <Route path="exchange-rates" element={<ExchangeRates />} />
                  <Route path="customers" element={<Customers />} />
                  <Route path="customers/:id" element={<CustomerProfile />} />
                  <Route path="suppliers" element={<Suppliers />} />
                  <Route path="suppliers/:id" element={<SupplierProfile />} />
                  <Route path="employees" element={<Employees />} />
                  <Route path="employees/:id" element={<EmployeeProfile />} />
                  <Route path="bookings" element={<Bookings />} />
                  <Route path="tour-packages" element={<TourPackages />} />
                  <Route path="payments" element={<Payments />} />
                  <Route path="payment-methods" element={<PaymentMethods />} />
                  <Route path="supplier-settlements" element={<SupplierSettlements />} />
                  <Route path="reports" element={<Reports />} />
                  <Route path="leads" element={<Leads />} />
                  <Route path="assignment-rules" element={<AssignmentRules />} />
                  <Route path="imports" element={<Imports />} />
                  <Route path="settings" element={<Settings />} />
                  <Route path="users" element={<Users />} />
                  <Route path="roles" element={<Roles />} />
        <Route path="audit-events" element={<AuditLog />} />
                  <Route path="profile" element={<Profile />} />
                </Route>
              </Routes>
            </BrowserRouter>
          </AuthProvider>
        </QueryClientProvider>
      </ThemeProvider>
    </I18nProvider>
  )
}
