<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DB;
use App\Models\Project;

class ImportTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:tasks {filepath}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Tasks and projects from CSV';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('filepath');

        if(!file_exists($filePath)) {
            $this->error('File does not exist');
            return;
        }

        if(pathinfo($filePath, PATHINFO_EXTENSION) !== 'csv') {
            $this->error('File is not a CSV');
            return;
        }

        $handle = fopen($filePath, "r");

        if(!$handle) {
            $this->error('Unable to open file');
            return;
        }

        $header = fgetcsv($handle); // for skipping the headers

        $priorityMap = [
            'Low' => 1,
            'Medium' => 2,
            'High' => 3,
            'Critical' => 4
        ];

        $batchSize = 100;
        $tasksData = [];
        $count = 0;

        $projects = Project::pluck('id', 'name')->toArray();

        $this->info('Importing tasks...');
        $progressBar = $this->output->createProgressBar();
        $progressBar->start($batchSize);

        while(($row = fgetcsv($handle)) !== false) {

            $rowData = array_combine($header, $row);

            if(empty($rowData['project_name']) ||  empty($rowData['color']) || empty($rowData['task_title'])) {
                continue;
            }

            $projectName = trim($rowData['project_name']);
            $projectId = $projects[$projectName] ?? null;

            if (!$projectId) {
                $project = Project::where('name', $projectName)->first();

                if (!$project) {
                    $project = Project::create([
                        'name' => $projectName,
                        'color' => $rowData['color'],
                    ]);
                }

                $projectId = $project->id;

                if (count($projects) < 5000) {
                    $projects[$projectName] = $projectId;
                }
            }

            $tasksData[] = [
                'project' => $projectId,
                'name' => $rowData['task_title'],
                'priority' => $priorityMap[$rowData['task_priority']] ?? 1,
                'is_completed' => filter_var($rowData['is_complete'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $count++;

            if(count($tasksData) === $batchSize) {
                DB::table('tasks')->insert($tasksData);
                $tasksData = [];
                unset($tasksData);
            }

            $progressBar->advance();

        }

        // Insert remaining tasks
        if(!empty($tasksData)) {
            DB::table('tasks')->insert($tasksData);
        }

        fclose($handle);

        $progressBar->finish();
        $this->info('Projects and Tasks Imported successfully');
    }
}
