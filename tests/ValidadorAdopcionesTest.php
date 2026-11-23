<?php

use PHPUnit\Framework\TestCase;

// Validación de los cuerpos del circuito (contrato-adopciones.md). El circuito completo, contra la
// base, lo prueba 09_adopciones.sh del Gateway.
final class ValidadorAdopcionesTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testUnaPublicacionCompletaEsValida(): void
    {
        if (!class_exists('finfo')) {
            $this->markTestSkipped('Falta la extensión fileinfo (en el CI y en Docker está)');
        }

        [$datos, $errores] = ValidadorAdopciones::publicacion([
            'mascota_id' => 901,
            'titulo' => '  Canela busca familia ',
            'descripcion' => 'Perra de un año, mansa y juguetona.',
            'foto' => self::PNG,
        ]);

        $this->assertSame([], $errores);
        $this->assertSame('Canela busca familia', $datos['titulo']);
        $this->assertSame('image/png', $datos['foto'][1]);
    }

    public function testLaPublicacionMarcaCadaCampoMal(): void
    {
        [, $errores] = ValidadorAdopciones::publicacion([
            'mascota_id' => '901',
            'titulo' => 'ab',
            'descripcion' => str_repeat('x', 2001),
        ]);

        $this->assertSame(['mascota_id', 'titulo', 'descripcion', 'foto'], array_keys($errores));
    }

    public function testAlEditarSoloSeValidaLoQueViene(): void
    {
        [$datos, $errores] = ValidadorAdopciones::edicion(['titulo' => ' Canela, lista para su familia ']);
        $this->assertSame([], $errores);
        $this->assertSame(['titulo' => 'Canela, lista para su familia'], $datos);

        [, $errores] = ValidadorAdopciones::edicion(['descripcion' => 'corta']);
        $this->assertSame(['descripcion'], array_keys($errores));

        // Una foto vacía o null no cambia la foto: es lo mismo que no mandarla.
        [, $errores] = ValidadorAdopciones::edicion(['foto' => null]);
        $this->assertArrayHasKey('titulo', $errores);
    }

    public function testEditarSinNingunCampoDa400(): void
    {
        [, $errores] = ValidadorAdopciones::edicion(['mascota_id' => 901]);

        $this->assertSame(['titulo'], array_keys($errores));
    }

    public function testElMotivoDelRechazoTieneLargoMinimoYMaximo(): void
    {
        $this->assertArrayHasKey('motivo', ValidadorAdopciones::motivo(['motivo' => ' '])[1]);
        $this->assertArrayHasKey('motivo', ValidadorAdopciones::motivo(['motivo' => str_repeat('x', 256)])[1]);
        $this->assertSame([], ValidadorAdopciones::motivo(['motivo' => 'La foto no se ve bien'])[1]);
    }

    public function testLaPostulacionPideLosTresCampos(): void
    {
        [$datos, $errores] = ValidadorAdopciones::postulacion([
            'tipo_vivienda' => 'Casa con patio',
            'otras_mascotas' => 'Ninguna',
            'tiempo_disponible' => 'Toda la tarde',
        ]);
        $this->assertSame([], $errores);
        $this->assertSame('Ninguna', $datos['otras_mascotas']);

        [, $errores] = ValidadorAdopciones::postulacion(['tipo_vivienda' => 'x', 'otras_mascotas' => 3]);
        $this->assertSame(['tipo_vivienda', 'otras_mascotas', 'tiempo_disponible'], array_keys($errores));
    }

    public function testLosIdsTienenQueSerEnterosDeVerdad(): void
    {
        $this->assertSame(101, ValidadorAdopciones::id(['postulacion_id' => 101], 'postulacion_id'));
        $this->assertNull(ValidadorAdopciones::id(['postulacion_id' => '101'], 'postulacion_id'));
        $this->assertNull(ValidadorAdopciones::id(['postulacion_id' => 0], 'postulacion_id'));
        $this->assertNull(ValidadorAdopciones::id(['postulacion_id' => 1.5], 'postulacion_id'));
        $this->assertNull(ValidadorAdopciones::id([], 'turno_id'));
    }

    public function testElListadoSinFiltrosDevuelveLaPrimeraPaginaDeCien(): void
    {
        [$pedido, $errores] = ValidadorAdopciones::listado([]);

        $this->assertSame([], $errores);
        $this->assertSame(['estado' => null, 'filtros' => [], 'pagina' => 1, 'por_pagina' => 100], $pedido);
    }

    public function testElListadoAceptaFechasBusquedaYPagina(): void
    {
        [$pedido, $errores] = ValidadorAdopciones::listado([
            'estado' => 'FINALIZADA', 'desde' => '2026-10-20', 'hasta' => '2026-11-19',
            'buscar' => '  Simba ', 'pagina' => '3', 'por_pagina' => '20',
        ]);

        $this->assertSame([], $errores);
        $this->assertSame('FINALIZADA', $pedido['estado']);
        $this->assertSame(['desde' => '2026-10-20', 'hasta' => '2026-11-19', 'buscar' => 'Simba'], $pedido['filtros']);
        $this->assertSame([3, 20], [$pedido['pagina'], $pedido['por_pagina']]);
    }

    public function testElListadoMarcaCadaFiltroMal(): void
    {
        [, $errores] = ValidadorAdopciones::listado([
            'estado' => 'ADOPTADA', 'desde' => '2026-02-30', 'hasta' => '19/11/2026',
            'buscar' => str_repeat('x', 61), 'pagina' => '0', 'por_pagina' => '101',
        ]);

        $this->assertSame(['estado', 'desde', 'hasta', 'buscar', 'pagina', 'por_pagina'], array_keys($errores));
    }

    public function testHastaNoPuedeSerAnteriorADesde(): void
    {
        [, $errores] = ValidadorAdopciones::listado(['desde' => '2026-11-19', 'hasta' => '2026-11-01']);

        $this->assertSame(['hasta'], array_keys($errores));
    }

    public function testElListadoAceptaLosTresOrdenesYRechazaOtro(): void
    {
        foreach (['prioridad', 'recientes', 'antiguas'] as $orden) {
            [$pedido, $errores] = ValidadorAdopciones::listado(['orden' => $orden]);
            $this->assertSame([], $errores);
            $this->assertSame($orden, $pedido['filtros']['orden']);
        }

        [, $errores] = ValidadorAdopciones::listado(['orden' => 'al azar']);
        $this->assertArrayHasKey('orden', $errores);

        [$pedido, $errores] = ValidadorAdopciones::listado([]);
        $this->assertSame([], $errores);
        $this->assertArrayNotHasKey('orden', $pedido['filtros']);
    }
}
