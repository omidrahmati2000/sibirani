import SwaggerUI from 'swagger-ui-dist/swagger-ui-es-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

SwaggerUI({
    dom_id: '#swagger-ui',
    deepLinking: true,
    layout: 'BaseLayout',
    url: document.getElementById('swagger-ui').dataset.specUrl,
});
