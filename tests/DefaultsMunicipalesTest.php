<?php

use Illuminate\Support\Facades\Http;
use Muni\OpenRouter\Facades\OpenRouter;
use Muni\OpenRouter\OpenRouterException;

/**
 * Los defectos que hereda el primer sistema que instale esto.
 *
 * Hoy ningún consumidor llama al cliente, así que nada de esto está pasando. El
 * problema es justamente ése: los defaults son lo que va a heredar la primera
 * feature de IA de un sistema municipal —solicitudes, contenido escrito por
 * vecinos— si nadie los cambia a mano. Y nadie los cambia a mano.
 */
beforeEach(function () {
    config()->set('openrouter.api_key', 'clave-de-prueba');
});

it('por omisión pide que el proveedor no se quede con el prompt', function () {
    // `exclude_logging` venía en false. Sin él, el cuerpo sale SIN
    // `provider.data_collection=deny`, y el modelo por defecto es `:free`: según
    // la documentación de OpenRouter, los proveedores gratuitos son justamente
    // los que entrenan con el prompt. O sea, el default es el caso más expuesto.
    expect(config('openrouter.exclude_logging'))->toBeTrue(
        'el default deja que el proveedor gratuito se quede con el prompt del vecino'
    );
});

it('el cuerpo lleva la política de datos con la configuración por omisión', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'hola']]]])]);

    OpenRouter::chat([['role' => 'user', 'content' => 'hola']]);

    Http::assertSent(function ($peticion) {
        $cuerpo = $peticion->data();

        return ($cuerpo['provider']['data_collection'] ?? null) === 'deny'
            && ($cuerpo['provider']['zdr'] ?? null) === true;
    });
});

it('conserva el bloque provider que mande quien llama', function () {
    // La fusión no puede pisar lo que el llamador puso a propósito: si alguien
    // fija un `order` de proveedores, se respeta y solo se le añade la política.
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

    OpenRouter::chat(
        [['role' => 'user', 'content' => 'hola']],
        ['provider' => ['order' => ['proveedor-x']]]
    );

    Http::assertSent(function ($peticion) {
        $provider = $peticion->data()['provider'] ?? [];

        return ($provider['order'] ?? null) === ['proveedor-x']
            && ($provider['data_collection'] ?? null) === 'deny';
    });
});

it('cae al modelo pago cuando ningún proveedor cumple la política', function () {
    // El caso que rompía la feature en silencio: con `exclude_logging=true`,
    // OpenRouter FALLA la petición si ningún proveedor gratuito cumple la
    // política —y esa falla NO es un 429—, así que el fallback al modelo pago no
    // se disparaba nunca. La feature moría con una excepción sin explicación,
    // que es el peor sitio donde descubrir esto: en producción y sin pista.
    config()->set('openrouter.fallback_model', 'anthropic/claude-3.5-sonnet');

    $llamadas = 0;

    Http::fake(function ($peticion) use (&$llamadas) {
        $llamadas++;

        if ($llamadas === 1) {
            return Http::response([
                'error' => ['message' => 'No endpoints found that meet your data policy'],
            ], 404);
        }

        return Http::response(['choices' => [['message' => ['content' => 'respondió el pago']]]]);
    });

    expect(OpenRouter::chat([['role' => 'user', 'content' => 'hola']]))->toBe('respondió el pago')
        ->and($llamadas)->toBe(2);
});

it('un 404 que NO es de política no gasta el modelo pago', function () {
    // Contraprueba: si cualquier 404 disparara el fallback, un modelo mal
    // escrito costaría dinero en cada llamada en vez de fallar y avisar.
    config()->set('openrouter.fallback_model', 'anthropic/claude-3.5-sonnet');

    $llamadas = 0;

    Http::fake(function () use (&$llamadas) {
        $llamadas++;

        return Http::response(['error' => ['message' => 'No such model']], 404);
    });

    expect(fn () => OpenRouter::chat([['role' => 'user', 'content' => 'hola']]))
        ->toThrow(OpenRouterException::class);

    expect($llamadas)->toBe(1);
});

it('manda un tope de tokens por omisión', function () {
    // Sin tope, una respuesta del modelo pago es coste sin límite. El tope va
    // POR DEBAJO de lo que mande quien llama: es un piso de seguridad, no una
    // decisión que le quite el control al consumidor.
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

    OpenRouter::chat([['role' => 'user', 'content' => 'hola']]);

    Http::assertSent(fn ($p) => isset($p->data()['max_tokens']));
});

it('quien llama puede subir el tope', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

    OpenRouter::chat([['role' => 'user', 'content' => 'hola']], ['max_tokens' => 4096]);

    Http::assertSent(fn ($p) => ($p->data()['max_tokens'] ?? null) === 4096);
});

it('un cuerpo que no es JSON da una excepción clara, no un error raro más abajo', function () {
    Http::fake(['*' => Http::response('<html>error del proxy</html>', 200)]);

    expect(fn () => OpenRouter::raw([['role' => 'user', 'content' => 'hola']]))
        ->toThrow(OpenRouterException::class);
});

it('una respuesta sin contenido lanza una excepción clara, no un índice indefinido', function () {
    // Lanzar es mejor que devolver cadena vacía: una respuesta vacía del modelo
    // y una llamada que no llegó a responder son cosas distintas, y quien llama
    // tiene que poder distinguirlas.
    Http::fake(['*' => Http::response(['choices' => []])]);

    expect(fn () => OpenRouter::chat([['role' => 'user', 'content' => 'hola']]))
        ->toThrow(OpenRouterException::class);
});
