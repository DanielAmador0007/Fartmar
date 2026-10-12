<?php

use App\Domain\Patients\PatientMasker;

it('enmascara el documento dejando solo los últimos 4 y sin revelar el largo', function (string $document, string $expected) {
    expect(PatientMasker::document($document))->toBe($expected);
})->with([
    'cédula de 10' => ['1032456789', '******6789'],
    'cédula de 6' => ['123456', '******3456'],
    'pasaporte alfanumérico' => ['AB123456', '******3456'],
    'espacios alrededor' => ['  98765432 ', '******5432'],
    '4 caracteres: no se muestra nada' => ['1234', '******'],
    'vacío' => ['', '******'],
]);

it('enmascara nombres: primera letra de cada palabra + ***', function (string $name, string $expected) {
    expect(PatientMasker::name($name))->toBe($expected);
})->with([
    'nombre simple' => ['Juan', 'J***'],
    'nombre compuesto' => ['Juan Pablo', 'J*** P***'],
    'tildes y eñe' => ['Ñusta Álvarez', 'Ñ*** Á***'],
    'minúsculas' => ['maría josé', 'M*** J***'],
    'espacios repetidos' => ['  Ana   María ', 'A*** M***'],
    'vacío' => ['', ''],
]);
