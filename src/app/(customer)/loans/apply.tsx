import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';

import { getCustomerAccounts } from '@/api/accounts';
import { LoanApplicationForm } from '@/components/loan-application-form';
import { ErrorState, LoadingState } from '@/components/ui';

export default function CustomerApplyForLoanScreen() {
  const accounts = useQuery({ queryKey: ['customer', 'accounts'], queryFn: getCustomerAccounts });

  if (accounts.isLoading) {
    return <LoadingState label="Loading your accounts…" />;
  }
  if (accounts.isError) {
    return <ErrorState title="Couldn't load your accounts" onRetry={() => void accounts.refetch()} />;
  }

  return (
    <LoanApplicationForm
      accounts={accounts.data ?? []}
      withGuarantor={false}
      onDone={() => router.replace('/(customer)/loans')}
    />
  );
}
