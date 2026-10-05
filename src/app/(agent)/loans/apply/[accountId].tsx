import { router, useLocalSearchParams } from 'expo-router';

import { LoanApplicationForm } from '@/components/loan-application-form';

export default function ApplyForLoanScreen() {
  const params = useLocalSearchParams<{ accountId: string; customerId: string; customerName?: string }>();

  return (
    <LoanApplicationForm
      customerId={params.customerId}
      customerName={params.customerName}
      fixedAccountId={params.accountId}
      withGuarantor
      onDone={() => router.replace('/(agent)/loans')}
    />
  );
}
