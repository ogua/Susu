import { router } from 'expo-router';

import { LoanList } from '@/components/loan-list';

export default function AgentLoansScreen() {
  return (
    <LoanList
      queryKey={['agent', 'loans']}
      onOpen={(loan) => router.push({ pathname: '/(agent)/loans/[loanId]', params: { loanId: loan.id } })}
      emptyHint="To start an application, open a customer's account from Collect and tap “Apply for loan”."
    />
  );
}
