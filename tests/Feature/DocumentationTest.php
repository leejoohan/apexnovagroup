<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_home_page_points_to_api_documentation(): void
    {
        $this->get('/')->assertRedirect('/docs');
    }

    public function test_interactive_documentation_is_publicly_available(): void
    {
        $response = $this->get('/docs');

        $response->assertOk()
            ->assertSee('Product Inventory API')
            ->assertSee('/openapi.yaml')
            ->assertSee('swagger-ui-dist@5.17.14');
    }

    public function test_openapi_specification_is_publicly_available_as_yaml(): void
    {
        $response = $this->get('/openapi.yaml');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/yaml')
            ->assertSee('openapi: 3.0.3', false)
            ->assertSee('/api/v1/products:', false);
    }
}
