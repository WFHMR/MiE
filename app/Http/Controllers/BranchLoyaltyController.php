<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\BranchLoyaltyService;
use App\Services\MemberImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchLoyaltyController extends Controller
{
    public function __construct(private BranchLoyaltyService $loyalty, private MemberImportService $members) {}

    public function index(Request $request)
    {
        ns()->restrict('nexopos.read.customers');
        $branch = (int) $request->query('branch_id', session('selected_branch_id'));
        $this->members->authorizeBranch($branch);
        $request->validate(['q' => 'nullable|string|max:100']);
        $query = trim((string) $request->query('q', ''));
        $grammar = DB::connection()->getQueryGrammar();
        $history = collect();
        if ($request->filled('account')) {
            $account = DB::table('nexopos_loyalty_accounts')->where('id', $request->integer('account'))->firstOrFail();
            $history = DB::table('nexopos_loyalty_entries as e')
                ->join('nexopos_loyalty_accounts as a', 'a.id', '=', 'e.account_id')
                ->leftJoin('nexopos_branches as b', 'b.id', '=', DB::raw('COALESCE('.$grammar->wrap('e.branch_id').', '.$grammar->wrap('a.branch_id').')'))
                ->where('a.customer_id', $account->customer_id)->select('e.*', 'b.name as branch_name')->orderByDesc('e.id')->limit(100)->get();
        }
        $accounts = DB::table('nexopos_users as u')
            ->whereIn('u.id', Customer::select('id'))
            ->where(fn ($q) => $q->whereNull('u.email')->orWhere('u.email', '<>', 'walkin@store.local'))
            ->leftJoin('nexopos_loyalty_accounts as a', 'a.customer_id', '=', 'u.id');
        if ($query !== '') {
            $digits = preg_replace('/[^0-9]/', '', $query);
            $phoneTerm = str_starts_with($digits, '66') && strlen($digits) === 11 ? substr($digits, 2) : $digits;
            $phoneSql = 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE('.$grammar->wrap('u.phone').", ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
            $accounts->where(function ($search) use ($query, $digits, $phoneTerm, $phoneSql): void {
                $search->where('u.first_name', 'like', '%'.$query.'%')->orWhere('u.last_name', 'like', '%'.$query.'%')->orWhere('u.phone', 'like', '%'.$query.'%')
                    ->orWhereExists(fn ($codes) => $codes->selectRaw('1')->from('nexopos_loyalty_accounts as codes')->whereColumn('codes.customer_id', 'u.id')->where('codes.member_code', $query));
                if ($digits !== '' && preg_match('/^[0-9+()\s-]+$/', $query)) {
                    $search->orWhereRaw($phoneSql.' LIKE ?', ['%'.$phoneTerm.'%']);
                    if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
                        $search->orWhereRaw($phoneSql.' LIKE ?', ['%66'.substr($digits, 1).'%']);
                    }
                }
                $parts = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
                if (count($parts) > 1) {
                    $search->orWhere(function ($names) use ($parts): void {
                        foreach ($parts as $part) {
                            $names->where(fn ($name) => $name->where('u.first_name', 'like', '%'.$part.'%')->orWhere('u.last_name', 'like', '%'.$part.'%'));
                        }
                    });
                }
            });
        }
        $accounts = $accounts->select('u.id as customer_id', 'u.first_name', 'u.last_name', 'u.phone')
            ->selectRaw('MIN('.$grammar->wrap('a.id').') as id, MAX('.$grammar->wrap('a.member_code').') as member_code, COALESCE(SUM('.$grammar->wrap('a.points').'),0) as points')
            ->groupBy('u.id', 'u.first_name', 'u.last_name', 'u.phone')->orderBy('u.id')->paginate(30)->withQueryString();

        return view('pages.dashboard.customers.loyalty', [
            'title' => 'คะแนนสมาชิกทุกสาขา', 'description' => 'ตั้งอัตราสะสมและแลกส่วนลด พร้อมตรวจยอดคะแนน',
            'branches' => $this->members->branches()->get(), 'branchId' => $branch, 'settings' => $this->loyalty->settings($branch), 'accounts' => $accounts, 'query' => $query, 'history' => $history,
        ]);
    }

    public function enroll(Request $request)
    {
        ns()->restrict('nexopos.create.customers');
        $data = $request->validate(['branch_id' => 'required|integer', 'customer_id' => 'required|integer', 'acknowledge' => 'accepted']);
        $this->members->authorizeBranch((int) $data['branch_id']);
        $customer = Customer::findOrFail($data['customer_id']);
        abort_if(! $customer->phone || $customer->email === 'walkin@store.local', 422, 'สมาชิกต้องมีเบอร์โทร และไม่ใช่ลูกค้าขาจร');
        DB::transaction(function () use ($data, $customer): void {
            $this->loyalty->lockCustomer((int) $customer->id);
            if (! DB::table('nexopos_loyalty_accounts')->where('customer_id', $customer->id)->exists()) {
                DB::table('nexopos_loyalty_accounts')->insert(['branch_id' => $data['branch_id'], 'customer_id' => $customer->id, 'points' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return redirect()->route('ns.loyalty', ['branch_id' => $data['branch_id'], 'q' => $customer->phone])->with('success', 'ลงทะเบียนคะแนนใช้ร่วมทุกสาขาแล้ว เริ่มต้น 0 คะแนน (สมาชิกเดิมคงคะแนนเดิม)');
    }

    public function save(Request $request)
    {
        ns()->restrict('nexopos.update.rewards');
        $data = $request->validate(['branch_id' => 'required|integer', 'enabled' => 'required|boolean', 'earn_baht' => 'required|numeric|min:0.01|max:1000000|decimal:0,2', 'redeem_points' => 'required|integer|min:1|max:1000000', 'redeem_baht' => 'required|numeric|min:0.01|max:1000000|decimal:0,2']);
        $branch = (int) $data['branch_id'];
        $this->members->authorizeBranch($branch);
        $settings = ['enabled' => (bool) $data['enabled'], 'earn_satang' => (int) round($data['earn_baht'] * 100), 'redeem_points' => (int) $data['redeem_points'], 'redeem_satang' => (int) round($data['redeem_baht'] * 100), 'updated_by' => auth()->id(), 'updated_at' => now()];
        DB::transaction(function () use ($settings): void {
            DB::table('nexopos_loyalty_settings')->updateOrInsert(['branch_id' => 0], $settings);
            DB::table('nexopos_loyalty_settings_history')->insert(['branch_id' => 0, 'author_id' => auth()->id(), 'settings' => json_encode($settings), 'created_at' => now()]);
        });

        return redirect()->route('ns.loyalty', ['branch_id' => $branch])->with('success', 'บันทึกกติกากลาง ใช้ร่วมกันทุกสาขาแล้ว');
    }

    public function context(Request $request)
    {
        ns()->restrict('nexopos.create.orders');
        $data = $request->validate(['customer_id' => 'required|integer']);
        $branch = (int) session('selected_branch_id');
        $this->members->authorizeBranch($branch);
        $customer = Customer::findOrFail($data['customer_id']);
        $account = DB::table('nexopos_loyalty_accounts')->where('customer_id', $data['customer_id'])->first();
        $settings = $this->loyalty->settings($branch);

        return ['points' => $this->loyalty->balance((int) $data['customer_id']), 'member' => (bool) $account || $this->loyalty->eligible($customer), 'enabled' => (bool) ($settings?->enabled ?? false), 'redeem_points' => $settings?->redeem_points, 'redeem_baht' => ($settings?->redeem_satang ?? 0) / 100];
    }
}
