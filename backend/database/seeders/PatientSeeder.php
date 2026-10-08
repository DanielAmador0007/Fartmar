<?php

namespace Database\Seeders;

use App\Domain\Patients\DocumentHasher;
use App\Enums\PrescriptionStatus;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * 3 pacientes SINTÉTICOS (nombres y documentos inventados, prefijo 99...) con
 * prescripciones vigentes; RX-DEMO-0003 incluye Morfina (control especial).
 *
 * Idempotencia: pacientes por document_hash, prescripciones por número,
 * líneas por (prescripción, producto). quantity_dispensed no se reinicia al
 * re-ejecutar (firstOrCreate), para no romper lo ya dispensado en la demo.
 */
class PatientSeeder extends Seeder
{
    public function run(): void
    {
        $prescriber = User::query()->where('email', 'medico@fartmar.test')->firstOrFail();
        $products = Product::query()->pluck('id', 'code');
        $today = CarbonImmutable::now(config('fartmar.business_timezone'))->startOfDay();

        $patients = [
            [
                'document' => ['CC', '9900000001'],
                'first_name' => 'Ana',
                'last_name' => 'Ficticia Demo',
                'birth_date' => '1985-03-14',
                'phone' => '3000000001',
                'prescription' => ['RX-DEMO-0001', ['ACE500' => 20, 'OME20' => 14]],
            ],
            [
                'document' => ['CC', '9900000002'],
                'first_name' => 'Carlos',
                'last_name' => 'Sintético Prueba',
                'birth_date' => '1972-11-02',
                'phone' => '3000000002',
                'prescription' => ['RX-DEMO-0002', ['AMX500' => 21, 'IBU400' => 10]],
            ],
            [
                'document' => ['CC', '9900000003'],
                'first_name' => 'Lucía',
                'last_name' => 'Inventada Ejemplo',
                'birth_date' => '1990-07-28',
                'phone' => '3000000003',
                'prescription' => ['RX-DEMO-0003', ['MOR10' => 5, 'LOS50' => 30]],
            ],
        ];

        foreach ($patients as $data) {
            [$documentType, $documentNumber] = $data['document'];

            $patient = Patient::query()->updateOrCreate(
                ['document_hash' => DocumentHasher::hash($documentType, $documentNumber)],
                [
                    'document_type' => $documentType,
                    'document_number' => $documentNumber,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'birth_date' => $data['birth_date'],
                    'phone' => $data['phone'],
                ],
            );

            [$number, $items] = $data['prescription'];

            // Vigente durante 30 días desde la última ejecución del seeder.
            $prescription = Prescription::query()->updateOrCreate(['number' => $number], [
                'patient_id' => $patient->id,
                'prescriber_id' => $prescriber->id,
                'issued_at' => $today->subDays(2),
                'valid_until' => $today->addDays(30)->toDateString(),
                'status' => PrescriptionStatus::Activa,
            ]);

            foreach ($items as $productCode => $quantity) {
                PrescriptionItem::query()->firstOrCreate(
                    ['prescription_id' => $prescription->id, 'product_id' => $products[$productCode]],
                    ['quantity_prescribed' => $quantity, 'quantity_dispensed' => 0, 'instructions' => 'Según indicación médica'],
                );
            }
        }
    }
}
