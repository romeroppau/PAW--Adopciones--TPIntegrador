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
}
