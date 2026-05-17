<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Player;
use App\Models\User;
use App\Services\PlayerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

class PlayerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_page_renders(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->get('/players/import');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('players/import')
            ->where('preview', null)
        );
    }

    public function test_csv_preview_returns_parsed_rows_with_normalised_values(): void
    {
        $admin = User::factory()->create();

        $csv = "Name,Arabic name,Club,Position,Height,Weight,National ID,Birth year\n"
            ."Ahmad Naji,أحمد ناجي,Al-Riffa,Left wing,170cm,57.7 KG,070811709,2007\n"
            ."Badr Husain,بدر حسين,Al-Riffa,Centre back,184,88.5 Kg,070500398,2007\n";

        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv);

        $response = $this->actingAs($admin)->post('/players/import/preview', [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('players/import')
            ->where('preview.summary.total', 2)
            ->where('preview.summary.valid', 2)
            ->where('preview.rows.0.normalized.full_name', 'Ahmad Naji')
            ->where('preview.rows.0.normalized.name_ar', 'أحمد ناجي')
            ->where('preview.rows.0.normalized.height_cm', 170)
            ->where('preview.rows.0.normalized.weight_kg', 57.7)
            ->where('preview.rows.0.normalized.date_of_birth', '2007-01-01')
            ->where('preview.rows.0.normalized.player_code', '070811709')
            ->where('preview.rows.1.normalized.height_cm', 184)
            ->where('preview.rows.1.normalized.weight_kg', 88.5)
        );
    }

    public function test_arabic_only_name_is_mirrored_into_full_name(): void
    {
        $admin = User::factory()->create();

        $csv = "الاسم,النادي,المركز\n"
            ."أحمد ناجي,الرفاع,Left wing\n";

        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv);

        $response = $this->actingAs($admin)->post('/players/import/preview', [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('preview.rows.0.normalized.full_name', 'أحمد ناجي')
            ->where('preview.rows.0.normalized.name_ar', 'أحمد ناجي')
            ->where('preview.summary.valid', 1)
        );
    }

    public function test_invalid_height_is_flagged_with_field_error(): void
    {
        $admin = User::factory()->create();

        $csv = "Name,Height\nAhmad,2cm\n";

        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv);

        $response = $this->actingAs($admin)->post('/players/import/preview', [
            'file' => $file,
        ]);

        $response->assertInertia(fn ($page) => $page
            ->where('preview.summary.valid', 0)
            ->where('preview.summary.invalid', 1)
            ->has('preview.rows.0.errors.height_cm')
        );
    }

    public function test_duplicate_player_code_within_file_is_flagged(): void
    {
        $admin = User::factory()->create();

        $csv = "Name,National ID\nAhmad,070811709\nBadr,070811709\n";

        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv);

        $response = $this->actingAs($admin)->post('/players/import/preview', [
            'file' => $file,
        ]);

        $response->assertInertia(fn ($page) => $page
            ->where('preview.summary.valid', 1)
            ->where('preview.summary.invalid', 1)
            ->where('preview.rows.1.duplicate_of_row', 2)
        );
    }

    public function test_duplicate_against_existing_player_is_flagged(): void
    {
        $admin = User::factory()->create();
        Player::factory()->create(['player_code' => '070811709']);

        $csv = "Name,National ID\nAhmad,070811709\n";

        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv);

        $response = $this->actingAs($admin)->post('/players/import/preview', [
            'file' => $file,
        ]);

        $response->assertInertia(fn ($page) => $page
            ->where('preview.summary.invalid', 1)
            ->has('preview.rows.0.errors.player_code')
        );
    }

    public function test_store_creates_only_rows_with_no_errors(): void
    {
        $admin = User::factory()->create();

        $rows = [
            [
                'normalized' => [
                    'full_name' => 'Ahmad Naji',
                    'name_ar' => 'أحمد ناجي',
                    'club' => 'Al-Riffa',
                    'position' => 'Left wing',
                    'date_of_birth' => '2007-01-01',
                    'nationality' => null,
                    'height_cm' => 170,
                    'weight_kg' => 57.7,
                    'preferred_foot' => null,
                    'player_code' => '070811709',
                    'phone' => null,
                    'email' => null,
                    'status' => 'active',
                ],
                'errors' => [],
            ],
            [
                'normalized' => [
                    'full_name' => 'Broken Row',
                    'height_cm' => 999,
                ],
                'errors' => ['height_cm' => ['Out of range']],
            ],
        ];

        $response = $this->actingAs($admin)->post('/players/import', [
            'rows' => $rows,
        ]);

        $response->assertRedirect(route('players.index'));

        $this->assertSame(1, Player::count());
        $this->assertDatabaseHas('players', [
            'full_name' => 'Ahmad Naji',
            'player_code' => '070811709',
            'height_cm' => 170,
        ]);
        $this->assertDatabaseMissing('players', [
            'full_name' => 'Broken Row',
        ]);
    }

    public function test_xlsx_file_is_parsed_using_real_openspout(): void
    {
        $admin = User::factory()->create();

        $tmp = tempnam(sys_get_temp_dir(), 'roster_').'.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues(['Name', 'Arabic name', 'Club', 'Height', 'Weight', 'National ID']));
        $writer->addRow(Row::fromValues(['Ahmad Naji', 'أحمد ناجي', 'Al-Riffa', '170cm', '57.7 KG', '070811709']));
        $writer->close();

        $file = new UploadedFile($tmp, 'roster.xlsx', null, null, true);

        $response = $this->actingAs($admin)->post('/players/import/preview', [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('preview.summary.total', 1)
            ->where('preview.summary.valid', 1)
            ->where('preview.rows.0.normalized.full_name', 'Ahmad Naji')
            ->where('preview.rows.0.normalized.height_cm', 170)
            ->where('preview.rows.0.normalized.weight_kg', 57.7)
        );

        @unlink($tmp);
    }

    public function test_rejects_non_spreadsheet_file_type(): void
    {
        $admin = User::factory()->create();

        $file = UploadedFile::fake()->create('roster.pdf', 10, 'application/pdf');

        $response = $this->actingAs($admin)
            ->from('/players/import')
            ->post('/players/import/preview', ['file' => $file]);

        $response->assertSessionHasErrors('file');
    }

    public function test_metric_height_in_metres_is_converted_to_cm(): void
    {
        $service = new PlayerImportService();
        $csv = "Name,Height\nAhmad,1.78m\n";
        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv);

        $preview = $service->parse($file);

        $this->assertSame(178, $preview['rows'][0]['normalized']['height_cm']);
    }
}
