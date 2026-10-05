import type { Customer } from '@/types/api';

/** Display name: business name for business clients, else first + last. */
export function customerDisplayName(customer: Customer | null | undefined): string {
  if (!customer) {
    return '';
  }

  return customer.client_type === 'business' && customer.business_name
    ? customer.business_name
    : `${customer.first_name} ${customer.last_name}`;
}
