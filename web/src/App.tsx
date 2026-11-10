import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { I18nProvider } from './i18n'
import { ThemeProvider } from './theme'
import { AuthProvider } from './auth/AuthProvider'
import { Layout } from './components/Layout'
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
import Payments from './pages/Payments'
import SupplierSettlements from './pages/SupplierSettlements'
import Reports from './pages/Reports'
import Leads from './pages/Leads'
import AssignmentRules from './pages/AssignmentRules'
import Settings from './pages/Settings'
import Treasury from './pages/Treasury'
import Expenses from './pages/Expenses'
import OpeningBalances from './pages/OpeningBalances'
import Periods from './pages/Periods'
import Invoices from './pages/Invoices'
import InvoiceProfile from './pages/InvoiceProfile'
import Documents from './pages/Documents'
import Reconciliation from './pages/Reconciliation'
import Schedules from './pages/Schedules'
import FixedAssets from './pages/FixedAssets'

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
              <Routes>
                <Route path="/login" element={<Login />} />
                <Route element={<Layout />}>
                  <Route index element={<Dashboard />} />
                  <Route path="accounts" element={<Accounts />} />
                  <Route path="journal" element={<Journal />} />
                  <Route path="treasury" element={<Treasury />} />
                  <Route path="expenses" element={<Expenses />} />
                  <Route path="opening-balances" element={<OpeningBalances />} />
                  <Route path="periods" element={<Periods />} />
                  <Route path="invoices" element={<Invoices />} />
                  <Route path="invoices/:id" element={<InvoiceProfile />} />
                  <Route path="documents" element={<Documents />} />
                  <Route path="reconciliation" element={<Reconciliation />} />
                  <Route path="schedules" element={<Schedules />} />
                  <Route path="fixed-assets" element={<FixedAssets />} />
                  <Route path="customers" element={<Customers />} />
                  <Route path="customers/:id" element={<CustomerProfile />} />
                  <Route path="suppliers" element={<Suppliers />} />
                  <Route path="suppliers/:id" element={<SupplierProfile />} />
                  <Route path="employees" element={<Employees />} />
                  <Route path="employees/:id" element={<EmployeeProfile />} />
                  <Route path="bookings" element={<Bookings />} />
                  <Route path="payments" element={<Payments />} />
                  <Route path="supplier-settlements" element={<SupplierSettlements />} />
                  <Route path="reports" element={<Reports />} />
                  <Route path="leads" element={<Leads />} />
                  <Route path="assignment-rules" element={<AssignmentRules />} />
                  <Route path="settings" element={<Settings />} />
                </Route>
              </Routes>
            </BrowserRouter>
          </AuthProvider>
        </QueryClientProvider>
      </ThemeProvider>
    </I18nProvider>
  )
}
