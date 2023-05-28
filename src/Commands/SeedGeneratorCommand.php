<?php
namespace TYGHaykal\LaravelSeedGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use TYGHaykal\LaravelSeedGenerator\Helpers\StringHelper;

class SeedGeneratorCommand extends Command
{
    protected $signature = "seed:generate {model?} 
                                {--show-option} 
                                {--all-ids} 
                                {--all-fields} 
                                {--without-relations} 
                                {--where=* : The where clause conditions}
                                {--where-in=* : The where in clause conditions}
                                {--limit= : Limit data to be seeded} 
                                {--ids= : The ids to be seeded} 
                                {--ignore-ids= : The ids to be ignored} 
                                {--fields= : The fields to be seeded} 
                                {--ignore-fields= : The fields to be ignored} 
                                {--relations= : The relations to be seeded}
                                {--relations-limit= : Limit relation data to be seeded} ";
                                
    protected $description = "Generate a seed file from a model";
    private $oldLaravelVersion = false,
        $commands = [], $showOption = false ;
    public function __construct()
    {
        parent::__construct();
        $this->commands["main"] = "artisan seed:generate";
        $this->oldLaravelVersion = version_compare(app()->version(), "8.0.0") < 0;
    }
    
    public function handle(Filesystem $files)
    {
        try {
            $this->showOption = $this->option("show-option");

            $model = $this->checkModelInput("model");
            $modelInstance = app($model);

            $where = $this->checkWhereInput();
            $whereIn = $this->checkWhereInInput();
            $limit = $this->checkLimit();
            list($selectedIds, $ignoreIds) = $this->checkIdsInput();
            list($selectedFields, $ignoreFields) = $this->checkFieldsInput();
            $relations = $this->checkRelationInput();
            $relationsLimit = $this->checkRelationLimit();

            $seederCommands = $this->getSeederCode(
                $modelInstance,
                $selectedIds,
                $ignoreIds,
                $selectedFields,
                $ignoreFields,
                $relations,
                $where,
                $whereIn,
                $limit,
                $relationsLimit
            );

            $this->writeSeederFile($files, $seederCommands, $modelInstance);
        } catch (\Exception $e) {
            dd($e);
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function checkModelInput(): string
    {
        $model = $this->argument("model");
        if (!$model) {
            $model = $this->anticipate("Please provide a model name", []);
        }
        $this->commands["main"] .= " " . $model;
        return $this->checkModel($model);
    }

    private function checkModel(string $model): string
    {
        $modelPath = "\\App\\Models\\{$model}";
        if (class_exists($modelPath)) {
            return "\\App\\Models\\$model";
        } else {
            $modelPath = "\\App\\{$model}";
            if (class_exists($modelPath)) {
                return "\\App\\$model";
            }
        }
        throw new \Exception("Model file not found at under \App\Models or \App");
    }

    private function checkLimit(){
        $limit = $this->option("limit");
        if ($limit == null && $this->showOption) {
            $limit = $this->ask("Please provide the limit of data to be seeded");
        }
        if ($limit != null) {
            $this->commands["limit"] = "--limit={$limit}";
        }
        return $limit;
    }

    private function checkRelationLimit(){
        $limit = $this->option("relations-limit");
        if ($limit == null && $this->showOption) {
            $limit = $this->ask("Please provide the limit of relation data to be seeded");
        }
        if ($limit != null) {
            $this->commands["relations-limit"] = "--relations-limit={$limit}";
        }

        return $limit;
    }



    private function checkWhereInput(){
        $wheres = $this->option("where");
        $this->commands["where"] = "";
        if (count($wheres) == 0 && $this->showOption) {
            $wheres = $this->ask("Please provide the where clause conditions (seperate with comma for column and value)");
        }
        if ($wheres != null) {
            foreach($wheres as $where){
                $this->commands["where"] .= "--where={$where} ";
            }
        }
        $wheresFinal = [];
        foreach($wheres as $key=>$where){
            $result = $this->optionToArray($where);
            if(count($result) != 2){
                throw new \Exception("You must provide 2 values for where clause");
            }
            $wheresFinal[$key]["column"] = $result[0];
            $wheresFinal[$key]["value"] = $result[1];
        }
        if(count($wheresFinal) == 0){
            unset($this->commands["where"]);
        }
        return $wheresFinal;
    }

    private function checkWhereInInput(){
        $whereIns = $this->option("where-in");
        $this->commands["where-in"] = "";
        if (count($whereIns) == 0 && $this->showOption) {
            $whereIns = $this->ask("Please provide the where clause conditions (seperate with comma for column and value)");
        }
        if ($whereIns != null) {
            foreach($whereIns as $whereIn){
                $this->commands["where-in"] .= "--where-in={$whereIn} ";
            }
        }
        $whereInsFinal = [];
        foreach($whereIns as $key=>$where){
            $result = $this->optionToArray($where);
            if(count($result) < 2){
                throw new \Exception("You must provide atleast 2 values for where in clause");
            }
            $whereInsFinal[$key]["column"] = $result[0];
            unset($result[0]);
            $whereInsFinal[$key]["value"] = $result;
        }
        if(count($whereInsFinal) == 0){
            unset($this->commands["where-in"]);
        }
        return $whereInsFinal;
    }

    private function checkIdsInput(): array
    {
        if ($this->option('all-ids')) {
            $this->commands["ids"] = "--all-ids";
            return [[], []];
        }
        $selectedIds = $this->option("ids");
        $ignoredIds = $this->option("ignore-ids");
        if ($selectedIds == null && $ignoredIds == null && $this->showOption) {
            $typeOfIds = $this->choice("Do you want to select or ignore ids?", [
                1 => "Select all",
                2 => "Select some ids",
                3 => "Ignore some ids",
            ]);
            switch ($typeOfIds) {
                case "Select some ids":
                    $selectedIds = $this->ask("Please provide the ids you want to select (seperate with comma)");
                    break;
                case "Ignore some ids":
                    $ignoredIds = $this->ask("Please provide the ids you want to ignore (seperate with comma)");
                    break;
            }
        }
        if ($selectedIds != null) {
            $this->commands["ids"] = "--ids={$selectedIds}";
        }
        if ($ignoredIds != null) {
            $this->commands["ids"] = "--ignore-ids={$ignoredIds}";
        }
        $selectedIds = $this->optionToArray($selectedIds);
        $ignoredIds = $this->optionToArray($ignoredIds);
        if (count($selectedIds) > 0 && count($ignoredIds) > 0) {
            throw new \Exception("You can't use --ignore-ids and --ids at the same time.");
        }
        return [$selectedIds, $ignoredIds];
    }

    private function checkFieldsInput(): array
    {
        if ($this->option('all-fields')) {
            $this->commands["fields"] = "--all-fields";
            return [[], []];
        }
        $selectedFields = $this->option("fields");
        $ignoredFields = $this->option("ignore-fields");
        if ($selectedFields == null && $ignoredFields == null && $this->showOption) {
            $typeOfFields = $this->choice("Do you want to select or ignore fields?", [
                1 => "Select all",
                2 => "Select some fields",
                3 => "Ignore some fields",
            ]);
            switch ($typeOfFields) {
                case "Select some fields":
                    $selectedFields = $this->ask("Please provide the fields you want to select (seperate with comma)");
                    break;
                case "Ignore some fields":
                    $ignoredFields = $this->ask("Please provide the fields you want to ignore (seperate with comma)");
                    break;
            }
        }
        if ($ignoredFields != null) {
            $this->commands["fields"] = "--ignore-fields={$ignoredFields}";
        }
        if ($selectedFields != null) {
            $this->commands["fields"] = "--fields={$selectedFields}";
        }
        $selectedFields = $this->optionToArray($selectedFields);
        $ignoredFields = $this->optionToArray($ignoredFields);
        if (count($selectedFields) > 0 && count($ignoredFields) > 0) {
            throw new \Exception("You can't use --ignore-fields and --fields at the same time.");
        }
        return [$selectedFields, $ignoredFields];
    }

    private function checkRelationInput(): array
    {
        if (!$this->option("without-relations")) {
            $relations = $this->option("relations");
            if ($relations == null && $this->showOption) {
                $typeOfRelation = $this->choice("Do you want to seed the has-many relation?", [1 => "No", 2 => "Yes"]);
                switch ($typeOfRelation) {
                    case "Yes":
                        $relations = $this->ask("Please provide the has-many relations you want to seed (seperate with comma)");
                        break;
                    default:
                        $relations = "";
                        break;
                }
            }
            if ($relations != null) {
                $this->commands["relation"] = "--relations={$relations}";
            }
            $relations = $this->optionToArray($relations);
            return $relations;
        }
        return [];
    }

    private function optionToArray(?string $ids): array
    {
        if (!$ids) {
            return [];
        }
        $ids = explode(",", $ids);
        return $ids;
    }

    private function getSeederCode(
        Model $modelInstance,
        array $selectedIds,
        array $ignoreIds,
        array $selectedFields,
        array $ignoreFields,
        array $relations,
        array $where,
        array $whereIn,
        ?int $limit,
        ?int $relationsLimit
    ): string {
        $modelInstance = $modelInstance->newQuery();
        if (count($selectedIds) > 0) {
            $modelInstance = $modelInstance->whereIn("id", $selectedIds);
        } elseif (count($ignoreIds) > 0) {
            $modelInstance = $modelInstance->whereNotIn("id", $ignoreIds);
        }

        if(count($where) > 0){
            foreach($where as $whereData){
                $modelInstance = $modelInstance->where($whereData["column"], $whereData["value"]);
            }
        }

        if(count($whereIn) > 0){
            foreach($whereIn as $whereInData){
                $modelInstance = $modelInstance->whereIn($whereInData["column"], $whereInData["value"]);
            }
        }
        
        if ($limit != null) {
            $modelInstance = $modelInstance->limit($limit);
        }
        $modelDatas = $modelInstance->get();
        
        if ($relationsLimit != null) {
            foreach ($relations as $relation) {
                $modelDatas->load([$relation => function ($query) use ($relationsLimit) {
                    $query->limit($relationsLimit);
                }]);
            }
        } else {
            $modelDatas->load($relations);
        }

        $codes = [];
        foreach ($modelDatas as $key => $data) {
            $data->makeHidden($relations);
            $dataArray = $data->toArray() ?? [];
            if (count($selectedFields) > 0) {
                //remove all fields except the selected fields
                $dataArray = array_intersect_key($dataArray, array_flip($selectedFields));
            }
            if (count($ignoreFields) > 0) {
                //return all fields except the ignored fields
                $dataArray = array_diff_key($dataArray, array_flip($ignoreFields));
            }
            $dataArray = StringHelper::prettyPrintArray($dataArray, 3);

            $code = "\$newData$key = \\" . get_class($modelInstance->getModel()) . "::create(" . $dataArray . ");";

            if ($key != 0) {
                $code = StringHelper::generateIndentation($code, 2);
            }
            foreach ($relations as $relation) {
                $relationData = $data->$relation;
                //get the has many relation only
                if ($data->$relation() instanceof \Illuminate\Database\Eloquent\Relations\HasMany) {
                    if ($relationData->count() > 0) {
                        $relationSubDatas = $relationData->toArray();
                        $relationCode = "";
                        foreach ($relationSubDatas as $subRelationKey => $relationSubData) {
                            $relationSubData = StringHelper::prettyPrintArray($relationSubData, 4);
                            if ($subRelationKey > 0) {
                                $relationSubData = StringHelper::generateIndentation($relationSubData, 4);
                            } else {
                                $relationSubData = StringHelper::generateIndentation($relationSubData, 3);
                            }
                            $relationCode .= ($subRelationKey > -1 ? "\n" : "") . $relationSubData . ",";
                        }
                        // Remove trailing comma
                        $relationCode = rtrim($relationCode, ",]") . "]";
                        $relationCode = "\$newData$key->$relation()->createMany([" . $relationCode;
                        $relationCode = "\n" . StringHelper::generateIndentation($relationCode, 2);
                        $relationCode .= "\n" . StringHelper::generateIndentation("]);", 2);
                        $code .= $relationCode;

                        // $code = StringHelper::generateIndentation($code, 1);
                    }
                } else {
                    throw new \Exception("The relation {$relation} is not a has-many relation");
                }
            }
            $codes[] = $code;
        }
        $code = implode("\n", $codes);
        //remove tab from $code
        $code = str_replace("\t", "", $code);
        $code = str_replace("\r", "", $code);
        return $code;
    }

    private function getCommands(): string
    {
        if($this->showOption){
            $this->commands["show_option"] = "--show-option";
        }
        return implode(" ", $this->commands);
    }

    private function writeSeederFile(Filesystem $files, string $code, Model $modelInstance): void
    {
        $isReplace = false;

        //set seed class name
        $seedClassName = class_basename($modelInstance);
        $seedClassName = Str::studly($seedClassName) . "Seeder";
        //set seed namespace
        $seedNamespace = new \ReflectionClass($modelInstance);
        $seedNamespace = $seedNamespace->getNamespaceName();
        $seedNamespace = str_replace("App\\Models", "", $seedNamespace);

        $command = $this->getCommands();

        if (!$this->oldLaravelVersion) {
            $dirSeed = "seeders";
            $stubContent = $files->get(__DIR__ . "/../Stubs/SeedAfter8.stub");
            $fileContent = str_replace(
                ["{{ namespace }}", "{{ class }}", "{{ command }}", "{{ code }}"],
                [$seedNamespace, $seedClassName, $command, $code],
                $stubContent
            );
        } else {
            $dirSeed = "seeds";
            $stubContent = $files->get(__DIR__ . "/../Stubs/SeedBefore8.stub");
            $fileContent = str_replace(
                ["{{ class }}", "{{ command }}", "{{ code }}"],
                [$seedClassName, $command, $code],
                $stubContent
            );
        }

        $dirSeed .= $seedNamespace ? $seedNamespace : "";

        //check if seed directory exists
        if (!$files->exists(database_path($dirSeed))) {
            $files->makeDirectory(database_path($dirSeed));
        }

        //get $modelInstance namespace
        $filePath = database_path("{$dirSeed}" . ("/" . $seedClassName) . ".php");
        if ($files->exists($filePath)) {
            $isReplace = true;
            $files->delete($filePath);
        }
        $files->put($filePath, $fileContent);

        $this->info(($isReplace ? "Seed file replaced" : "Seed file created") . " : {$filePath}");
    }
}
