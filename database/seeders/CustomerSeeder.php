<?php

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Enums\MaritalStatus;
use App\Enums\ResidencyStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds ~50 individual customers with realistic Ghanaian names, phone numbers,
 * and localities, spread across the branches of the first company. Local/dev
 * demo data only — run DemoSeeder (or seed a company + branch) first.
 */
class CustomerSeeder extends Seeder
{
    private const COUNT = 50;

    /** @var list<array{name: string, gender: string}> */
    private const FEMALE_FIRST_NAMES = [
        ['name' => 'Akosua', 'gender' => 'female'],
        ['name' => 'Adwoa', 'gender' => 'female'],
        ['name' => 'Abena', 'gender' => 'female'],
        ['name' => 'Akua', 'gender' => 'female'],
        ['name' => 'Yaa', 'gender' => 'female'],
        ['name' => 'Afua', 'gender' => 'female'],
        ['name' => 'Ama', 'gender' => 'female'],
        ['name' => 'Afia', 'gender' => 'female'],
        ['name' => 'Esi', 'gender' => 'female'],
        ['name' => 'Araba', 'gender' => 'female'],
        ['name' => 'Efua', 'gender' => 'female'],
        ['name' => 'Adjoa', 'gender' => 'female'],
        ['name' => 'Dzifa', 'gender' => 'female'],
        ['name' => 'Serwaa', 'gender' => 'female'],
        ['name' => 'Comfort', 'gender' => 'female'],
        ['name' => 'Gifty', 'gender' => 'female'],
        ['name' => 'Vida', 'gender' => 'female'],
        ['name' => 'Priscilla', 'gender' => 'female'],
        ['name' => 'Belinda', 'gender' => 'female'],
        ['name' => 'Naa Adjeley', 'gender' => 'female'],
        ['name' => 'Lariba', 'gender' => 'female'],
        ['name' => 'Ayishetu', 'gender' => 'female'],
        ['name' => 'Hawa', 'gender' => 'female'],
        ['name' => 'Mercy', 'gender' => 'female'],
        ['name' => 'Grace', 'gender' => 'female'],
    ];

    /** @var list<array{name: string, gender: string}> */
    private const MALE_FIRST_NAMES = [
        ['name' => 'Kwame', 'gender' => 'male'],
        ['name' => 'Kofi', 'gender' => 'male'],
        ['name' => 'Kwaku', 'gender' => 'male'],
        ['name' => 'Yaw', 'gender' => 'male'],
        ['name' => 'Kwabena', 'gender' => 'male'],
        ['name' => 'Kwadwo', 'gender' => 'male'],
        ['name' => 'Kojo', 'gender' => 'male'],
        ['name' => 'Kwasi', 'gender' => 'male'],
        ['name' => 'Fiifi', 'gender' => 'male'],
        ['name' => 'Ekow', 'gender' => 'male'],
        ['name' => 'Kobina', 'gender' => 'male'],
        ['name' => 'Selorm', 'gender' => 'male'],
        ['name' => 'Elikem', 'gender' => 'male'],
        ['name' => 'Mawuli', 'gender' => 'male'],
        ['name' => 'Emmanuel', 'gender' => 'male'],
        ['name' => 'Isaac', 'gender' => 'male'],
        ['name' => 'Samuel', 'gender' => 'male'],
        ['name' => 'Daniel', 'gender' => 'male'],
        ['name' => 'Francis', 'gender' => 'male'],
        ['name' => 'Richmond', 'gender' => 'male'],
        ['name' => 'Ebenezer', 'gender' => 'male'],
        ['name' => 'Godfred', 'gender' => 'male'],
        ['name' => 'Nii Armah', 'gender' => 'male'],
        ['name' => 'Abdul-Rahman', 'gender' => 'male'],
        ['name' => 'Iddrisu', 'gender' => 'male'],
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'Mensah', 'Owusu', 'Boateng', 'Osei', 'Asante', 'Agyeman', 'Appiah',
        'Adjei', 'Amoah', 'Annan', 'Darko', 'Frimpong', 'Gyasi', 'Acheampong',
        'Addo', 'Antwi', 'Bediako', 'Danso', 'Nkrumah', 'Ofori', 'Opoku',
        'Sarpong', 'Yeboah', 'Asamoah', 'Bonsu', 'Quaye', 'Lamptey', 'Tetteh',
        'Ashong', 'Aryee', 'Nartey', 'Sowah', 'Agbeko', 'Ahiable', 'Azumah',
        'Abdulai', 'Alhassan', 'Mahama', 'Yakubu', 'Fuseini', 'Sulemana',
    ];

