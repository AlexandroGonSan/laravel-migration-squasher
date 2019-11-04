<?php
/**
 * Created by IntelliJ IDEA.
 * User: jbarber
 * Date: 10/31/13
 * Time: 9:46 AM
 */

namespace Stackout\Squasher;

use Stackout\Squasher\Database\Column;
use Stackout\Squasher\Database\Relationship;
use Stackout\Squasher\Database\Table;

class MigrationSquasher
{

    /**
     * The path to unsquashed migrations.
     *
     * @var string
     */
    protected $migrationPath;

    /**
     * The path to the generated migrations.
     *
     * @var string
     */
    protected $outputPath;

    /**
     * The path to move old migrations to.
     *
     * @var string
     */
    protected $moveToPath;

    /**
     * An array of strings containing the paths to each migration.
     *
     * @var array
     */
    protected $migrations;

    /**
     * An array of table objects.
     *
     * @var array
     */
    protected $tables;

    /**
     * Preps a new migration squasher.
     *
     * @param $pathToMigrations
     * @param $outputMigrations
     * @param $moveToPath
     */
    public function __construct($pathToMigrations, $outputMigrations, $moveToPath = null)
    {
        $this->migrationPath = trim(($pathToMigrations), '/') . '/';
        $this->outputPath = $this->setupFolder($outputMigrations);
        $this->moveToPath = $moveToPath == null ? null : $this->setupFolder($moveToPath);
        $this->migrations = scandir($this->migrationPath);
        $this->tables = [];
    }

    /**
     * Begin squashing all migrations in the migration path.
     */
    public function squash()
    {
        echo "Beginning migration squash\n";

        $this->parseMigrations();

        $sortedTableNames = $this->resolveTableDependencies();
        $date = date('Y_m_d');
        foreach ($sortedTableNames as $key => $table) {
            echo "Squashing $table\n";

            file_put_contents($this->outputPath . $date . '_' . str_pad($key, 6, '0', STR_PAD_LEFT) .
                "_squashed_" . $table .
                "_table.php", TableBuilder::build($this->tables[$table]));
        }

        echo "Squash complete!" . (trim($this->moveToPath, '/') === trim($this->migrationPath, '/') ? '' :
                " Old migrations have been moved to " . $this->moveToPath) . "\n";
        echo "New migrations are located in $this->outputPath\n";
    }

    /**
     * Begin parsing each file.
     */
    protected function parseMigrations()
    {
        foreach ($this->migrations as $migration) {
            if (!is_dir($migration)) {
                echo "Parsing migration $migration\n";
                if ($this->parseFile($migration) && $this->moveToPath !== null) {
                    rename($this->migrationPath . $migration, base_path($this->moveToPath . $migration));
                }
            }
        }
    }

    /**
     * Parse the given file.
     *
     * @param $filePath
     * @return bool true/false if the file was a migration
     */
    protected function parseFile($filePath)
    {
        $file = file_get_contents($this->migrationPath . $filePath);
        $file = str_replace("\n","",$file);
        $file = str_replace("{","{\n", $file);
        $file = str_replace(";",";\n", $file);
        $file = str_replace("}","}\n", $file);
        $fileLines = explode(PHP_EOL, file_get_contents($this->migrationPath . $filePath));
        return $this->parseLines($fileLines);
    }

    /**
     * Parse each string from the given array of strings
     *
     * @param $fileLines
     * @return bool true/false if the file was a migration
     */
    protected function parseLines($fileLines)
    {
        $table = null;
        $migration = false;
        foreach ($fileLines as $line) {
            if (preg_match('/public function down\(.*\)/', $line)) {
                break;
            }

            if (str_contains($line, "}")) {
                $table = null;
            }
            if ($this->lineContainsDbStatement($line) &&
                preg_match_all('/ALTER TABLE *`?([^` ]*)`? *(?>MODIFY|CHANGE) *COLUMN `?([^ `]*)`? *([^;( ]*)(\(([^)]*))?\)? *([^\';]*)/i',
                    $line, $matches1)
            ) {
                $this->createColumnFromDbStatement($matches1);
            }elseif(preg_match_all('/Schema::rename\((\'|")([^\'"]*)[^,]*,\s*(\'|")([^\'"]*)/', $line, $matches3)){
                $name = $matches3[2][0];
                $newName = $matches3[4][0];
                $this->tables[$newName] = $this->tables[$name];
                $this->tables[$newName]->name = $newName;
                unset($this->tables[$name]);
                $table = $this->tables[$newName];
            }
            elseif (preg_match('/Schema::(d|c|t|[^(]*\((\'|")(.*)(\'|"))*/', $line, $matches2)) {
                $table = $this->parseTable($matches2);
                $migration = true;
            }
            elseif ($table !== null) {
                $this->parseField($table, $line);
            }
        }
        return $migration;
    }

