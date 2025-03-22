<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class CsvImportController extends Controller
{
    public function showImportForm()
    {
        $tables = DB::select('SHOW TABLES');
        $databaseName = DB::getDatabaseName();
        $tableKey = "Tables_in_" . $databaseName;
        
        $formattedTables = collect($tables)->map(function($table) use ($tableKey) {
            $tableName = $table->$tableKey;
            return [
                'name' => $tableName,
                'count' => DB::table($tableName)->count()
            ];
        });

        return view('csv.import', ['tables' => $formattedTables]);
    }

    public function analyzeFile(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt'
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getPathname(), 'r');
        $headers = fgetcsv($handle);
        
        // Read first data row to get sample values
        $sampleData = fgetcsv($handle);
        fclose($handle);

        $tables = DB::select('SHOW TABLES');
        $databaseName = DB::getDatabaseName();
        $tableKey = "Tables_in_" . $databaseName;
        
        $matchingScores = [];
        
        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            $columns = Schema::getColumnListing($tableName);
            
            // Remove common columns that shouldn't affect matching
            $columnsFiltered = array_diff($columns, ['id', 'created_at', 'updated_at', 'deleted_at']);
            $headersFiltered = array_map('strtolower', $headers);
            
            // Calculate exact matches
            $exactMatches = array_intersect($headersFiltered, $columnsFiltered);
            
            // Calculate similar matches using string similarity
            $similarMatches = [];
            foreach ($headersFiltered as $header) {
                foreach ($columnsFiltered as $column) {
                    $similarity = similar_text($header, $column, $percent);
                    if ($percent > 70 && !in_array($column, $exactMatches)) {
                        $similarMatches[$header] = $column;
                    }
                }
            }
            
            // Calculate scores
            $exactScore = count($exactMatches) * 2; // Exact matches worth 2 points
            $similarScore = count($similarMatches); // Similar matches worth 1 point
            $totalScore = ($exactScore + $similarScore) / (count($headersFiltered) * 2) * 100;
            
            if ($totalScore > 0) {
                $matchingScores[$tableName] = [
                    'score' => $totalScore,
                    'exact_matches' => $exactMatches,
                    'similar_matches' => $similarMatches,
                    'missing_columns' => array_diff($headersFiltered, array_merge($exactMatches, array_keys($similarMatches))),
                    'sample_mapping' => $this->generateSampleMapping($exactMatches, $similarMatches, $headers, $sampleData)
                ];
            }
        }

        // Sort by score descending
        arsort($matchingScores);

        return response()->json([
            'suggestions' => $matchingScores,
            'headers' => $headers,
            'sample_data' => $sampleData
        ]);
    }

    private function generateSampleMapping($exactMatches, $similarMatches, $headers, $sampleData)
    {
        $mapping = [];
        foreach ($headers as $index => $header) {
            $header = strtolower($header);
            $value = isset($sampleData[$index]) ? $sampleData[$index] : null;
            
            if (in_array($header, $exactMatches)) {
                $mapping[$header] = [
                    'type' => 'exact',
                    'maps_to' => $header,
                    'sample' => $value
                ];
            } elseif (isset($similarMatches[$header])) {
                $mapping[$header] = [
                    'type' => 'similar',
                    'maps_to' => $similarMatches[$header],
                    'sample' => $value
                ];
            } else {
                $mapping[$header] = [
                    'type' => 'missing',
                    'maps_to' => null,
                    'sample' => $value
                ];
            }
        }
        return $mapping;
    }

    public function import(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt',
            'table_name' => 'required|string'
        ]);

        $file = $request->file('csv_file');
        $tableName = $request->input('table_name');

        if (!Schema::hasTable($tableName)) {
            Session()->flash('flash_message_warning', 'Table not found');
            return redirect()->back();
        }

        $columns = Schema::getColumnListing($tableName);
        $handle = fopen($file->getPathname(), 'r');
        
        // Read header row
        $header = fgetcsv($handle);
        
        // Validate header columns against table columns
        $validColumns = array_intersect($header, $columns);
        
        if (empty($validColumns)) {
            fclose($handle);
            Session()->flash('flash_message_warning', 'No matching columns found in CSV file');
            return redirect()->back();
        }

        DB::beginTransaction();
        try {
            while (($data = fgetcsv($handle)) !== false) {
                $row = array_combine($header, $data);
                $insertData = array_intersect_key($row, array_flip($validColumns));
                
                DB::table($tableName)->insert($insertData);
            }
            
            DB::commit();
            fclose($handle);
            Session()->flash('flash_message', 'CSV imported successfully');
            return redirect()->back();
            
        } catch (\Exception $e) {
            DB::rollBack();
            fclose($handle);
            Session()->flash('flash_message_warning', 'Error importing CSV: ' . $e->getMessage());
            return redirect()->back();
        }
    }

    public function truncateAllTables()
    {
        try {
            $basePath = base_path();
            
            // Exécuter les commandes dans l'ordre
            $commands = [
                'php artisan migrate:fresh',
                'php artisan migrate --seed',
                'php artisan key:generate'
            ];

            foreach ($commands as $command) {
                $process = Process::fromShellCommandline($command, $basePath);
                $process->setTimeout(null);
                $process->run();

                if (!$process->isSuccessful()) {
                    throw new ProcessFailedException($process);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Database has been reset successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function truncateTable(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Individual table truncate is disabled. Please use "Reset Database" to reset the entire database.'
        ]);
    }
}