    /** @var list<array{town: string, region: string}> */
    private const LOCALITIES = [
        ['town' => 'Accra', 'region' => 'Greater Accra'],
        ['town' => 'Tema', 'region' => 'Greater Accra'],
        ['town' => 'Madina', 'region' => 'Greater Accra'],
        ['town' => 'Ashaiman', 'region' => 'Greater Accra'],
        ['town' => 'Kasoa', 'region' => 'Central'],
        ['town' => 'Cape Coast', 'region' => 'Central'],
        ['town' => 'Winneba', 'region' => 'Central'],
        ['town' => 'Kumasi', 'region' => 'Ashanti'],
        ['town' => 'Obuasi', 'region' => 'Ashanti'],
        ['town' => 'Ejisu', 'region' => 'Ashanti'],
        ['town' => 'Takoradi', 'region' => 'Western'],
        ['town' => 'Tarkwa', 'region' => 'Western'],
        ['town' => 'Koforidua', 'region' => 'Eastern'],
        ['town' => 'Nkawkaw', 'region' => 'Eastern'],
        ['town' => 'Ho', 'region' => 'Volta'],
        ['town' => 'Keta', 'region' => 'Volta'],
        ['town' => 'Tamale', 'region' => 'Northern'],
        ['town' => 'Sunyani', 'region' => 'Bono'],
    ];

    /** @var list<string> */
    private const OCCUPATIONS = [
        'Trader', 'Seamstress', 'Farmer', 'Taxi Driver', 'Hairdresser',
        'Teacher', 'Market Vendor', 'Carpenter', 'Mason', 'Fishmonger',
        'Provision Shop Owner', 'Mechanic', 'Nurse', 'Electrician', 'Caterer',
        'Kente Weaver', 'Poultry Farmer', 'Tailor', 'Barber', 'Cocoa Farmer',
    ];

    /** @var list<string> */
    private const MOBILE_PREFIXES = ['24', '54', '55', '59', '20', '50', '27', '57', '26', '56', '23', '53'];