    /**
     * Pull the table out of the given regex matches.
     *
     * @param $matches
     * @return null|Table
     */
    protected function parseTable($matches)
    {
        preg_match('/(\'|").*(\'|")/', $matches[0], $tableMatch);
        $tableMatch = preg_replace("/'|\"/", "", $tableMatch[0]);

        if (str_contains($matches[0], '::drop')) {
            unset($this->tables[$tableMatch]);
            return null;
        }

        return isset($this->tables[$tableMatch]) ? $this->tables[$tableMatch] :
            $this->tables[$tableMatch] = new Table($tableMatch);
    }

    /**
     * Parse the given line and set the values in the given table.
     *
     * @param Table $table
     * @param $line
     */
    protected function parseField(Table $table, $line)
    {
        if (preg_match('/\$[^->]*->engine/', $line)) {
            $table->setEngine(preg_replace("/'|;| |\"/", "", explode("=", $line)[1]));
            return;
        }
        elseif ($matches = $this->lineContainsFunctionCall($line)) {
            $this->createMigrationFunctionCall($table, $line, $matches[0]);
        }
    }


    /**
     * Create the function call based on the column on the line.
     *
     * @param Table $table
     * @param $line
     * @param $matches
     */
    protected function createMigrationFunctionCall(Table $table, $line, $matches)
    {
        $line = str_replace('"', "'", $line);
        $segments = explode("'", $line);
        $matches[0] = preg_replace('/>| |,/', '', $matches[0]);
        switch ($matches[0]) {
            case 'primary' :
                $table->setPrimaryKey($segments[1]);
                break;
            case 'unique' :
                $table->getColumn($segments[1])->unique = true;
                break;
            case 'renameColumn':
                $table->renameColumn($segments[1], $segments[3]);
                break;
            case 'foreign':
                $table->addRelationship(new Relationship($segments[1], $segments[3], $segments[5]));
                break;
            case 'dropColumn':
            case 'dropIfExists' :
                $table->dropColumn($segments[1]);
                break;
            case 'dropForeign':
                $table->dropRelationship($segments[1]);
                break;
            case 'dropSoftDeletes' :
                $table->dropColumn('softDeletes');
                break;
            case 'dropTimestamps' :
                $table->dropColumn('timestamps');
                break;
            case 'timestamps' :
            case 'softDeletes' :
            case 'nullableTimestamps' :
            case 'rememberToken' :
                $segments[1] = $matches[0];
            case 'string' :
            case 'integer' :
            case 'increments' :
            case 'bigIncrements' :
            case 'bigInteger' :
            case 'smallInteger' :
            case 'float' :
            case 'double' :
            case 'decimal' :
            case 'boolean' :
            case 'date' :
            case 'dateTime' :
            case 'datetime' :
            case 'time' :
            case 'timestamp' :
            case 'text' :
            case 'binary' :
            case 'morphs' :
            case 'mediumText' :
            case 'longText' :
            case 'mediumInteger' :
            case 'tinyInteger' :
            case 'unsignedBigInteger' :
            case 'unsignedInteger' :
            case 'enum' :
                $table->addColumn($this->createStandardColumn($matches, $segments, $line));
                break;
            case 'index' :
            case 'dropUnique' :
                echo "ERROR, cannot handle " . $matches[0] . PHP_EOL;
                var_dump($segments);
                break;
            default:
                echo "Unknown table operation: " . $matches[0] . PHP_EOL;
                exit;
        }
        $matches = null;
    }

