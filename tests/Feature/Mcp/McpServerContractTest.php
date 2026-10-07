<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\POSServer;
use App\Mcp\Tools\UpdateCategoryTool;
use App\Mcp\Tools\UpdateProductTool;
use App\Mcp\Tools\UpdateProductUnitQuantityTool;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the contract between POSServer::$tools and its #[Instructions] block.
 *
 * An agent can only call what is registered, but it will happily try to call
 * whatever the instructions describe. When the two drift apart the agent fails
 * at runtime with no clear signal, so the drift is asserted here instead.
 */
class McpServerContractTest extends TestCase
{
    /**
     * Tool names that were advertised in the instructions but never existed as
     * classes. Kept as an explicit regression guard.
     *
     * @var array<int, string>
     */
    private const PHANTOM_TOOL_NAMES = [
        'create_product',
        'delete_product',
        'delete_category',
        'create_category',
        'update_category',
        'get_product_unit_quantities',
    ];

    /**
     * @return array<int, class-string<Tool>>
     */
    private function registeredToolClasses(): array
    {
        /**
         * Read the declared default rather than instantiating the server, which
         * would require a Transport that only exists during a real MCP request.
         */
        $property = ( new ReflectionClass( POSServer::class ) )->getProperty( 'tools' );
        $property->setAccessible( true );

        return $property->getDefaultValue();
    }

    /**
     * @return array<int, string>
     */
    private function registeredToolNames(): array
    {
        return array_map(
            fn ( string $class ): string => app()->make( $class )->name(),
            $this->registeredToolClasses()
        );
    }

    private function instructions(): string
    {
        $attributes = ( new ReflectionClass( POSServer::class ) )->getAttributes( Instructions::class );

        $this->assertNotEmpty( $attributes, 'POSServer must declare an #[Instructions] attribute.' );

        return $attributes[0]->newInstance()->value;
    }

    /**
     * The payload an MCP client actually receives for a tool.
     *
     * @return array{properties: array<string, mixed>, required: array<int, string>}
     */
    private function inputSchemaOf( string $toolClass ): array
    {
        $definition = app()->make( $toolClass )->toArray();

        $this->assertArrayHasKey( 'inputSchema', $definition, "{$toolClass} produced no inputSchema." );

        return [
            'properties' => (array) ( $definition['inputSchema']['properties'] ?? [] ),
            'required' => (array) ( $definition['inputSchema']['required'] ?? [] ),
        ];
    }

    public function test_every_tool_file_is_registered_on_the_server(): void
    {
        $onDisk = collect( glob( app_path( 'Mcp/Tools/*.php' ) ) )
            ->map( fn ( string $path ): string => 'App\\Mcp\\Tools\\' . basename( $path, '.php' ) )
            ->sort()
            ->values()
            ->all();

        $registered = collect( $this->registeredToolClasses() )->sort()->values()->all();

        $this->assertSame(
            $onDisk,
            $registered,
            'Every class in app/Mcp/Tools must be registered in POSServer::$tools, otherwise it is dead code.'
        );
    }

    public function test_registered_tool_classes_are_all_loadable_tools(): void
    {
        foreach ( $this->registeredToolClasses() as $class ) {
            $this->assertTrue( class_exists( $class ), "{$class} is registered but does not exist." );
            $this->assertInstanceOf( Tool::class, app()->make( $class ), "{$class} must extend the MCP Tool class." );
        }
    }

    public function test_tool_names_are_unique_and_non_empty(): void
    {
        $names = $this->registeredToolNames();

        foreach ( $names as $name ) {
            $this->assertNotSame( '', $name, 'Every registered tool must expose a non-empty name.' );
        }

        $this->assertSame(
            count( $names ),
            count( array_unique( $names ) ),
            'Tool names must be unique: ' . implode( ', ', $names )
        );
    }

    public function test_instructions_mention_every_registered_tool(): void
    {
        $instructions = $this->instructions();

        foreach ( $this->registeredToolNames() as $name ) {
            $this->assertStringContainsString(
                $name,
                $instructions,
                "Tool '{$name}' is registered but never described in #[Instructions], so an agent will not know to use it."
            );
        }
    }

    public function test_instructions_do_not_advertise_tools_that_do_not_exist(): void
    {
        $instructions = $this->instructions();
        $registered = $this->registeredToolNames();

        foreach ( self::PHANTOM_TOOL_NAMES as $phantom ) {
            if ( in_array( $phantom, $registered, true ) ) {
                continue;
            }

            /**
             * Word boundaries matter here: create_product is a phantom, but
             * create_product_category is a real tool that contains it.
             */
            $this->assertDoesNotMatchRegularExpression(
                '/\b' . preg_quote( $phantom, '/' ) . '\b/',
                $instructions,
                "#[Instructions] advertises '{$phantom}' but no such tool is registered. An agent will call it and fail."
            );
        }
    }

    /**
     * Tools whose handle() refuses to run without an "id" must declare that
     * parameter, otherwise the calling agent has no way to discover it.
     */
    public function test_tools_requiring_an_id_declare_it_in_their_schema(): void
    {
        $checked = 0;

        foreach ( $this->registeredToolClasses() as $class ) {
            $source = file_get_contents( ( new ReflectionClass( $class ) )->getFileName() );

            if ( ! preg_match( '/request->get\(\s*[\'"]id[\'"]\s*\)/', $source ) ) {
                continue;
            }

            $schema = $this->inputSchemaOf( $class );
            $checked++;

            $this->assertArrayHasKey(
                'id',
                $schema['properties'],
                "{$class} reads an \"id\" parameter in handle() but does not declare it in schema()."
            );
        }

        $this->assertGreaterThan( 0, $checked, 'Expected at least one tool to require an id.' );
    }

    /**
     * The update tools error out without an id, so it must be advertised as
     * required rather than optional.
     */
    public function test_update_tools_declare_id_as_required(): void
    {
        $mustRequireId = [
            UpdateProductTool::class,
            UpdateCategoryTool::class,
            UpdateProductUnitQuantityTool::class,
        ];

        foreach ( $mustRequireId as $class ) {
            $schema = $this->inputSchemaOf( $class );

            $this->assertContains(
                'id',
                $schema['required'],
                "{$class} rejects calls without an id, so id must be listed as required in its schema."
            );
        }
    }
}
