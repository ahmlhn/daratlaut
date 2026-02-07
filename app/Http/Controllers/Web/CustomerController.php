<?php

namespace App\Http\Controllers\Web;

use App\Models\BillingCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController
{
    public function index(Request $request): Response
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 0);
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $perPage = max(10, min(100, (int) $request->query('per_page', 20)));

        $items = [];
        $meta = [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
        $warning = null;

        if (!Schema::hasTable('noci_billing_customers')) {
            $warning = 'Table noci_billing_customers belum ada. Jalankan migration terlebih dulu.';
        } else {
            $query = BillingCustomer::query()->forTenant($tenantId);

            if ($q !== '') {
                $query->where(function ($builder) use ($q) {
                    $like = '%' . $q . '%';
                    $builder->where('full_name', 'like', $like)
                        ->orWhere('customer_code', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('profile_name', 'like', $like)
                        ->orWhere('pop_name', 'like', $like)
                        ->orWhere('odp_name', 'like', $like)
                        ->orWhere('reseller_name', 'like', $like);
                });
            }

            if (in_array($status, ['AKTIF', 'SUSPEND', 'NONAKTIF'], true)) {
                $query->where('service_status', $status);
            }

            $paginator = $query->orderByDesc('id')
                ->paginate($perPage)
                ->withQueryString();

            $items = $paginator->items();
            $meta = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        return Inertia::render('Customers/Index', [
            'tenantId' => $tenantId,
            'filters' => [
                'q' => $q,
                'status' => $status,
                'per_page' => $perPage,
            ],
            'customers' => $items,
            'meta' => $meta,
            'warning' => $warning,
        ]);
    }
}
