@includeWhen( request()->query( 'dash-visibility' ) === 'disabled', 'pages.dashboard.taxes.withholding._naked_certificate' )
@includeWhen( empty( request()->query( 'dash-visibility' ) ), 'pages.dashboard.taxes.withholding._dashboard_certificate' )
