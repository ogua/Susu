import { useQuery } from '@tanstack/react-query';

import { getCustomer } from '@/api/customers';
import { ChipSelect, LoadingState, Notice } from '@/components/ui';
import { displayFormatted } from '@/utils/money';

/**
 * Lets the agent pick which of a customer's savings accounts a deposit or
 * write-off applies to — the backend now requires an explicit
 * savings_account_id instead of inferring or escrowing one.
 */
export function SavingsAccountPicker({
  customerId,
  value,
  onChange,
  label = 'Savings account',
}: {
  customerId: string;
  value: string | null;
  onChange: (accountId: string) => void;
  label?: string;
}) {
  const customer = useQuery({
    queryKey: ['customer', customerId],
    queryFn: () => getCustomer(customerId),
    enabled: !!customerId,
  });

  if (customer.isLoading) {
    return <LoadingState label="Loading savings accounts…" />;
  }

  const accounts = customer.data?.savings_accounts ?? [];

  if (accounts.length === 0) {
    return <Notice tone="warning" message="This customer has no savings accounts. Open one first." />;
  }

  return (
    <ChipSelect
      accessibilityLabel={label}
      options={accounts.map((account) => ({
        value: account.id,
        label: account.account_number,
        description: `Balance ${displayFormatted(account.balance_formatted)}`,
      }))}
      value={value}
      onChange={onChange}
    />
  );
}
