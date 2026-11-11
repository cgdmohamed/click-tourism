import { useQuery } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { Badge, Card, CardHeader, EmptyState, ErrorState, Loading, PageHeader, Table, Td, Th } from '../components/ui'

type TaxProfile = {
  id: string
  name: string
  rate: string
  is_inclusive: boolean
}

/**
 * Active tax profiles used by booking items. The API is intentionally
 * read-only; profiles are maintained with the installation's tax setup.
 */
export default function TaxProfiles() {
  const { t, locale } = useI18n()
  const ar = locale === 'ar'

  const profilesQuery = useQuery({
    queryKey: ['tax-profiles'],
    queryFn: async () => {
      const { data } = await api.get<{ data: TaxProfile[] }>('/tax-profiles')
      return data.data
    },
  })

  const profiles = profilesQuery.data ?? []

  return (
    <div>
      <PageHeader
        title={ar ? 'ملفات الضريبة' : 'Tax profiles'}
        subtitle={ar ? 'الملفات النشطة والسارية المتاحة لبنود الحجز' : 'Active, currently effective profiles available to booking items'}
        icon="percent"
      />

      <Card>
        <CardHeader
          title={ar ? 'الملفات النشطة' : 'Active profiles'}
          subtitle={ar ? 'تُدار من إعداد الضريبة في التثبيت' : "Maintained with the installation's tax configuration"}
        />
        {profilesQuery.isLoading ? <Loading /> : null}
        {profilesQuery.isError ? (
          <ErrorState message={errorMessage(profilesQuery.error)} onRetry={() => void profilesQuery.refetch()} />
        ) : null}
        {!profilesQuery.isLoading && !profilesQuery.isError && profiles.length === 0 ? <EmptyState /> : null}
        {profiles.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('name')}</Th>
                <Th className="text-end">{ar ? 'النسبة' : 'Rate'}</Th>
                <Th>{ar ? 'شامل الضريبة' : 'Tax inclusive'}</Th>
              </tr>
            </thead>
            <tbody>
              {profiles.map((profile) => (
                <tr key={profile.id}>
                  <Td className="font-medium">{profile.name}</Td>
                  <Td className="text-end tabular" dir="ltr">{Number(profile.rate)}%</Td>
                  <Td>
                    <Badge tone={profile.is_inclusive ? 'brand' : 'neutral'}>
                      {profile.is_inclusive ? (ar ? 'شامل' : 'Inclusive') : (ar ? 'يُضاف' : 'Added')}
                    </Badge>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
      </Card>
    </div>
  )
}
