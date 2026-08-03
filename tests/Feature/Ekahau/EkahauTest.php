<?php

use App\BaseURL;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function makeUploadedEsx(string $asName = 'sample.esx'): UploadedFile
{
    $temp = sys_get_temp_dir().'/ekahau-upload-'.uniqid().'.esx';
    copy(base_path('tests/Fixtures/ekahau/sample.esx'), $temp);

    return new UploadedFile(
        $temp,
        $asName,
        'application/zip',
        null,
        true,
    );
}

function makeInstallerExcelUpload(): UploadedFile
{
    $path = sys_get_temp_dir().'/ekahau-feature-'.uniqid().'.xlsx';
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Install');
    $sheet->fromArray([
        ['WAP location # on Drawing', 'New WAP Name', 'Site Bld Floor'],
        ['AP-A', 'FINAL-A', 'Floor 1'],
    ], null, 'A1');
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile($path, 'installer.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function makeBssidExcelUpload(): UploadedFile
{
    $path = sys_get_temp_dir().'/ekahau-bssid-'.uniqid().'.xlsx';
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['raw_mac', 'ap_name'],
        ['aa:bb:cc:dd:ab:cd', 'MATCHED-1'],
    ], null, 'A1');
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile($path, 'bssids.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('guests cannot view ekahau index', function () {
    auth()->logout();

    $this->get(route('ekahau.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can view ekahau index without a current client', function () {
    $this->get(route('ekahau.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ekahau/Index')
            ->where('has_current_client', false)
            ->where('site_options', []));
});

test('ekahau index includes site options when current client is set', function () {
    $client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    seedCentralScopeCache($client);

    $this->get(route('ekahau.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ekahau/Index')
            ->where('has_current_client', true)
            ->has('site_options', 1)
            ->where('site_options.0.siteId', 'scope-site')
            ->where('site_options.0.siteName', 'Central Site'));
});

test('rename ap validates required uploads', function () {
    $this->postJson(route('ekahau.rename-ap'), [])
        ->assertStatus(422);
});

test('rename ap returns downloadable result', function () {
    $response = $this->post(route('ekahau.rename-ap'), [
        'esx_file' => makeUploadedEsx(),
        'excel_file' => makeInstallerExcelUpload(),
        'sheet_name' => 'Install',
        'esx_ap_name' => 'WAP location # on Drawing',
        'ap_name' => 'New WAP Name',
        'site_name' => 'Site Bld Floor',
        'lowercase_ap_names' => false,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['results', 'download_url']);

    $downloadUrl = $response->json('download_url');
    $this->get($downloadUrl)->assertOk();
});

test('rename ap by mac returns downloadable result', function () {
    $response = $this->post(route('ekahau.rename-ap-by-mac'), [
        'esx_files' => [makeUploadedEsx()],
        'mapping_source' => 'bssid',
        'mapping_file' => makeBssidExcelUpload(),
        'lowercase_ap_names' => false,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['results', 'download_url']);
});

test('rename ap by mac from central requires a site id', function () {
    $client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    seedCentralScopeCache($client);

    $this->postJson(route('ekahau.rename-ap-by-mac'), [
        'esx_files' => [makeUploadedEsx()],
        'mapping_source' => 'central',
    ])->assertStatus(422);
});

test('rename ap by mac from central requires a current client', function () {
    $this->postJson(route('ekahau.rename-ap-by-mac'), [
        'esx_files' => [makeUploadedEsx()],
        'mapping_source' => 'central',
        'site_id' => 'scope-site',
    ])
        ->assertStatus(422)
        ->assertJson([
            'message' => 'Please set current client to pull BSSIDs from Central.',
        ]);
});

test('rename ap by mac from central returns downloadable result', function () {
    $client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    seedCentralScopeCache($client);

    Http::fake([
        '*network-monitoring/v1/bssids*' => Http::response([
            'items' => [
                [
                    'deviceName' => 'MATCHED-1',
                    'bssid' => 'aa:bb:cc:dd:ab:cd',
                ],
                [
                    'deviceName' => 'MATCHED-2',
                    'bssid' => '11:22:33:44:11:22',
                ],
            ],
            'total' => 2,
            'next' => null,
        ], 200),
    ]);

    $response = $this->post(route('ekahau.rename-ap-by-mac'), [
        'esx_files' => [makeUploadedEsx()],
        'mapping_source' => 'central',
        'site_id' => 'scope-site',
        'site_name' => 'Central Site',
        'lowercase_ap_names' => false,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['results', 'download_url']);

    $this->get($response->json('download_url'))->assertOk();
});

test('export aps returns downloadable xlsx', function () {
    $response = $this->post(route('ekahau.export-aps'), [
        'esx_files' => [makeUploadedEsx()],
        'prefix_mode' => 'none',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['results', 'download_url']);

    $this->get($response->json('download_url'))->assertOk();
});

test('prefix ap returns downloadable esx', function () {
    $response = $this->post(route('ekahau.prefix-ap'), [
        'esx_files' => [makeUploadedEsx()],
        'prefix_mode' => 'flat',
        'ap_name_prefix' => 'HQ-',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['results', 'download_url']);
});

test('prefix ap requires a prefix value', function () {
    $this->postJson(route('ekahau.prefix-ap'), [
        'esx_files' => [makeUploadedEsx()],
        'prefix_mode' => 'flat',
        'ap_name_prefix' => '',
    ])->assertStatus(422);
});
