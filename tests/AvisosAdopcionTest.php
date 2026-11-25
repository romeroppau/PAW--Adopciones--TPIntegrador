<?php

use PHPUnit\Framework\TestCase;

// Los avisos del circuito (contrato-adopciones.md, sección 8): a quién, de qué tipo y con qué
// id_operacion. Que lleguen a la campana lo prueba 09_adopciones.sh del Gateway.
final class AvisosAdopcionTest extends TestCase
{
    public function testUnaPublicacionNuevaLeAvisaACadaVeterinaria(): void
    {
        $aviso = AvisosAdopcion::publicacionNueva(55, 101, 'Rescatista Aprobada', 'Canela');

        $this->assertSame(101, $aviso['usuario_id']);
        $this->assertSame('WEB', $aviso['canal']);
        $this->assertSame('publicacion-nueva-55-vet-101', $aviso['id_operacion']);
        $this->assertStringContainsString('Rescatista Aprobada publicó a Canela para adopción', $aviso['mensaje']);
    }

    public function testModerarLeAvisaALaRescatistaConElMotivoSiSeRechaza(): void
    {
        $aprobada = AvisosAdopcion::publicacionModerada(55, 104, 'Canela', null, '20261123170000');
        $this->assertSame(104, $aprobada['usuario_id']);
        $this->assertSame('publicacion-55-aprobada', $aprobada['id_operacion']);
        $this->assertStringContainsString('Aprobamos la publicación de Canela', $aprobada['mensaje']);

        $rechazada = AvisosAdopcion::publicacionModerada(56, 104, 'Rocco', 'La foto no se ve bien', '20261123170000');
        $this->assertSame('publicacion-56-rechazada-20261123170000', $rechazada['id_operacion']);
        $this->assertStringContainsString('No aprobamos la publicación de Rocco. Motivo: La foto no se ve bien', $rechazada['mensaje']);
    }

    public function testLaPostulacionNuevaLeAvisaALaRescatista(): void
    {
        $aviso = AvisosAdopcion::postulacionNueva(7, 104, 'Cliente Dos', 'Pipa');

        $this->assertSame(104, $aviso['usuario_id']);
        $this->assertSame('POSTULACION', $aviso['tipo']);
        $this->assertSame('WEB', $aviso['canal']);
        $this->assertSame('postulacion-nueva-7', $aviso['id_operacion']);
        $this->assertStringContainsString('Cliente Dos se postuló para adoptar a Pipa', $aviso['mensaje']);
    }

    public function testCancelarLaPostulacionLeAvisaALaRescatista(): void
    {
        $aviso = AvisosAdopcion::postulacionCancelada(7, 104, 'Cliente Dos', 'Pipa', '20261124183000');

        $this->assertSame(104, $aviso['usuario_id']);
        $this->assertSame('POSTULACION', $aviso['tipo']);
        $this->assertSame('postulacion-cancelada-7-20261124183000', $aviso['id_operacion']);
        $this->assertSame('Cliente Dos canceló su postulación para adoptar a Pipa.', $aviso['mensaje']);
    }

    public function testSiVuelveAPostularseElAvisoTieneOtroIdOperacion(): void
    {
        $this->assertSame('postulacion-nueva-7', AvisosAdopcion::postulacionNueva(7, 104, 'Cliente Dos', 'Pipa')['id_operacion']);
        $this->assertSame(
            'postulacion-nueva-7-20261124190000',
            AvisosAdopcion::postulacionNueva(7, 104, 'Cliente Dos', 'Pipa', '20261124190000')['id_operacion']
        );
    }

    public function testElegirLeAvisaAlClienteElegido(): void
    {
        $aviso = AvisosAdopcion::postulacionElegida(7, 103, 'Pipa');

        $this->assertSame(103, $aviso['usuario_id']);
        $this->assertSame('POSTULACION', $aviso['tipo']);
        $this->assertSame('postulacion-elegida-7', $aviso['id_operacion']);
        $this->assertStringContainsString('Te eligieron para adoptar a Pipa', $aviso['mensaje']);
    }

    public function testElTurnoDelChequeoLeAvisaAlAdoptanteYALaRescatistaPorLosDosCanales(): void
    {
        $avisos = AvisosAdopcion::chequeoAsignado(101, 120, 103, 104, 'Cliente Dos', 'Pipa', '2026-11-26', '10:00:00');

        $this->assertCount(4, $avisos);
        $porId = array_column($avisos, null, 'id_operacion');
        $this->assertSame(
            ['chequeo-101-120-adoptante-web', 'chequeo-101-120-rescatista-web', 'chequeo-101-120-adoptante-whatsapp', 'chequeo-101-120-rescatista-whatsapp'],
            array_keys($porId)
        );

        $adoptante = $porId['chequeo-101-120-adoptante-whatsapp'];
        $this->assertSame(103, $adoptante['usuario_id']);
        $this->assertSame('WHATSAPP', $adoptante['canal']);
        $this->assertStringContainsString('chequeo de adopción de Pipa el jueves 26/11 a las 10:00', $adoptante['mensaje']);

        $rescatista = $porId['chequeo-101-120-rescatista-web'];
        $this->assertSame(104, $rescatista['usuario_id']);
        $this->assertSame('WEB', $rescatista['canal']);
        $this->assertStringContainsString('Cliente Dos tiene turno para el chequeo de adopción de Pipa', $rescatista['mensaje']);

        foreach ($avisos as $aviso) {
            $this->assertSame('POSTULACION', $aviso['tipo']);
            $this->assertStringNotContainsString("\n", $aviso['mensaje']);   // WhatsApp: un solo renglón
        }
    }

    public function testCompletarLeAvisaAlAdoptante(): void
    {
        $aviso = AvisosAdopcion::adopcionFinalizada(101, 103, 'Pipa');

        $this->assertSame(103, $aviso['usuario_id']);
        $this->assertSame('CONTROL_ADOPCION', $aviso['tipo']);
        $this->assertSame('adopcion-finalizada-101', $aviso['id_operacion']);
    }

    public function testElAvisoCumpleLoQuePideBackend(): void
    {
        // Mensaje de hasta 500 caracteres y fecha AAAA-MM-DD HH:MM:SS, aunque el nombre sea muy largo.
        $aviso = AvisosAdopcion::postulacionNueva(1, 2, str_repeat('Nombre muy largo ', 40), 'Pipa');

        $this->assertLessThanOrEqual(500, mb_strlen($aviso['mensaje']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $aviso['fecha_programada']);
        $this->assertSame(['usuario_id', 'tipo', 'canal', 'mensaje', 'fecha_programada', 'id_operacion'], array_keys($aviso));
    }

    public function testSiBackendNoRespondeElAvisoNoCortaNada(): void
    {
        // Sin INTERNAL_TOKEN ni Backend: enviar() devuelve false y no lanza nada.
        $anterior = getenv('INTERNAL_TOKEN');
        putenv('INTERNAL_TOKEN');

        $this->assertFalse(AvisosAdopcion::enviar(AvisosAdopcion::adopcionFinalizada(1, 2, 'Pipa')));

        if ($anterior !== false) {
            putenv("INTERNAL_TOKEN=$anterior");
        }
    }
}
