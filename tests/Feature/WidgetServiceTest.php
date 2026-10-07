<?php

namespace Tests\Feature;

use App\Services\WidgetService;
use App\Widgets\MyNexoPosWidget;
use Tests\TestCase;

/**
 * The dashboard widget that shows takings.
 *
 * This file sat in tests/Feature in no phpunit suite, so it had never run, and it still asserted the
 * upstream widget: the name "My NexoPOS", the permission manage.modules, and a payload whose only key
 * said whether the marketplace account was connected. The widget has since been repurposed into the
 * takings panel the shop actually uses, which means the assertions described something that no longer
 * existed and would have failed the moment anybody ran them.
 *
 * It is kept and corrected rather than deleted, because the thing worth pinning is precisely what
 * changed: the widget is named in Thai and carries no upstream product name, and its permission is the
 * one a shop manager holds rather than the one that installs modules. A test is the only thing that
 * stops either of those quietly reverting on the next merge.
 *
 * The class name is left alone. It is referenced by the compiled component key nsMyNexoPosWidget and by
 * the widget rows already stored in the options table on the live install, so renaming it is a data
 * migration rather than a rename, and it is never shown to anybody.
 */
class WidgetServiceTest extends TestCase
{
    public function test_the_takings_widget_is_registered_under_a_thai_name(): void
    {
        $widgets = app( WidgetService::class )->getAllWidgets();

        $widget = $widgets->firstWhere( 'class-name', MyNexoPosWidget::class );

        $this->assertNotNull( $widget, 'The takings widget is not registered at all.' );
        $this->assertSame( 'ยอดขายสะสม', $widget->name );
        $this->assertSame( 'nsMyNexoPosWidget', $widget->{'component-name'} );

        /**
         * read.dashboard, not manage.modules. Under the upstream permission a shop manager could not
         * see the panel at all, while anybody who could install modules could.
         */
        $this->assertSame( 'read.dashboard', $widget->instance->getPermission() );
    }

    /**
     * Nothing a user reads may carry the upstream product name.
     */
    public function test_the_widget_shows_no_upstream_name(): void
    {
        $widgets = app( WidgetService::class )->getAllWidgets();

        foreach ( $widgets as $widget ) {
            $this->assertStringNotContainsStringIgnoringCase(
                'nexopos',
                (string) $widget->name,
                'A dashboard widget is presented under the upstream product name.'
            );

            /**
             * Read from the instance: getAllWidgets() does not put the description in the object it
             * maps, so reading $widget->description finds nothing and asserts against an empty string.
             */
            $this->assertStringNotContainsStringIgnoringCase(
                'nexopos',
                (string) $widget->instance->getDescription(),
                'A dashboard widget description carries the upstream product name.'
            );
        }
    }
}
