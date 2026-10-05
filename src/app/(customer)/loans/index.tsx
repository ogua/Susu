import { router } from 'expo-router';

import { LoanList } from '@/components/loan-list';
import { Button } from '@/components/ui';

export default function CustomerLoansScreen() {
  return (
    <LoanList
      queryKey={['customer', 'loans']}
      onOpen={(loan) => router.push({ pathname: '/(customer)/loans/[loanId]', params: { loanId: loan.id } })}
      header={<Button title="Apply for a loan" icon="add" onPress={() => router.push('/(customer)/loans/apply')} />}
      emptyHint="Loans you apply for, and their repayment progress, will appear here."
    />
  );
}
