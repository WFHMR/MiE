<?php

namespace Tests\Feature;

use Tests\TestCase;

class VueLifecycleHooksTest extends TestCase
{
    /**
     * Vue 3 renamed beforeDestroy to beforeUnmount and destroyed to unmounted.
     * The old names are not errors, they are simply ignored, so cleanup written
     * in them never runs: hotkey listeners stay registered and RxJS
     * subscriptions keep receiving after the component is gone.
     *
     * One such leak let the payment numpad record a payment from a keystroke
     * typed into an unrelated popup.
     */
    public function test_no_component_uses_a_vue_2_teardown_hook(): void
    {
        $offenders = [];

        foreach ( $this->componentFiles() as $file ) {
            $contents = file_get_contents( $file );

            /**
             * Matched as a property of the options object rather than anywhere in
             * the file, so a mention inside a comment or a string is ignored.
             */
            if ( preg_match( '/^\s*(beforeDestroy|destroyed)\s*\(\s*\)\s*\{/m', $contents, $matches ) ) {
                $offenders[] = sprintf(
                    '%s uses %s()',
                    str_replace( base_path() . DIRECTORY_SEPARATOR, '', $file ),
                    $matches[ 1 ]
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Vue 2 teardown hooks never run under Vue 3, so this cleanup is dead:\n" . implode( "\n", $offenders )
        );
    }

    /**
     * Every Vue single file component shipped by the application.
     *
     * @return array<int, string>
     */
    private function componentFiles(): array
    {
        $directory = new \RecursiveDirectoryIterator( resource_path( 'ts' ) );
        $iterator = new \RecursiveIteratorIterator( $directory );

        $files = [];

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && $file->getExtension() === 'vue' ) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty( $files, 'No Vue components were found to inspect.' );

        return $files;
    }
}
