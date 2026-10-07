<?php

namespace App\Widgets;

use App\Services\WidgetService;

class MyNexoPosWidget extends WidgetService
{
    protected $vueComponent = 'nsMyNexoPosWidget';

    public function __construct()
    {
        $this->name = __( 'ยอดขายสะสม' );
        $this->description = __( 'แสดงยอดขายสะสม รายวัน รายเดือน รายปี' );
        $this->permission = 'read.dashboard';
    }

    public function getData(): array
    {
        return [
            'isConnected' => ! empty( ns()->option->get( 'mynexopos_access_token' ) ) && ! empty( ns()->option->get( 'mynexopos_refresh_token' ) ),
        ];
    }
}
