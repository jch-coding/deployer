<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Services\CentralScopeCacheService;
use App\Services\DeviceCentralFilterBuilder;
use App\Services\Ekahau\EkahauWorkspace;
use App\Services\Ekahau\ExportEkahauApsService;
use App\Services\Ekahau\PrefixEsxApService;
use App\Services\Ekahau\RenameEsxApByMacService;
use App\Services\Ekahau\RenameEsxApService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EkahauController extends Controller
{
    public function index(Request $request, CentralScopeCacheService $centralScopeCacheService): Response
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return Inertia::render('Ekahau/Index', [
                'site_options' => [],
                'has_current_client' => false,
                'central_sites_cache' => [
                    'refreshed_at' => null,
                    'error' => null,
                ],
                'central_groups_cache' => [
                    'refreshed_at' => null,
                    'error' => null,
                    'classic_error' => null,
                ],
            ]);
        }

        return Inertia::render('Ekahau/Index', [
            'site_options' => $centralScopeCacheService->getSiteOptions($currentClient),
            'has_current_client' => true,
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }

    public function renameAp(
        Request $request,
        EkahauWorkspace $workspace,
        RenameEsxApService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'esx_file' => ['required', 'file', 'max:102400'],
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
            'sheet_name' => ['required', 'string', 'max:255'],
            'esx_ap_name' => ['required', 'string', 'max:255'],
            'ap_name' => ['required', 'string', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'lowercase_ap_names' => ['sometimes', 'boolean'],
        ]);

        $this->assertEsxUpload($validated['esx_file']);

        $dir = $workspace->create();
        try {
            $esxPath = $workspace->storeUpload($validated['esx_file'], $dir);
            $excelPath = $workspace->storeUpload($validated['excel_file'], $dir);
            $outputDir = $dir.'/output';
            mkdir($outputDir, 0755, true);

            $outcome = $service->rename(
                $esxPath,
                $excelPath,
                $validated['sheet_name'],
                $validated['esx_ap_name'],
                $validated['ap_name'],
                $validated['site_name'] ?? '',
                (bool) ($validated['lowercase_ap_names'] ?? false),
                $outputDir,
            );

            $token = $workspace->registerDownload(
                $dir,
                [$outcome['output_path']],
                $outcome['results'],
                'renamed.esx',
            );

            return response()->json([
                'results' => $outcome['results'],
                'download_url' => route('ekahau.download', ['token' => $token]),
            ]);
        } catch (\Throwable $e) {
            $workspace->cleanup($dir);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function renameApByMac(
        Request $request,
        EkahauWorkspace $workspace,
        RenameEsxApByMacService $service,
        DeviceCentralFilterBuilder $filterBuilder,
    ): JsonResponse {
        $validated = $request->validate([
            'esx_files' => ['required', 'array', 'min:1'],
            'esx_files.*' => ['required', 'file', 'max:102400'],
            'mapping_source' => ['required', Rule::in(['bssid', 'csv', 'excel', 'central'])],
            'mapping_file' => ['required_unless:mapping_source,central', 'nullable', 'file', 'max:20480'],
            'site_id' => ['required_if:mapping_source,central', 'nullable', 'string', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'ap_mac' => ['nullable', 'string', 'max:255'],
            'ap_name' => ['nullable', 'string', 'max:255'],
            'lowercase_ap_names' => ['sometimes', 'boolean'],
        ]);

        foreach ($validated['esx_files'] as $esxFile) {
            $this->assertEsxUpload($esxFile);
        }

        $mappingSource = $validated['mapping_source'];
        $lowercase = (bool) ($validated['lowercase_ap_names'] ?? false);

        if ($mappingSource === 'central') {
            return $this->renameApByMacFromCentral(
                $request,
                $workspace,
                $service,
                $filterBuilder,
                $validated,
                $lowercase,
            );
        }

        $macColumn = 'raw_mac';
        $nameColumn = 'ap_name';
        $sheetName = null;

        if ($mappingSource === 'csv') {
            $request->validate([
                'ap_mac' => ['required', 'string', 'max:255'],
                'ap_name' => ['required', 'string', 'max:255'],
            ]);
            $macColumn = $validated['ap_mac'] ?? '';
            $nameColumn = $validated['ap_name'] ?? '';
        } elseif ($mappingSource === 'excel') {
            $request->validate([
                'sheet_name' => ['required', 'string', 'max:255'],
                'ap_mac' => ['required', 'string', 'max:255'],
                'ap_name' => ['required', 'string', 'max:255'],
            ]);
            $sheetName = $validated['sheet_name'] ?? null;
            $macColumn = $validated['ap_mac'] ?? '';
            $nameColumn = $validated['ap_name'] ?? '';
        }

        $dir = $workspace->create();
        try {
            $esxPaths = $workspace->storeUploads($validated['esx_files'], $dir, 'esx');
            $mappingPath = $workspace->storeUpload($validated['mapping_file'], $dir, 'mapping');
            $outputDir = $dir.'/output';
            mkdir($outputDir, 0755, true);

            $outcome = $service->rename(
                $esxPaths,
                $mappingPath,
                $sheetName,
                $macColumn,
                $nameColumn,
                $lowercase,
                $outputDir,
            );

            $token = $workspace->registerDownload(
                $dir,
                $outcome['output_paths'],
                $outcome['results'],
                'renamed-by-mac.zip',
            );

            return response()->json([
                'results' => $outcome['results'],
                'download_url' => route('ekahau.download', ['token' => $token]),
            ]);
        } catch (\Throwable $e) {
            $workspace->cleanup($dir);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function exportAps(
        Request $request,
        EkahauWorkspace $workspace,
        ExportEkahauApsService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'esx_files' => ['required', 'array', 'min:1'],
            'esx_files.*' => ['required', 'file', 'max:102400'],
            'prefix_mode' => ['nullable', Rule::in(['none', 'flat', 'template'])],
            'ap_name_prefix' => ['nullable', 'string', 'max:255'],
            'ap_name_prefix_template' => ['nullable', 'string', 'max:255'],
            'ap_name_prefix_custom' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated['esx_files'] as $esxFile) {
            $this->assertEsxUpload($esxFile);
        }

        [$template, $legacy, $custom] = $this->resolvePrefixOptions($validated);

        $dir = $workspace->create();
        try {
            $esxPaths = $workspace->storeUploads($validated['esx_files'], $dir, 'esx');
            $outputDir = $dir.'/output';
            mkdir($outputDir, 0755, true);

            $outcome = $service->export(
                $esxPaths,
                $outputDir,
                $template,
                $custom,
                $legacy,
            );

            $token = $workspace->registerDownload(
                $dir,
                $outcome['output_paths'],
                $outcome['results'],
                'ekahau-aps-export.zip',
            );

            return response()->json([
                'results' => $outcome['results'],
                'download_url' => route('ekahau.download', ['token' => $token]),
            ]);
        } catch (\Throwable $e) {
            $workspace->cleanup($dir);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function prefixAp(
        Request $request,
        EkahauWorkspace $workspace,
        PrefixEsxApService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'esx_files' => ['required', 'array', 'min:1'],
            'esx_files.*' => ['required', 'file', 'max:102400'],
            'prefix_mode' => ['required', Rule::in(['flat', 'template'])],
            'ap_name_prefix' => ['nullable', 'string', 'max:255'],
            'ap_name_prefix_template' => ['nullable', 'string', 'max:255'],
            'ap_name_prefix_custom' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated['esx_files'] as $esxFile) {
            $this->assertEsxUpload($esxFile);
        }

        [$template, $legacy, $custom] = $this->resolvePrefixOptions($validated);
        if ($template === '' && $legacy === '') {
            return response()->json(['message' => 'Provide a flat prefix or a prefix template.'], 422);
        }

        $dir = $workspace->create();
        try {
            $esxPaths = $workspace->storeUploads($validated['esx_files'], $dir, 'esx');
            $outputDir = $dir.'/output';
            mkdir($outputDir, 0755, true);

            $outcome = $service->prefix(
                $esxPaths,
                $outputDir,
                $template,
                $custom,
                $legacy,
            );

            $token = $workspace->registerDownload(
                $dir,
                $outcome['output_paths'],
                $outcome['results'],
                'prefixed-esx.zip',
            );

            return response()->json([
                'results' => $outcome['results'],
                'download_url' => route('ekahau.download', ['token' => $token]),
            ]);
        } catch (\Throwable $e) {
            $workspace->cleanup($dir);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function download(string $token, EkahauWorkspace $workspace): BinaryFileResponse|JsonResponse
    {
        $payload = $workspace->resolveDownload($token);
        if ($payload === null || ! is_file($payload['path'])) {
            return response()->json(['message' => 'Download expired or not found.'], 404);
        }

        app()->terminating(function () use ($workspace, $token): void {
            $workspace->forgetDownload($token);
        });

        return response()->download($payload['path'], $payload['filename']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function renameApByMacFromCentral(
        Request $request,
        EkahauWorkspace $workspace,
        RenameEsxApByMacService $service,
        DeviceCentralFilterBuilder $filterBuilder,
        array $validated,
        bool $lowercase,
    ): JsonResponse {
        $currentClient = $request->user()->currentClient();
        if (! $currentClient) {
            return response()->json([
                'message' => 'Please set current client to pull BSSIDs from Central.',
            ], 422);
        }

        $siteId = trim((string) ($validated['site_id'] ?? ''));
        $siteName = trim((string) ($validated['site_name'] ?? ''));

        $filter = $filterBuilder->build([
            'siteId' => $siteId,
            'siteName' => $siteName,
        ]);

        if ($filter === null) {
            return response()->json(['message' => 'A site ID or site name is required.'], 422);
        }

        $helper = new CentralAPIHelper($currentClient);
        $result = $helper->get_all_bssids(['filter' => $filter]);

        if (array_key_exists('error', $result)) {
            return response()->json(['message' => (string) $result['error']], 422);
        }

        $rows = [];
        foreach ($result as $item) {
            $rows[] = [
                'mac' => (string) ($item['bssid'] ?? ''),
                'name' => (string) ($item['deviceName'] ?? ''),
            ];
        }

        if ($rows === []) {
            return response()->json(['message' => 'No BSSIDs returned for the selected site.'], 422);
        }

        $dir = $workspace->create();
        try {
            $esxPaths = $workspace->storeUploads($validated['esx_files'], $dir, 'esx');
            $outputDir = $dir.'/output';
            mkdir($outputDir, 0755, true);

            $outcome = $service->renameFromMacNameRows(
                $esxPaths,
                $rows,
                $lowercase,
                $outputDir,
            );

            $token = $workspace->registerDownload(
                $dir,
                $outcome['output_paths'],
                $outcome['results'],
                'renamed-by-mac.zip',
            );

            return response()->json([
                'results' => $outcome['results'],
                'download_url' => route('ekahau.download', ['token' => $token]),
            ]);
        } catch (\Throwable $e) {
            $workspace->cleanup($dir);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolvePrefixOptions(array $validated): array
    {
        $mode = $validated['prefix_mode'] ?? 'none';
        $custom = (string) ($validated['ap_name_prefix_custom'] ?? '');

        return match ($mode) {
            'flat' => ['', (string) ($validated['ap_name_prefix'] ?? ''), $custom],
            'template' => [(string) ($validated['ap_name_prefix_template'] ?? ''), '', $custom],
            default => ['', '', $custom],
        };
    }

    private function assertEsxUpload(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'esx') {
            throw ValidationException::withMessages([
                'esx_file' => 'ESX uploads must use the .esx extension.',
            ]);
        }
    }
}