    /**
     * A generic function for creating a plain old column.
     *
     * @param $matches
     * @param $segments
     * @param $line
     * @return \Stackout\Squasher\Database\Column
     */
    protected function createStandardColumn($matches, $segments, $line)
    {
        $col = new Column($matches[0], isset($segments[1]) ? $segments[1] : null);
        foreach ($matches as $key => $match) {
            if ($key === 0) {
                continue;
            }
            if (str_contains($match, 'unsigned')) {
                $col->unsigned = true;
            }
            elseif (str_contains($match, 'unique')) {
                $col->unique = true;
            }
            elseif (str_contains($match, 'nullable')) {
                $col->nullable = true;
            }
            elseif (str_contains($match, 'default')) {
                preg_match_all('/default\(([^)]*)/', $line, $default);
                $col->default = $default[1][0];
            }
        }
        array_shift($segments);
        array_shift($segments);
        $segments = implode("'",$segments);
        if (isset($segments)) {
            $col->parameters =
                preg_match('/, *.*?\)(-|;)/', $segments, $lineSize) ?
                    trim(substr(preg_replace('/\)(-|;)/', '', $lineSize[0], 1),1),' ') :
                    null;
        }
        return $col;
    }

    /**
     * Return an array of function calls on the given line, or false if there are none.
     *
     * @param $line
     * @return array|bool
     */
    protected function lineContainsFunctionCall($line)
    {
        if (preg_match_all('/[^->]*>[^(]*/', $line, $match)) {
            return $match;
        }
        return false;
    }

    /**
     * Return an array of function calls on the given line, or false if there are none.
     *
     * @param $line
     * @return array|bool
     */
    protected function lineContainsDbStatement($line)
    {
        return str_contains($line, "::update");
    }

    /**
     * Create the given folder recursively, and return the correctly formatted folder path.
     *
     * @param $folder
     * @return string
     */
    protected function setupFolder($folder)
    {
        $folder = trim($folder, '/');
        if (!is_dir($folder)) {
            echo "Creating output folder $folder\n";
            mkdir($folder, 0777, true);
        }
        $folder .= '/';
        return $folder;
    }

    /**
     * Return an array that is the correct order that tables should be created.
     *
     * @return array
     */
    protected function resolveTableDependencies()
    {
        echo "Resolving foreign key relationships...\n";
        $sortedTables = [];
        $count = count($this->tables);
        while (count($sortedTables) !== $count) {
            foreach ($this->tables as $table) {
                if (in_array($table->name, $sortedTables)) {
                    continue;
                }

                $resolved = true;
                foreach ($table->getRelationships() as $relationship) {
                    if (!in_array($relationship->relationshipTable, $sortedTables)) {
                        echo "cannot resolve {$table->name}, depends on {$relationship->relationshipTable} \n";
                        if ($relationship->relationshipTable == $table->name) {
                            echo "Self dependency\n";
                        } else {
                            $resolved = false;
                            break;
                        }
                    }
                }
                if ($resolved) {
                    echo "resolved " . $table->name . PHP_EOL;
                    array_push($sortedTables, $table->name);
                }
            }
        }
        echo "Done!\n";
        return $sortedTables;
    }

    /**
     * @param $matches
     * @return mixed
     */
    protected function createColumnFromDbStatement($matches)
    {
        $table = $matches[1][0];
        $column = $matches[2][0];
        $type = $this->convertMySqlTypeToLaravelType(strtolower($matches[3][0]));
        $params = $matches[5][0];

        $attributes = strtolower($matches[6][0]);

        if ($this->tables[$table]->hasColumn($column)) {
            if (str_contains($attributes, 'auto_increment')) {
                if ($type === "bigInteger") {
                    $type = "bigIncrements";
                }
                elseif ($type === "integer") {
                    $type = "increments";
                }
            }

            $col = new Column($type, $column);
            $col->nullable = str_contains($attributes, "not null") ? false : true;

            switch ($type) {
                case 'string' :
                    $col->parameters = $params;
                    break;
                case 'double' :
                case 'decimal' :
                    $col->parameters = $params;
            }

            $this->tables[$table]->addColumn($col);
        }else{
            echo "\n======================================================================\nWARNING: You have a mysql query modifying a non-existent column.\n$column\n======================================================================\n";
        }

    }

    protected function convertMySqlTypeToLaravelType($type)
    {
        switch ($type) {
            case 'char' :
            case 'varchar' :
                return 'string';
            case 'bigint' :
            case 'biginteger':
                return 'bigInteger';
            case 'int':
                return 'integer';
            case 'smallint' :
            case 'smallinteger' :
                return 'smallInteger';
            case 'blob' :
                return 'binary';

        }
        return $type;
    }
}