    public function run(): void
    {
        $company = Company::query()->oldest()->first();

        if (! $company) {
            $this->command?->warn('CustomerSeeder skipped: no company found. Run DemoSeeder first.');

            return;
        }

        $branches = Branch::query()->where('company_id', $company->id)->get();

        if ($branches->isEmpty()) {
            $this->command?->warn('CustomerSeeder skipped: company has no branches.');

            return;
        }

        $agentsByBranch = User::query()
            ->role('field_agent')
            ->whereIn('branch_id', $branches->pluck('id'))
            ->get()
            ->groupBy('branch_id');

        $firstNamePool = collect(self::FEMALE_FIRST_NAMES)
            ->merge(self::MALE_FIRST_NAMES)
            ->shuffle();

        $takenCodes = Customer::query()
            ->where('company_id', $company->id)
            ->pluck('customer_code')
            ->flip();

        $takenPhones = Customer::query()
            ->pluck('phone')
            ->merge(User::query()->pluck('phone'))
            ->filter()
            ->flip();

        $nextSequence = 0;

        for ($i = 0; $i < self::COUNT; $i++) {
            do {
                $customerCode = sprintf('CUS-%05d', ++$nextSequence);
            } while ($takenCodes->has($customerCode));
            $takenCodes->put($customerCode, true);

            $phone = $this->uniqueGhanaMobile($takenPhones);
            $nextOfKinPhone = $this->uniqueGhanaMobile($takenPhones);

            $branch = $branches[$i % $branches->count()];
            $person = $firstNamePool[$i % $firstNamePool->count()];
            $isMarried = fake()->boolean(55);

            $spouse = $isMarried
                ? $this->spouseFor($person['gender'], $firstNamePool)
                : ['name' => null, 'gender' => null];

            $locality = fake()->randomElement(self::LOCALITIES);
            $agentId = $this->agentIdFor($agentsByBranch, $branch->id);

            Customer::factory()
                ->for($branch)
                ->for($branch->company)
                ->individual()
                ->create([
                    'customer_code' => $customerCode,
                    'first_name' => $person['name'],
                    'last_name' => fake()->randomElement(self::LAST_NAMES),
                    'other_names' => fake()->boolean(25) ? fake()->randomElement(self::LAST_NAMES) : null,
                    'phone' => $phone,
                    'gender' => $person['gender'],
                    'date_of_birth' => fake()->dateTimeBetween('-60 years', '-19 years'),
                    'id_type' => 'ghana_card',
                    'id_number' => sprintf('GHA-%09d-%d', fake()->numberBetween(100000000, 999999999), fake()->randomDigit()),
                    'nationality' => 'Ghanaian',
                    'country' => 'Ghana',
                    'country_of_residence' => 'Ghana',
                    'city_town' => $locality['town'],
                    'state_region' => $locality['region'],
                    'digital_address' => $this->ghanaPostGps(),
                    'address' => fake()->buildingNumber().' '.fake()->streetName().', '.$locality['town'],
                    'occupation' => fake()->randomElement(self::OCCUPATIONS),
                    'marital_status' => $isMarried ? MaritalStatus::Married : MaritalStatus::Single,
                    'spouse_name' => $spouse['name'],
                    'spouse_occupation' => $isMarried ? fake()->randomElement(self::OCCUPATIONS) : null,
                    'residency_status' => fake()->randomElement(ResidencyStatus::cases()),
                    'has_past_loan' => fake()->boolean(30),
                    'next_of_kin_name' => $this->fullGhanaianName($firstNamePool),
                    'next_of_kin_phone' => $nextOfKinPhone,
                    'next_of_kin_relationship' => fake()->randomElement(['spouse', 'sibling', 'parent', 'child']),
                    'client_type' => ClientType::Individual,
                    'status' => 'active',
                    'registered_by' => $agentId,
                    'assigned_agent_id' => $agentId,
                ]);
        }

        $this->command?->info(sprintf(
            'Seeded %d customers across %d branch(es) of "%s".',
            self::COUNT,
            $branches->count(),
            $company->name,
        ));
    }

    /**
     * @param  Collection<int, array{name: string, gender: string}>  $pool
     * @return array{name: string, gender: string}
     */
    private function spouseFor(string $customerGender, Collection $pool): array
    {
        $opposite = $customerGender === 'male' ? 'female' : 'male';

        return [
            'name' => $pool->where('gender', $opposite)->random()['name'].' '.fake()->randomElement(self::LAST_NAMES),
            'gender' => $opposite,
        ];
    }

    /**
     * @param  Collection<int, array{name: string, gender: string}>  $pool
     */
    private function fullGhanaianName(Collection $pool): string
    {
        return $pool->random()['name'].' '.fake()->randomElement(self::LAST_NAMES);
    }

    /**
     * @param  Collection<string, Collection<int, User>>  $agentsByBranch
     */
    private function agentIdFor(Collection $agentsByBranch, string $branchId): ?string
    {
        $agents = $agentsByBranch->get($branchId);

        return $agents && $agents->isNotEmpty() ? $agents->random()->id : null;
    }

    /**
     * @param  Collection<string, mixed>  $taken  Phone numbers already in use; the returned number is added to it.
     */
    private function uniqueGhanaMobile(Collection $taken): string
    {
        do {
            $number = '0'.fake()->randomElement(self::MOBILE_PREFIXES).fake()->numerify('#######');
        } while ($taken->has($number));

        $taken->put($number, true);

        return $number;
    }

    private function ghanaPostGps(): string
    {
        $regionCode = fake()->randomElement(['GA', 'GS', 'GR', 'GC', 'GW', 'GE', 'GT', 'GN', 'GB']);

        return sprintf('%s-%03d-%04d', $regionCode, fake()->numberBetween(1, 999), fake()->numberBetween(1, 9999));
    }
}
