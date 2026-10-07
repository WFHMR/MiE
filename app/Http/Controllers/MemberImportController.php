<?php

namespace App\Http\Controllers;

use App\Models\CustomerGroup;
use App\Services\MemberImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberImportController extends Controller
{
    public function __construct( private MemberImportService $members ) {}

    public function index( Request $request )
    {
        ns()->restrict( 'nexopos.create.customers' );
        $batch = null;
        if ( $request->filled( 'batch' ) ) {
            $batch = DB::table( 'nexopos_member_imports' )->where( 'id', $request->query( 'batch' ) )->where( 'author_id', auth()->id() )->firstOrFail();
            $this->members->authorizeBranch( (int) $batch->branch_id );
            $batch->rows = json_decode( $batch->rows, true );
        }

        return view( 'pages.dashboard.customers.import-members', [
            'title' => 'นำเข้าสมาชิก', 'description' => 'ตรวจสอบชื่อ เบอร์โทร และคะแนนตั้งต้นที่ใช้ร่วมทุกสาขาก่อนยืนยัน',
            'branches' => $this->members->branches()->get(), 'groups' => CustomerGroup::all(), 'batch' => $batch,
        ] );
    }

    public function preview( Request $request )
    {
        ns()->restrict( 'nexopos.create.customers' );
        $data = $request->validate( ['file' => 'required|file|max:4096|extensions:csv,xls,xlsx', 'branch_id' => 'required|integer', 'group_id' => 'required|integer'] );
        $this->members->authorizeBranch( (int) $data['branch_id'] );
        $file = $request->file( 'file' );
        $id = $this->members->preview( $this->members->read( $file->getRealPath(), $file->getClientOriginalExtension() ), (int) $data['branch_id'], (int) $data['group_id'] );

        return redirect()->route( 'ns.members.import', ['batch' => $id] );
    }

    public function confirm( Request $request, string $id )
    {
        ns()->restrict( 'nexopos.create.customers' );
        $request->validate( ['acknowledge' => 'accepted'] );
        $count = $this->members->confirm( $id );

        return redirect()->route( 'ns.members.import', ['batch' => $id] )->with( 'success', 'นำเข้าแล้ว ' . $count . ' รายการ' );
    }

    public function template()
    {
        ns()->restrict( 'nexopos.create.customers' );

        return response( "\xEF\xBB\xBFชื่อ,เบอร์โทร,รหัสสมาชิก,คะแนน\r\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="member-template.csv"'] );
    }
}
