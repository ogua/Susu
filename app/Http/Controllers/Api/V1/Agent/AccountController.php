<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SavingsAccountResource;
use App\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    /** Accounts assigned to the authenticated agent, searchable by name/number/phone. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = SavingsAccount::query()
            ->where('agent_id', $request->user()->id)
            ->with(['customer', 'product'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->value().'%';
                $query->where(function ($q) use ($term): void {
                    $q->where('account_number', 'like', $term)
                        ->orWhereHas('customer', function ($c) use ($term): void {
                            $c->where('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term)
                                ->orWhere('phone', 'like', $term);
                        });
                });
            })
            ->orderBy('account_number')
            ->paginate($request->integer('per_page', 50));

        return SavingsAccountResource::collection($accounts);
    }
}
