<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Searchable;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;

#[Signature('scout:import-all
            {--fresh : Flush each index before importing}
            {--queue : Import via queued chunk jobs (scout:queue-import)}
            {--skip-sync-settings : Do not sync index settings first}
            {--c|chunk= : The number of records to import at a time}')]
#[Description('Import all Eloquent models that use Laravel Scout into the search indexes')]
class ScoutImportAllCommand extends Command
{
    public function handle(EngineManager $engines): int
    {
        $models = $this->searchableModels();

        if ($models === []) {
            $this->warn('No searchable models found under app/Models.');

            return self::SUCCESS;
        }

        if (! $this->option('skip-sync-settings')) {
            $engine = $engines->engine();

            if ($engine instanceof UpdatesIndexSettings) {
                $this->components->info('Syncing search index settings…');
                $this->call('scout:sync-index-settings');
            }
        }

        $importCommand = $this->option('queue') ? 'scout:queue-import' : 'scout:import';

        foreach ($models as $model) {
            $this->components->info("Importing [{$model}]…");

            $parameters = [
                'model' => $model,
            ];

            if ($this->option('chunk') !== null) {
                $parameters['--chunk'] = $this->option('chunk');
            }

            if ($this->option('fresh')) {
                if ($this->option('queue')) {
                    $flushExitCode = $this->call('scout:flush', ['model' => $model]);

                    if ($flushExitCode !== self::SUCCESS) {
                        $this->components->error("Flush failed for [{$model}].");

                        return $flushExitCode;
                    }
                } else {
                    $parameters['--fresh'] = true;
                }
            }

            $exitCode = $this->call($importCommand, $parameters);

            if ($exitCode !== self::SUCCESS) {
                $this->components->error("Import failed for [{$model}].");

                return $exitCode;
            }
        }

        $this->components->info('Imported '.count($models).' searchable model(s).');

        return self::SUCCESS;
    }

    /**
     * @return list<class-string<Model>>
     */
    protected function searchableModels(): array
    {
        if (! File::isDirectory(app_path('Models'))) {
            return [];
        }

        return collect(File::allFiles(app_path('Models')))
            ->map(fn (SplFileInfo $file): string => $this->classFromModelFile($file))
            ->filter(fn (string $class): bool => $this->isSearchableModel($class))
            ->sort()
            ->values()
            ->all();
    }

    protected function classFromModelFile(SplFileInfo $file): string
    {
        $relative = str_replace(['/', '\\'], '\\', $file->getRelativePathname());

        return 'App\\Models\\'.str_replace('.php', '', $relative);
    }

    /**
     * @param  class-string  $class
     */
    protected function isSearchableModel(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return false;
        }

        return in_array(Searchable::class, class_uses_recursive($class), true);
    }
}
