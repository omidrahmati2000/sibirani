<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocumentationTest extends TestCase
{
    public function test_swagger_ui_is_served_locally(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('swagger-ui')
            ->assertSee('/docs/openapi.yaml');
    }

    public function test_openapi_document_is_served_locally(): void
    {
        $this->get('/docs/openapi.yaml')
            ->assertOk()
            ->assertSee('openapi: 3.0.3')
            ->assertSee('/api/orders');
    }
}
