@includeWhen( request()->query( 'dash-visibility' ) === 'disabled', 'pages.dashboard.orders.templates._naked_billing_note' )
@includeWhen( empty( request()->query( 'dash-visibility' ) ), 'pages.dashboard.orders.templates._dashboard_billing_note' )
