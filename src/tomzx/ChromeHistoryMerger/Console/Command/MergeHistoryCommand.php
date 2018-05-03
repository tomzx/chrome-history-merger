<?php

namespace tomzx\ChromeHistoryMerger\Console\Command;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MergeHistoryCommand extends Command
{
    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $output;

    public function configure(): void
    {
        $this
            ->setName('merge-history')
            ->setDescription('Merge 2 or more chrome history files (sqlite) into a single file')
            ->setDefinition([
                new InputArgument('output', InputArgument::REQUIRED, 'The filename of the merged output file'),
                new InputArgument('files', InputArgument::REQUIRED|InputArgument::IS_ARRAY, 'A list of files to merge'),
            ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $outputFile = $input->getArgument('output');
        $inputFiles = $input->getArgument('files');

        $firstFile = $inputFiles[0];
        $restOfFiles = array_slice($inputFiles, 1);

        if ( ! $this->checkFileIsSqlite($firstFile)) {
            $this->output->writeln('<error>File ' . $firstFile . ' is not a valid SQLite file.</error>');
            return 1;
        }

        $this->output->writeln('Copying first file (' . $firstFile . ') to serve as initial output...');
        $this->copyFirstFileToOutput($outputFile, $firstFile);

        $outputDatabase = $this->createDatabaseConnection($outputFile);

        foreach ($restOfFiles as $file) {
            $this->output->writeln('Merging ' . $file . ' in output file...');

            if (!$this->checkFileIsSqlite($file)) {
                $this->output->writeln('<error>File ' . $file . ' is not a valid SQLite file, skipped.</error>');
                continue;
            }

            $inputDatabase = $this->createDatabaseConnection($file);

            $this->mergeDatabase($outputDatabase, $inputDatabase);
        }

        $this->output->writeln('Done.');

        return 0;
    }

    private function copyFirstFileToOutput(string $outputFile, string $inputFile): void
    {
        copy($inputFile, $outputFile);
    }

    private function mergeDatabase(Connection $outputDB, Connection $inputDB): void
    {
        $tables = [
            //'downloads', // there are some issues with the hash/blobs
            'downloads_url_chains',
            // 'keyword_search_terms', // does not have a PK
            // 'meta', // PK is not id
            'segment_usage',
            'segments',
            'urls',
            'visit_source',
            'visits',
        ];

        foreach ($tables as $tableName) {
            $this->output->writeln('Table ' . $tableName);
            $this->transferTableData($tableName, $inputDB, $outputDB);
        }
    }

    private function transferTableData(string $tableName, Connection $inputDB, Connection $outputDB): void
    {
        $lastInsertedId = $outputDB->table($tableName)
            ->select('id')
            ->orderBy('id', 'desc')
            ->first()['id'];

        $inputDB->table($tableName)->where('id', '>', $lastInsertedId)->orderBy('id')->chunk(100, function (Collection $data) use ($outputDB, $tableName) {
            $this->output->write('.');
            $outputDB->table($tableName)->insert($data->all());
        });

        $this->output->writeln('');
    }

    private function checkFileIsSqlite(string $file): bool
    {
        if (!file_exists($file)) {
            return false;
        }

        $connection = $this->createDatabaseConnection($file);

        try {
            $connection->statement('pragma integrity_check');
        } catch (QueryException $e) {
            return false;
        }

        return true;
    }

    private function createDatabaseConnection(string $file): Connection
    {
        $configuration = [
            'driver' => 'sqlite',
            'database' => $file,
        ];

        $capsule = new Capsule;
        $capsule->setEventDispatcher(new Dispatcher());
        $capsule->addConnection($configuration);
        $capsule->getEventDispatcher()->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(PDO::FETCH_ASSOC);
        });

        return $capsule->getConnection();
    }
}
